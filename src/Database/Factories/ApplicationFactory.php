<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Database\Factories;

use CartBecart\CardPay\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => null,
            'public_key' => 'app_'.Str::lower(Str::random(32)),
            'webhook_url' => null,
            'callback_url' => null,
            'allowed_domains' => null,
            'is_active' => true,
            'token_digits' => 3,
            'payment_expiration_minutes' => 30,
            'default_bank_card_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
