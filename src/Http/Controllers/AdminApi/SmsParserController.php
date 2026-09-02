<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Sms\ParserConfig;
use CartBecart\CardPay\Services\Sms\SmsParser as ParserPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-bank SMS extraction rules (§FR-6), plus the live-test action.
 *
 * The test endpoint is the point of this surface: an operator can prove a
 * regex extracts the right amount from a real bank message BEFORE any device
 * relies on it, without persisting anything. Patterns are applied defensively
 * downstream, so a broken regex surfaces here as "no match" rather than a 500.
 */
final class SmsParserController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ParserPipeline $pipeline,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->page(
            SmsParser::query()->latest('id')->paginate($this->perPage($request)),
            Present::parser(...),
        );
    }

    public function show(SmsParser $parser): JsonResponse
    {
        return $this->ok(Present::parser($parser));
    }

    public function store(Request $request): JsonResponse
    {
        $parser = SmsParser::query()->create($this->validated($request));

        $this->audit->log('parser.created', 'admin', $request->user()?->id, 'sms_parser', (string) $parser->id,
            null, ['name' => $parser->name]);

        return $this->ok(Present::parser($parser), 201);
    }

    public function update(Request $request, SmsParser $parser): JsonResponse
    {
        $parser->update($this->validated($request));

        $this->audit->log('parser.updated', 'admin', $request->user()?->id, 'sms_parser', (string) $parser->id,
            null, ['name' => $parser->name]);

        return $this->ok(Present::parser($parser));
    }

    /** Dry-run this parser against arbitrary text. Never persists. */
    public function test(Request $request, SmsParser $parser): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:10000'],
            'sender' => ['nullable', 'string', 'max:190'],
        ]);

        $sender = $data['sender'] ?? null;

        // The sender gate mirrors ingestion (§FR-10 step 1), so a test result
        // means the same thing a live message would.
        $pattern = trim((string) $parser->sender_pattern);
        if ($pattern !== '' && ($sender === null || @preg_match($pattern, $sender) !== 1)) {
            return $this->ok(['status' => 'ignored', 'amount' => null, 'error' => 'sender_mismatch']);
        }

        $result = $this->pipeline->parse($data['text'], ParserConfig::fromModel($parser), Carbon::now());

        return $this->ok([
            'status' => $result->status->value,
            'amount' => $result->amount,
            'error' => $result->failureReason,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'bank_name' => ['required', 'string', 'max:150'],
            'sender_pattern' => ['nullable', 'string', 'max:255'],
            'amount_regex' => ['required', 'string', 'max:500'],
            'date_regex' => ['nullable', 'string', 'max:500'],
            'time_regex' => ['nullable', 'string', 'max:500'],
            'transaction_type_regex' => ['nullable', 'string', 'max:500'],
            'positive_keywords' => ['nullable'],
            'negative_keywords' => ['nullable'],
            'sample_sms' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Keyword lists accept either a JSON array (natural for an API client)
        // or the panel's comma/newline separated text.
        foreach (['positive_keywords', 'negative_keywords'] as $field) {
            $data[$field] = $this->keywords($data[$field] ?? null);
        }

        return [
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function keywords(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $clean = array_values(array_filter(array_map(
                fn ($item): string => trim((string) $item),
                $raw,
            ), fn (string $item): bool => $item !== ''));

            return $clean === [] ? null : $clean;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $items = preg_split('/[,\n\r]+/u', $raw) ?: [];

        $clean = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $clean[] = $item;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
