<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Sms\ParserConfig;
use CartBecart\CardPay\Services\Sms\SmsParser as ParserPipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-bank parser CRUD (§FR-6) with the LIVE TEST action: submit arbitrary
 * SMS text + sender, see the parse result instantly — the admin can prove a
 * regex works before any device uses it. Regexes are applied defensively
 * downstream; here an invalid pattern surfaces as "no match" rather than 500.
 */
final class SmsParserAdminController extends Controller
{
    public function __construct(
        private readonly ParserPipeline $pipeline,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('cardpay::admin.parsers', [
            'parsers' => SmsParser::query()->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $parser = SmsParser::query()->create($this->validated($request));

        $this->audit->log('parser.created', 'admin', $request->user()?->id, 'sms_parser', (string) $parser->id,
            null, ['name' => $parser->name]);

        return back()->with('parser_ok', 'created');
    }

    public function update(Request $request, SmsParser $parser): RedirectResponse
    {
        $parser->update($this->validated($request));

        $this->audit->log('parser.updated', 'admin', $request->user()?->id, 'sms_parser', (string) $parser->id,
            null, ['name' => $parser->name]);

        return back()->with('parser_ok', 'updated');
    }

    /**
     * Live test: run the submitted text through the parse pipeline against
     * THIS parser's rules and show the outcome inline. Never persists.
     */
    public function liveTest(Request $request, SmsParser $parser): RedirectResponse
    {
        $data = $request->validate([
            'test_text' => ['required', 'string', 'max:10000'],
            'test_sender' => ['nullable', 'string', 'max:190'],
        ]);

        $result = $this->runTest($parser, $data['test_text'], $data['test_sender'] ?? null);

        return back()->with('live_test', $result);
    }

    /**
     * @return array{status: string, amount: int|null, error: string|null}
     */
    private function runTest(SmsParser $parser, string $text, ?string $sender): array
    {
        // Sender gate mirrors ingestion (§FR-10 step 1).
        $pattern = trim((string) $parser->sender_pattern);
        if ($pattern !== '' && ($sender === null || @preg_match($pattern, $sender) !== 1)) {
            return ['status' => 'ignored', 'amount' => null, 'error' => 'sender_mismatch'];
        }

        $result = $this->pipeline->parse($text, ParserConfig::fromModel($parser), Carbon::now());

        return [
            'status' => $result->status->value,
            'amount' => $result->amount,
            'error' => $result->failureReason,
        ];
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
            'positive_keywords' => ['nullable', 'string'],
            'negative_keywords' => ['nullable', 'string'],
            'sample_sms' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Keyword lists are edited as comma/newline-separated text, stored as
        // JSON arrays via the model cast.
        foreach (['positive_keywords', 'negative_keywords'] as $field) {
            $data[$field] = $this->splitKeywords($data[$field] ?? null);
        }

        return [
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function splitKeywords(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
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
