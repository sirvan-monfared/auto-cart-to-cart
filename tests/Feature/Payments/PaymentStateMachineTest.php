<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Exceptions\InvalidStatusTransitionException;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Payments\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The authoritative §9.2 transition map, mirrored here independently of the
 * implementation so the test would catch a silent edit to the production map.
 *
 * @return array<string, list<PaymentStatus>>
 */
function legalMap(): array
{
    return [
        PaymentStatus::Pending->value => [
            PaymentStatus::Paid,
            PaymentStatus::Expired,
            PaymentStatus::Canceled,
            PaymentStatus::ManualReview,
        ],
        PaymentStatus::ManualReview->value => [
            PaymentStatus::Paid,
            PaymentStatus::Rejected,
        ],
        PaymentStatus::Expired->value => [
            PaymentStatus::ManualReview,
            PaymentStatus::Paid,
        ],
        PaymentStatus::Paid->value => [],
        PaymentStatus::Canceled->value => [],
        PaymentStatus::Rejected->value => [],
    ];
}

/**
 * Create a pending payment without touching the factory's parent graph (no
 * BankCard/Crypto), so this suite exercises the state machine in isolation.
 */
function pendingPayment(array $overrides = []): Payment
{
    return Payment::factory()->create([
        'application_id' => 1,
        'bank_card_id' => 1,
        'status' => PaymentStatus::Pending,
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->machine = new PaymentStateMachine;
});

describe('can() reflects the full §9.2 matrix', function () {
    $cases = [];
    foreach (PaymentStatus::cases() as $from) {
        foreach (PaymentStatus::cases() as $to) {
            $cases["{$from->value} -> {$to->value}"] = [$from, $to];
        }
    }

    it('permits exactly the mapped transitions', function (PaymentStatus $from, PaymentStatus $to) {
        $expected = in_array($to, legalMap()[$from->value], true);

        expect($this->machine->can($from, $to))->toBe($expected);
    })->with($cases);
});

describe('terminal states never reopen', function () {
    it('canceled can never become paid', function () {
        expect($this->machine->can(PaymentStatus::Canceled, PaymentStatus::Paid))->toBeFalse();
    });

    it('rejected can never become paid', function () {
        expect($this->machine->can(PaymentStatus::Rejected, PaymentStatus::Paid))->toBeFalse();
    });

    it('paid is fully terminal', function () {
        foreach (PaymentStatus::cases() as $to) {
            expect($this->machine->can(PaymentStatus::Paid, $to))->toBeFalse();
        }
    });

    it('a status can never transition to itself', function () {
        foreach (PaymentStatus::cases() as $s) {
            expect($this->machine->can($s, $s))->toBeFalse();
        }
    });
});

describe('assertCan()', function () {
    it('throws on an illegal transition', function () {
        $this->machine->assertCan(PaymentStatus::Canceled, PaymentStatus::Paid);
    })->throws(InvalidStatusTransitionException::class);

    it('is silent on a legal transition', function () {
        $this->machine->assertCan(PaymentStatus::Pending, PaymentStatus::Paid);
    })->throwsNoExceptions();
});

describe('transition() persistence', function () {
    it('moves a pending payment to paid and reflects it in memory and DB', function () {
        $payment = pendingPayment();
        $paidAt = now()->subSeconds(3);

        $won = $this->machine->transition($payment, PaymentStatus::Paid, [
            'paid_at' => $paidAt,
            'matched_sms_id' => 42,
        ]);

        expect($won)->toBeTrue()
            ->and($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->matched_sms_id)->toBe(42)
            ->and($payment->paid_at->toDateTimeString())->toBe($paidAt->toDateTimeString());

        $fresh = $payment->fresh();
        expect($fresh->status)->toBe(PaymentStatus::Paid)
            ->and($fresh->matched_sms_id)->toBe(42)
            ->and($fresh->paid_at->toDateTimeString())->toBe($paidAt->toDateTimeString());
    });

    it('throws and writes nothing on an illegal transition', function () {
        $payment = pendingPayment()->fresh();
        $paid = Payment::factory()->create([
            'application_id' => 1,
            'bank_card_id' => 1,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        expect(fn () => $this->machine->transition($paid, PaymentStatus::Pending))
            ->toThrow(InvalidStatusTransitionException::class);

        expect($paid->fresh()->status)->toBe(PaymentStatus::Paid);
    });
});

describe('concurrency: exactly one writer wins', function () {
    it('lets only the first of two racers move the row', function () {
        $payment = pendingPayment();

        // Two independent in-memory handles that both still see "pending".
        $racerA = Payment::query()->findOrFail($payment->id);
        $racerB = Payment::query()->findOrFail($payment->id);

        $aWon = $this->machine->transition($racerA, PaymentStatus::Paid, ['paid_at' => now()]);
        $bWon = $this->machine->transition($racerB, PaymentStatus::Canceled, ['canceled_at' => now()]);

        expect($aWon)->toBeTrue()
            ->and($bWon)->toBeFalse();

        // The loser must not have altered the row: it stays paid, not canceled.
        expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('a duplicate confirmation attempt is a no-op (no double-pay)', function () {
        $payment = pendingPayment();

        // Two requests that both read the row while it is still pending.
        $racerA = Payment::query()->findOrFail($payment->id);
        $racerB = Payment::query()->findOrFail($payment->id);

        $first = $this->machine->transition($racerA, PaymentStatus::Paid, ['paid_at' => now()]);
        $second = $this->machine->transition($racerB, PaymentStatus::Paid, ['paid_at' => now()]);

        expect($first)->toBeTrue()
            ->and($second)->toBeFalse();
    });

    it('does not mutate the in-memory model when it loses the race', function () {
        $payment = pendingPayment();

        // Our handle, loaded while the row is still pending.
        $stale = Payment::query()->findOrFail($payment->id);

        // Someone else confirms it first, via a different handle.
        $this->machine->transition(
            Payment::query()->findOrFail($payment->id),
            PaymentStatus::Paid,
            ['paid_at' => now()],
        );

        // Our stale handle still thinks it is pending; the failed transition
        // must leave our handle's status untouched (not optimistically flipped).
        $lost = $this->machine->transition($stale, PaymentStatus::Canceled, ['canceled_at' => now()]);

        expect($lost)->toBeFalse()
            ->and($stale->status)->toBe(PaymentStatus::Pending)
            ->and($stale->canceled_at)->toBeNull();
    });
});

describe('expired → paid is legal only by explicit call (admin path)', function () {
    it('permits expired to paid', function () {
        $payment = pendingPayment()->fresh();
        $this->machine->transition($payment, PaymentStatus::Expired);

        $won = $this->machine->transition($payment, PaymentStatus::Paid, ['paid_at' => now()]);

        expect($won)->toBeTrue()
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    });
});
