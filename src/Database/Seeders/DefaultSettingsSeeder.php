<?php

namespace CartBecart\CardPay\Database\Seeders;

use CartBecart\CardPay\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Default typed settings (§14.2 / installer step 4). Idempotent: existing keys
 * are left untouched so admin customizations survive re-runs.
 */
class DefaultSettingsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $defaults = [
            // key => [value, type, isPublic]
            'app_name' => ['CardPay', 'string', false],
            'currency' => ['IRR', 'string', true],
            'timezone' => ['Asia/Tehran', 'string', true],
            'locale' => ['fa', 'string', true],
            'primary_color' => ['#155EEF', 'string', true],
            'accent_color' => ['#12B76A', 'string', true],
            'payment_title' => ['پرداخت امن کارت به کارت', 'string', true],
            'payment_help' => [
                'مبلغ دقیق نشان داده شده را کارت به کارت کنید؛ همان رقم به‌صورت خودکار تأیید می‌شود. مبلغ را تغییر ندهید.',
                'string',
                true,
            ],
            'success_text' => ['پرداخت شما با موفقیت تأیید شد.', 'string', true],
            'expired_text' => ['مهلت این پرداخت به پایان رسیده است.', 'string', true],
            'default_token_digits' => [3, 'int', false],
            'default_expiration_minutes' => [30, 'int', false],
            'token_cooldown_minutes' => [10, 'int', false],
        ];

        foreach ($defaults as $key => [$value, $type, $isPublic]) {
            Setting::query()->firstOrCreate(
                ['setting_key' => $key],
                [
                    'setting_value' => (string) $value,
                    'value_type' => $type,
                    'is_public' => $isPublic,
                ],
            );
        }
    }
}
