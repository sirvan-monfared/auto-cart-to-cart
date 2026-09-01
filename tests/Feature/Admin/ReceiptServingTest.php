<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §FR-12 / §SR-9 — receipt access
|--------------------------------------------------------------------------
|
| Receipts live on the private disk; the ONLY route to them is the admin-gated
| download endpoint. Downloads are audited, content-sniffed against the
| allow-list before serving, and stored paths are never trusted blindly.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
    Storage::fake('local');

    $this->actingAs(User::factory()->create());

    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create(['default_bank_card_id' => $this->card->id]);
    $this->payment = Payment::query()->create([
        'public_id' => 'PAY'.Str::ulid(),
        'application_id' => $this->merchant->id,
        'bank_card_id' => $this->card->id,
        'driver' => 'card_transfer',
        'original_amount' => 100_000,
        'token' => 417,
        'payable_amount' => 100_417,
        'currency' => 'IRR',
        'status' => 'manual_review',
        'expires_at' => now()->addMinutes(30),
    ]);
    $this->review = ManualReviewRequest::query()->create([
        'payment_id' => $this->payment->id,
        'reported_amount' => 100_417,
        'status' => 'pending',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('serves an uploaded receipt with correct type and a safe filename', function () {
    // Upload through the real customer flow.
    $this->post('/p/'.$this->payment->public_id.'/manual-review', [
        'receipt' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\ntrailer<<>>\n%%EOF"),
    ])->assertRedirect();

    $response = $this->get('/admin/reviews/'.$this->review->id.'/receipt');

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))
        ->toContain("receipt-{$this->review->id}.pdf")
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');

    // Download is audited (§SR-14).
    expect(AuditLog::query()->where('action', 'receipt.downloaded')->count())->toBe(1);
});

it('404s when the review has no receipt or the file is gone', function () {
    $this->get('/admin/reviews/'.$this->review->id.'/receipt')->assertStatus(404);

    // Attach a path pointing at a file that does not exist.
    $this->review->forceFill(['receipt_path' => 'receipts/ghost'.bin2hex(random_bytes(10))])->save();
    $this->get('/admin/reviews/'.$this->review->id.'/receipt')->assertStatus(404);
});

it('refuses stored paths that escape the receipts directory', function () {
    foreach (['../.env', '/absolute/path', 'logs/laravel.log'] as $bad) {
        $this->review->forceFill(['receipt_path' => $bad])->save();
        $this->get('/admin/reviews/'.$this->review->id.'/receipt')->assertStatus(404);
    }

    expect(AuditLog::query()->where('action', 'receipt.downloaded')->count())->toBe(0);
});

it('refuses to serve files whose sniffed content is not on the allow-list', function () {
    // A file whose bytes are NOT an allowed type, planted at a valid path.
    Storage::disk('local')->put(
        'receipts/'.bin2hex(random_bytes(20)),
        '<html>not allowed</html>',
    );
    $planted = collect(Storage::disk('local')->allFiles('receipts'))->first();
    $this->review->forceFill(['receipt_path' => $planted])->save();

    $this->get('/admin/reviews/'.$this->review->id.'/receipt')->assertStatus(404);
});

it('blocks guests from receipt downloads', function () {
    auth()->logout();

    $this->get('/admin/reviews/1/receipt')->assertRedirect(route('login'));
});
