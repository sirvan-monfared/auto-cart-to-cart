<?php

namespace CartBecart\CardPay\Database\Seeders;

use CartBecart\CardPay\Models\SmsParser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The Saman Bank deposit parser preset (§FR-6 / Appendix B). Idempotent by
 * bank_name+name so re-runs never duplicate the preset.
 */
class SamanParserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        SmsParser::query()->firstOrCreate(
            ['bank_name' => 'Saman', 'name' => 'Saman Bank deposit'],
            [
                'sender_pattern' => null,
                'amount_regex' => '/واریز\s+مبلغ\s+(?<amount>[0-9۰-۹,٬ ]+)\s*ریال/u',
                'date_regex' => '/(?<date>[0-9۰-۹]{4}\/[0-9۰-۹]{1,2}\/[0-9۰-۹]{1,2})/u',
                'time_regex' => '/(?<time>[0-9۰-۹]{1,2}:[0-9۰-۹]{2})/u',
                'transaction_type_regex' => '/(?<type>واریز|برداشت)/u',
                'positive_keywords' => ['واریز'],
                'negative_keywords' => ['برداشت'],
                'sample_sms' => "بانك سامان\nواريز مبلغ  1,000,000ريال\nبه 9001-800-2156834-1\nمانده 1,397,604\n1405/5/13\n1:57",
                'is_active' => true,
            ],
        );
    }
}
