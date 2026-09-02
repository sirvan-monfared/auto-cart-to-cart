<?php

namespace CartBecart\CardPay\Database\Seeders;

use CartBecart\CardPay\Support\Edition;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * CardPay's idempotent seed chain (§FR-1): safe to run repeatedly —
     * existing settings/parsers/applications are never overwritten, and the
     * default application's API key is only minted once.
     *
     * The settings seeder is skipped when the store is config-backed (lite):
     * there is no cp_settings table to populate.
     */
    public function run(): void
    {
        $seeders = [];

        if (Edition::enabled('db_settings')) {
            $seeders[] = DefaultSettingsSeeder::class;
        }

        $seeders[] = SamanParserSeeder::class;
        $seeders[] = DefaultApplicationSeeder::class;

        $this->call($seeders);
    }
}
