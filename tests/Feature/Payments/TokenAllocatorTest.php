<?php

declare(strict_types=1);

use CartBecart\CardPay\Exceptions\TokenPoolExhaustedException;
use CartBecart\CardPay\Models\PaymentTokenReservation;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->allocator = new TokenAllocator;
});

describe('allocate()', function () {
    it('reserves a unique payable amount = original + token, token in range', function () {
        $reservation = $this->allocator->allocate(bankCardId: 1, originalAmount: 500_000, digits: 3);

        expect($reservation->exists)->toBeTrue()
            ->and($reservation->bank_card_id)->toBe(1)
            ->and($reservation->active_key)->toBeTrue()
            ->and($reservation->payment_id)->toBeNull()
            ->and($reservation->token)->toBeGreaterThanOrEqual(1)
            ->and($reservation->token)->toBeLessThanOrEqual(999)
            ->and($reservation->payable_amount)->toBe(500_000 + $reservation->token);
    });

    it('never issues a duplicate payable amount across the whole pool (AC-1)', function () {
        // Exhaust the 2-digit pool (99 slots) on one card at a fixed base amount.
        $payables = [];
        for ($i = 0; $i < 99; $i++) {
            $payables[] = $this->allocator->allocate(1, 1_000_000, 2)->payable_amount;
        }

        expect($payables)->toHaveCount(99)
            ->and(array_unique($payables))->toHaveCount(99); // no collisions

        // Every token 1..99 was consumed exactly once.
        $tokens = array_map(fn (int $p): int => $p - 1_000_000, $payables);
        sort($tokens);
        expect($tokens)->toBe(range(1, 99));
    });

    it('throws token_pool_exhausted once every slot is taken (AC-1)', function () {
        for ($i = 0; $i < 99; $i++) {
            $this->allocator->allocate(1, 1_000_000, 2);
        }

        expect(fn () => $this->allocator->allocate(1, 1_000_000, 2))
            ->toThrow(TokenPoolExhaustedException::class);
    });

    it('does not collide across different cards', function () {
        // Same base amount + potential same token, but a different card scope.
        $a = $this->allocator->allocate(1, 1_000_000, 2);
        $b = $this->allocator->allocate(2, 1_000_000, 2);

        expect($a->bank_card_id)->toBe(1)
            ->and($b->bank_card_id)->toBe(2);

        // Both rows exist even if the token (hence payable) happens to coincide.
        expect(PaymentTokenReservation::query()->count())->toBe(2);
    });
});

describe('bind()', function () {
    it('attaches a payment id to the reservation', function () {
        $reservation = $this->allocator->allocate(1, 500_000, 3);

        $this->allocator->bind($reservation, 777);

        expect($reservation->fresh()->payment_id)->toBe(777);
    });
});

describe('cooldown()', function () {
    it('schedules release only on the active reservation of the payment', function () {
        $reservation = $this->allocator->allocate(1, 500_000, 3);
        $this->allocator->bind($reservation, 100);

        $this->allocator->cooldown(100, 15);

        $fresh = $reservation->fresh();
        expect($fresh->release_at)->not->toBeNull()
            ->and($fresh->release_at->toDateTimeString())->toBe(now()->addMinutes(15)->toDateTimeString());
    });

    it('ignores already-released reservations', function () {
        // A released row (active_key = NULL) for the same payment must stay released.
        $released = PaymentTokenReservation::query()->create([
            'payment_id' => 200,
            'bank_card_id' => 1,
            'payable_amount' => 500_123,
            'token' => 123,
            'active_key' => null,
            'release_at' => now()->subDay(),
        ]);

        $this->allocator->cooldown(200, 15);

        // Untouched: still released, release_at unchanged.
        expect($released->fresh()->active_key)->toBeNull();
    });
});

describe('releaseDue()', function () {
    it('releases reservations whose cooldown has elapsed and frees the amount', function () {
        $reservation = $this->allocator->allocate(1, 1_000_000, 3);
        $payable = $reservation->payable_amount;
        $reservation->update(['payment_id' => 1, 'release_at' => now()->subMinute()]);

        $released = $this->allocator->releaseDue(10);

        expect($released)->toBe(1)
            ->and($reservation->fresh()->active_key)->toBeNull();

        // The freed amount can be reserved again (released row is out of unique scope).
        $reused = PaymentTokenReservation::query()->create([
            'payment_id' => null,
            'bank_card_id' => 1,
            'payable_amount' => $payable,
            'token' => $payable - 1_000_000,
            'active_key' => true,
        ]);
        expect($reused->exists)->toBeTrue();
    });

    it('does not release reservations that are not yet due', function () {
        $future = PaymentTokenReservation::query()->create([
            'payment_id' => 1,
            'bank_card_id' => 1,
            'payable_amount' => 1_000_050,
            'token' => 50,
            'active_key' => true,
            'release_at' => now()->addMinutes(30),
        ]);

        expect($this->allocator->releaseDue(10))->toBe(0)
            ->and($future->fresh()->active_key)->toBeTrue();
    });

    it('does not release still-reserved rows that never entered cooldown', function () {
        // active_key = 1 with release_at NULL = an open payment's live reservation.
        $this->allocator->allocate(1, 1_000_000, 3);

        expect($this->allocator->releaseDue(10))->toBe(0);
    });

    it('honours the budget limit', function () {
        for ($i = 1; $i <= 5; $i++) {
            PaymentTokenReservation::query()->create([
                'payment_id' => $i,
                'bank_card_id' => 1,
                'payable_amount' => 1_000_000 + $i,
                'token' => $i,
                'active_key' => true,
                'release_at' => now()->subMinutes($i),
            ]);
        }

        expect($this->allocator->releaseDue(2))->toBe(2)
            ->and(PaymentTokenReservation::query()->whereNull('active_key')->count())->toBe(2);
    });
});
