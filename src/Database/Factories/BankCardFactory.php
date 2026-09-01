<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Database\Factories;

use CartBecart\CardPay\Models\BankCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankCard>
 */
class BankCardFactory extends Factory
{
    protected $model = BankCard::class;

    public function definition(): array
    {
        // A 16-digit PAN; the last four are stored in the clear for display,
        // the full number is encrypted by the model's Encrypted cast on save.
        $pan = (string) fake()->numerify('################');

        return [
            'title' => fake()->words(2, true),
            'bank_name' => fake()->randomElement(['Saman', 'Mellat', 'Melli', 'Parsian', 'Pasargad']),
            'card_number_encrypted' => $pan,
            'card_number_last_four' => substr($pan, -4),
            'card_holder_name' => fake()->name(),
            'iban_encrypted' => 'IR'.fake()->numerify('######################'),
            'description' => null,
            'sms_parser_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
