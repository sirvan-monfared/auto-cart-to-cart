<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Database\Factories;

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $original = fake()->numberBetween(10_000, 5_000_000);
        $token = fake()->numberBetween(1, 999);

        return [
            'public_id' => 'pay_'.Str::lower(Str::random(24)),
            'application_id' => Application::factory(),
            'bank_card_id' => BankCard::factory(),
            'driver' => 'card_transfer',
            'external_order_id' => null,
            'original_amount' => $original,
            'token' => $token,
            'payable_amount' => $original + $token,
            'currency' => 'IRR',
            'description' => null,
            'customer_name' => null,
            'customer_mobile' => null,
            'customer_reference' => null,
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(30),
            'paid_at' => null,
            'canceled_at' => null,
            'matched_sms_id' => null,
            'return_url' => null,
            'callback_url' => null,
            'metadata_json' => null,
        ];
    }

    /**
     * Fix the exact payable amount (matcher tests pivot on this).
     */
    public function payable(int $amount): static
    {
        return $this->state(fn (): array => ['payable_amount' => $amount]);
    }

    public function forCard(BankCard|int $card): static
    {
        return $this->state(fn (): array => [
            'bank_card_id' => $card instanceof BankCard ? $card->id : $card,
        ]);
    }

    public function forApplication(Application|int $app): static
    {
        return $this->state(fn (): array => [
            'application_id' => $app instanceof Application ? $app->id : $app,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Expired,
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }

    public function manualReview(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::ManualReview]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Rejected]);
    }

    /**
     * Already past its expiry instant but still pending (a candidate for lazy expiry).
     */
    public function due(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
