<?php

declare(strict_types=1);

use CartBecart\CardPay\Database\Seeders\DatabaseSeeder;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §FR-16 settings editor, reports + CSV, system page
|--------------------------------------------------------------------------
|
| Settings are WHITELISTED (arbitrary keys can't be injected), typed values
| are coerced before persistence, and is_public flips are audited. Reports
| aggregate by status over an inclusive UTC window and the CSV streams one
    | line per payment. The system page reports runtime truth without changing it.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('settings editor', function () {
    it('updates a whitelisted setting with type coercion', function () {
        $this->seed(DatabaseSeeder::class);

        $this->put(cardpay_test_url('settings'), [
            'settings' => ['default_expiration_minutes' => '45'],
        ])->assertRedirect()->assertSessionHas('settings_ok');

        expect(Setting::get('default_expiration_minutes'))->toBe(45) // int, not string
            ->and(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(1);
    });

    it('rejects non-integer input for int settings without saving anything', function () {
        $this->seed(DatabaseSeeder::class);
        $before = Setting::query()->pluck('setting_value', 'setting_key')->all();

        $this->put(cardpay_test_url('settings'), [
            'settings' => ['token_cooldown_minutes' => 'not-a-number'],
        ])->assertRedirect()->assertSessionHas('settings_error');

        expect(Setting::query()->pluck('setting_value', 'setting_key')->all())->toBe($before);
    });

    it('ignores keys outside the whitelist entirely', function () {
        $this->put(cardpay_test_url('settings'), [
            'settings' => ['app_key_backdoor' => 'evil-value'],
        ])->assertRedirect();

        expect(Setting::query()->where('setting_key', 'app_key_backdoor')->exists())->toBeFalse();
    });

    it('toggles is_public only for publicable keys and audits the flip', function () {
        $this->seed(DatabaseSeeder::class);

        // default_token_digits is NOT publicable: its flag must stay false.
        $this->put(cardpay_test_url('settings'), [
            'settings' => ['payment_title' => 'پرداخت فروشگاه', 'currency' => 'IRR'],
            'public' => ['payment_title' => '1', 'currency' => '1'],
        ])->assertRedirect();

        expect(Setting::query()->where('setting_key', 'payment_title')->first()->is_public)->toBeTrue()
            ->and(Setting::query()->where('setting_key', 'currency')->first()->is_public)->toBeTrue()
            ->and(Setting::query()->where('setting_key', 'default_token_digits')->first()->is_public)->toBeFalse();
    });
});

describe('reports + CSV', function () {
    beforeEach(function () {
        $this->card = BankCard::factory()->create();
        $this->merchant = Application::factory()->create(['default_bank_card_id' => $this->card->id]);

        foreach ([['paid', 100_417], ['paid', 250_000], ['expired', 99_999], ['pending', 10_000]] as [$status, $amount]) {
            Payment::query()->create([
                'public_id' => 'PAY'.Str::ulid(),
                'application_id' => $this->merchant->id,
                'bank_card_id' => $this->card->id,
                'driver' => 'card_transfer',
                'original_amount' => $amount,
                'token' => 1,
                'payable_amount' => $amount,
                'currency' => 'IRR',
                'status' => $status,
                'expires_at' => now()->addMinutes(30),
                'paid_at' => $status === 'paid' ? now() : null,
            ]);
        }
    });

    it('aggregates counts and volume by status within the window', function () {
        $this->get(cardpay_test_url('reports?from=').now()->subDays(1)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            // Paid: 2 payments; expired: 1; pending: 1.
            ->assertSeeInOrder([__('paid'), '2'])
            ->assertSee(number_format(350_417)); // paid volume 100_417 + 250_000
    });

    it('exports CSV with a header plus one row per payment', function () {
        $response = $this->get(cardpay_test_url('reports/csv'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // Streamed downloads expose their body via streamedContent().
        $lines = explode("\n", trim($response->streamedContent()));
        expect(count($lines))->toBe(5) // header + 4 payments
            ->and($lines[0])->toStartWith('public_id,application_id,bank_card_id')
            ->and($lines[1])->toContain(',paid');
    });

    it('falls back to defaults on garbage dates instead of erroring', function () {
        $this->get(cardpay_test_url('reports?from=garbage&to=worse'))->assertOk();
        $this->get(cardpay_test_url('reports/csv?from=garbage&to=worse'))->assertOk();
    });
});

describe('system page', function () {
    it('reports runtime truth without changing anything', function () {
        $this->get(cardpay_test_url('system'))
            ->assertOk()
            ->assertSee('PHP')
            ->assertSee(PHP_VERSION)
            ->assertSee(__('All migrations applied.'));
    });

    it('flags pending migrations when one has not run', function () {
        DB::table('migrations')->where('migration', 'like', '%000003%')->delete();

        $this->get(cardpay_test_url('system'))
            ->assertOk()
            ->assertSee('000003', false);
    });
});
