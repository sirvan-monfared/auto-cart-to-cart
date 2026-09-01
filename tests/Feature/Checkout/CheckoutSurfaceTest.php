<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §FR-8 Hosted checkout surface — page, polling, customer report
|--------------------------------------------------------------------------
|
| The page renders Persian/RTL branded content with the DECRYPTED card number
| and never caches; polling drives budgeted maintenance so expiry happens
| without cron; the report form enforces CSRF, dedupes reviews, validates
| uploads by sniffed MIME, and refuses to touch terminal payments.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'token_digits' => 3,
        'payment_expiration_minutes' => 30,
    ]);

    $this->payment = Payment::query()->create([
        'public_id' => 'PAY'.Str::ulid(),
        'application_id' => $this->merchant->id,
        'bank_card_id' => $this->card->id,
        'driver' => 'card_transfer',
        'original_amount' => 100_000,
        'token' => 417,
        'payable_amount' => 100_417,
        'currency' => 'IRR',
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
        'customer_mobile' => '09120000001',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('checkout page GET /p/{public_id}', function () {
    it('renders Persian RTL content with the decrypted card number', function () {
        $response = $this->get('/p/'.$this->payment->public_id);

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('dir="rtl"', false)
            // The amount renders grouped (100,417) for legibility.
            ->assertSee(number_format($this->payment->payable_amount))
            ->assertSee($this->card->card_holder_name);

        // The FULL decrypted card number is rendered (that is the point of the
        // page); the encrypted ciphertext and secret fingerprint are not.
        expect($response->getContent())->toContain($this->card->card_number_encrypted)
            ->not->toContain($this->card->getAttributes()['card_number_encrypted']);
    });

    it('404s for an unknown public id', function () {
        $this->get('/p/PAYdoesnotexist')->assertStatus(404);
    });
});

describe('polling GET /api/v1/public/payments/{id}/status', function () {
    it('returns the status envelope for a pending payment', function () {
        $this->getJson('/api/v1/public/payments/'.$this->payment->public_id.'/status')
            ->assertStatus(200)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.payment_id', $this->payment->public_id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payable_amount', 100_417);
    });

    it('drives expiry without cron: an overdue pending payment reads back expired AND is persisted expired', function () {
        $this->payment->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->getJson('/api/v1/public/payments/'.$this->payment->public_id.'/status')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'expired');

        // The lazy maintenance slice actually transitioned the row (§FR-15).
        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Expired);
    });

    it('404s for an unknown payment id', function () {
        $this->getJson('/api/v1/public/payments/PAYnope/status')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'payment_not_found');
    });

    it('rate limits per IP+payment (60/min default)', function () {
        config()->set('cardpay.rate_limits.public_status', 2);
        $url = '/api/v1/public/payments/'.$this->payment->public_id.'/status';

        $this->getJson($url)->assertStatus(200);
        $this->getJson($url)->assertStatus(200);
        $this->getJson($url)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit_exceeded');
    });
});

describe('customer report POST /p/{public_id}/manual-review', function () {
    it('creates a review, moves pending → manual_review, and emits payment.manual_review', function () {
        $response = $this->from('/p/'.$this->payment->public_id)
            ->post('/p/'.$this->payment->public_id.'/manual-review', [
                'reported_amount' => 100_417,
                'customer_note' => 'Transfer sent 10 minutes ago.',
            ]);

        $response->assertRedirect('/p/'.$this->payment->public_id.'?review=submitted');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::ManualReview);

        $review = ManualReviewRequest::query()->sole();
        expect($review->reported_amount)->toBe(100_417)
            ->and($review->contact_mobile)->toBe('09120000001') // defaults from payment
            ->and(WebhookEvent::query()->where('event_type', 'payment.manual_review')->count())->toBe(1);
    });

    it('dedupes: a second report updates the SAME pending review instead of creating another', function () {
        foreach ([1, 2] as $i) {
            $this->post('/p/'.$this->payment->public_id.'/manual-review', [
                'reported_amount' => 100_417,
                'customer_note' => "Attempt {$i}.",
            ])->assertRedirect();
        }

        expect(ManualReviewRequest::query()->count())->toBe(1);
    });

    it('accepts an expired payment report (expired → manual_review)', function () {
        $this->payment->forceFill(['status' => PaymentStatus::Expired])->save();

        $this->post('/p/'.$this->payment->public_id.'/manual-review', [
            'reported_amount' => 100_417,
        ])->assertRedirect('/p/'.$this->payment->public_id.'?review=submitted');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::ManualReview);
    });

    it('refuses a paid (terminal) payment with payment_not_reviewable', function () {
        $this->payment->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ])->save();

        $this->post('/p/'.$this->payment->public_id.'/manual-review', [
            'reported_amount' => 100_417,
        ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(ManualReviewRequest::query()->count())->toBe(0);
    });

    it('rejects a non-positive reported_amount via validation redirect', function () {
        $this->post('/p/'.$this->payment->public_id.'/manual-review', [
            'reported_amount' => -5,
        ])->assertRedirect();

        expect(ManualReviewRequest::query()->count())->toBe(0);
    });

    it('interprets approximate_paid_at in the settings timezone and stores UTC', function () {
        Setting::put('timezone', 'Asia/Tehran', 'string');
        // 14:30 Tehran == 11:00 UTC.
        $this->post('/p/'.$this->payment->public_id.'/manual-review', [
            'approximate_paid_at' => '2026-08-25T14:30:00',
        ])->assertRedirect();

        $stored = ManualReviewRequest::query()->sole()->approximate_paid_at;
        expect($stored->format('H:i'))->toBe('11:00')
            ->and($stored->utc()->format('H:i'))->toBe('11:00');
    });

    it('stores a valid PDF receipt privately under an unguessable name', function () {
        Storage::fake('local');

        $this->post('/p/'.$this->payment->public_id.'/manual-review', [
            'receipt' => UploadedFile::fake()->createWithContent(
                'receipt.pdf',
                "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF",
            ),
        ])->assertRedirect();

        $path = ManualReviewRequest::query()->sole()->receipt_path;
        expect($path)->toStartWith('receipts/')
            ->and(strlen(basename((string) $path)))->toBe(40) // 20 random bytes hex
            ->and(pathinfo((string) $path, PATHINFO_EXTENSION))->toBe(''); // extension-less
        Storage::disk('local')->assertExists((string) $path);
    });

    it('rejects a disallowed file type even when the client claims an allowed extension', function () {
        Storage::fake('local');

        $this->post('/p/'.$this->payment->public_id.'/manual-review', [
            'receipt' => UploadedFile::fake()->createWithContent(
                'evil.pdf',
                '<html><body>not a pdf at all</body></html>',
            ),
        ])->assertRedirect();

        expect(ManualReviewRequest::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(0);
    });
});
