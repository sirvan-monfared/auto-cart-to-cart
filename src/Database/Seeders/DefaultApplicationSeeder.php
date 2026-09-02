<?php

namespace CartBecart\CardPay\Database\Seeders;

use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The default merchant application with its first API key.
 *
 * Idempotent on slug, and delegated to {@see GatewayProvisioner} so seeding,
 * `cardpay:install`, and the admin API all create the gateway the same way.
 * The API key is minted ONLY when the application is newly created —
 * re-running never rotates a live secret. The seeder discards the revealed
 * credentials by design: use `cardpay:install` (which prints them once) or
 * `cardpay:api-key:rotate` to obtain a usable secret.
 */
class DefaultApplicationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function __construct(private readonly GatewayProvisioner $provisioner) {}

    public function run(): void
    {
        $this->provisioner->provision();
    }
}
