<?php

namespace CartBecart\CardPay\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * CardPay's idempotent seed chain (§FR-1): safe to run repeatedly —
     * existing settings/parsers/applications are never overwritten, and the
     * default application's API key is only minted once.
     */
    public function run(): void
    {
        $this->call([
            DefaultSettingsSeeder::class,
            SamanParserSeeder::class,
            DefaultApplicationSeeder::class,
        ]);
    }
}
