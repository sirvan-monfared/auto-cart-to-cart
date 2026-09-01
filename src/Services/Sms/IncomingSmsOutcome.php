<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Models\IncomingSms;

/**
 * The wire outcome of one ingestion request (§FR-9 response shape):
 * `{sms_id, duplicate, parse_status, match_status, error?, payment_id?}`.
 *
 * `error` is the parse failure reason (or null); `paymentId` is set only when
 * this message confirmed a payment — including on duplicates, where it replays
 * the ORIGINAL match so a retrying device learns what its first attempt did.
 */
final readonly class IncomingSmsOutcome
{
    public function __construct(
        public IncomingSms $sms,
        public bool $duplicate,
        public ?int $paymentId = null,
    ) {}

    public static function duplicate(IncomingSms $sms): self
    {
        return new self($sms, duplicate: true, paymentId: $sms->matched_payment_id);
    }

    /** HTTP status per §11.2: fresh ⇒ 201, duplicate ⇒ 200. */
    public function status(): int
    {
        return $this->duplicate ? 200 : 201;
    }

    /**
     * @return array{sms_id: int, duplicate: bool, parse_status: string, match_status: string, error?: string, payment_id?: int}
     */
    public function toArray(): array
    {
        // A freshly created row does not reflect the DB column defaults in memory
        // until refresh, so fall back to the schema defaults (§7.2).
        $parseStatus = $this->sms->parse_status ?? ParseStatus::Pending;
        $matchStatus = $this->sms->match_status ?? MatchStatus::Unmatched;

        $data = [
            'sms_id' => $this->sms->id,
            'duplicate' => $this->duplicate,
            'parse_status' => $parseStatus->value,
            'match_status' => $matchStatus->value,
        ];

        if (($reason = $this->sms->parse_error) !== null) {
            $data['error'] = $reason;
        }

        if ($this->paymentId !== null) {
            $data['payment_id'] = $this->paymentId;
        }

        return $data;
    }
}
