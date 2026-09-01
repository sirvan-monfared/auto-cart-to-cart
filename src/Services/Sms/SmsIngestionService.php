<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\SmsParser as SmsParserModel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Device SMS ingestion (§FR-9 → §FR-10 → §FR-11).
 *
 * One entry point for both device surfaces (full HMAC and shortcut). The
 * pipeline is deliberately ordered so that EVERY relayed message is durably
 * recorded exactly once, and every recorded message carries its parse + match
 * outcome for the audit trail:
 *
 *   1. dedupe on UNIQUE(device_id, message_id) — a replay returns the ORIGINAL
 *      outcome (including the previously matched payment), never re-runs the
 *      matcher (which would be a second chance to confirm money);
 *   2. persist first — a crash mid-pipeline still leaves the evidence row;
 *   3. sender-pattern gate → `ignored/sender_mismatch` when configured;
 *   4. resolve the ACTIVE parser linked to the device's bound card; none ⇒
 *      recorded failure `parser_not_configured` (never a silent drop);
 *   5. run the pure parse pipeline, then the fail-safe matching engine.
 *
 * Device stats (last_seen_at/ip, sms_count) advance per accepted message.
 */
final class SmsIngestionService
{
    /** Recorded failure reason when the card has no usable parser. */
    public const REASON_PARSER_NOT_CONFIGURED = 'parser_not_configured';

    public function __construct(
        private readonly SmsParser $parser,
        private readonly MatchingEngine $engine,
    ) {}

    /**
     * @return IncomingSmsOutcome The persisted SMS plus its wire outcome.
     */
    public function ingest(
        Device $device,
        string $messageId,
        string $rawSms,
        \DateTimeInterface $receivedAt,
        ?string $sender,
        ?string $sourceIp,
    ): IncomingSmsOutcome {
        // 1. Dedupe: a repeated message_id replays the original outcome. The
        // unique constraint is the source of truth — the pre-check is only an
        // optimization; a lost race falls through to the catch below.
        $existing = IncomingSms::query()
            ->where('device_id', $device->id)
            ->where('message_id', $messageId)
            ->first();

        if ($existing !== null) {
            return IncomingSmsOutcome::duplicate($existing);
        }

        try {
            // 2. Persist BEFORE parsing so every accepted delivery leaves evidence.
            $sms = DB::transaction(function () use ($device, $messageId, $rawSms, $receivedAt, $sender, $sourceIp): IncomingSms {
                $row = IncomingSms::query()->create([
                    'device_id' => $device->id,
                    'bank_card_id' => $device->bank_card_id,
                    'message_id' => $messageId,
                    'sender' => $sender,
                    'raw_sms' => $rawSms,
                    'received_at' => $receivedAt,
                    'server_received_at' => now(),
                    'source_ip' => $sourceIp,
                ]);

                // §FR-9: stats advance once per ACCEPTED (non-duplicate) message.
                $device->forceFill([
                    'last_seen_at' => now(),
                    'last_ip' => $sourceIp,
                    'sms_count' => $device->sms_count + 1,
                ])->save();

                return $row;
            });
        } catch (Throwable $e) {
            return $this->duplicateAfterRace($device, $messageId, $e);
        }

        // 3–5. Parse and match outside the insert transaction; both write their
        // outcomes back onto the stored row.
        return new IncomingSmsOutcome($sms, duplicate: false, paymentId: $this->process($sms));
    }

    /**
     * Run the §FR-10 parse pipeline and the §FR-11 matcher against a freshly
     * stored row, returning the matched payment id when confirmation happened.
     */
    private function process(IncomingSms $sms): ?int
    {
        // 3–4. The ACTIVE parser linked to the device's bound card (§FR-9); none
        // ⇒ recorded failure, never a silent drop. Sender gate next: configured
        // pattern ∧ mismatching sender ⇒ ignored.
        $parser = $this->activeParserFor($sms);

        if ($parser === null) {
            $this->recordFailure($sms, ParseStatus::Failed, self::REASON_PARSER_NOT_CONFIGURED);

            return null;
        }

        $pattern = trim((string) $parser->sender_pattern);

        if ($pattern !== '' && ($sms->sender === null || preg_match($pattern, $sms->sender) !== 1)) {
            $this->recordFailure($sms, ParseStatus::Ignored, 'sender_mismatch');

            return null;
        }

        // 5a. Pure parse pipeline (§A3).
        $result = $this->parser->parse(
            (string) $sms->raw_sms,
            ParserConfig::fromModel($parser),
            $sms->received_at,
        );

        $sms->update([
            'parse_status' => $result->status,
            'parsed_amount' => $result->amount,
            'parsed_transaction_at' => $result->transactionAt,
            'parse_error' => $result->failureReason,
        ]);

        if (! $result->isOk()) {
            return null; // ignored or failed — never goes near the matcher
        }

        // 5b. Fail-safe matching (§A4/§FR-11).
        return $this->engine->match($sms)->payment?->id;
    }

    private function recordFailure(IncomingSms $sms, ParseStatus $status, string $reason): void
    {
        $sms->update([
            'parse_status' => $status,
            'parse_error' => $reason,
        ]);
    }

    /**
     * The active parser configured on the SMS's bound card (device → card →
     * parser), or null when the card is missing/inactive or has no parser.
     */
    private function activeParserFor(IncomingSms $sms): ?SmsParserModel
    {
        /** @var SmsParserModel|null */
        return SmsParserModel::query()
            ->whereHas('bankCards', fn ($q) => $q
                ->where('id', $sms->bank_card_id)
                ->where('is_active', true))
            ->where('is_active', true)
            ->first();
    }

    /**
     * A concurrent request inserted the same (device_id, message_id) between our
     * SELECT and INSERT. Re-read and replay the original outcome; anything other
     * than that unique violation is a genuine fault.
     */
    private function duplicateAfterRace(Device $device, string $messageId, Throwable $e): IncomingSmsOutcome
    {
        $duplicate = IncomingSms::query()
            ->where('device_id', $device->id)
            ->where('message_id', $messageId)
            ->first();

        if ($duplicate !== null) {
            return IncomingSmsOutcome::duplicate($duplicate);
        }

        throw $e;
    }
}
