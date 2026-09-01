<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Reports (§FR-16): date-filtered payment aggregates in the UI, plus a CSV
 * export streamed row-by-row (bounded memory regardless of result size).
 * Dates are inclusive UTC day bounds; invalid input falls back to defaults.
 */
final class ReportsController extends Controller
{
    private const STATUSES = ['pending', 'paid', 'expired', 'canceled', 'rejected', 'manual_review'];

    public function index(Request $request): View
    {
        [$from, $to] = $this->bounds($request);

        $byStatus = Payment::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as n, coalesce(sum(payable_amount),0) as volume')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getAttribute('status')->value);

        return view('cardpay::admin.reports', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => collect(self::STATUSES)->map(fn ($s) => [
                'status' => $s,
                // Aggregate aliases live as plain attributes; read via getAttribute
                // so the analyzer doesn't expect model properties that don't exist.
                'count' => isset($byStatus[$s]) ? (int) $byStatus[$s]->getAttribute('n') : 0,
                'volume' => isset($byStatus[$s]) ? (int) $byStatus[$s]->getAttribute('volume') : 0,
            ]),
        ]);
    }

    /**
     * CSV export of the same window: one line per payment. Streamed so even a
     * year of traffic never materializes the whole file in memory.
     */
    public function csv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->bounds($request);

        $filename = "payments-{$from->toDateString()}-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($from, $to): void {
            /** @var resource|false $out */
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return; // output stream unavailable — nothing sensible to do
            }

            fputcsv($out, ['public_id', 'application_id', 'bank_card_id', 'original_amount', 'token',
                'payable_amount', 'currency', 'status', 'created_at', 'paid_at']);

            Payment::query()
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')
                ->chunk(500, function ($payments) use ($out): void {
                    foreach ($payments as $p) {
                        fputcsv($out, [
                            $p->public_id, $p->application_id, $p->bank_card_id,
                            $p->original_amount, $p->token, $p->payable_amount,
                            $p->currency, $p->status->value,
                            $p->created_at?->toIso8601String(), $p->paid_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Inclusive UTC bounds from the query string; defaults cover the last 30
     * days. Unparseable input silently falls back — a report never 500s.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(Request $request): array
    {
        try {
            $from = new Carbon((string) $request->date('from'));
            $from = $from->startOfDay();
        } catch (Throwable) {
            $from = new Carbon(now()->subDays(30)->startOfDay());
        }

        try {
            $to = new Carbon((string) $request->date('to'));
            $to = $to->endOfDay();
        } catch (Throwable) {
            $to = new Carbon(now()->endOfDay());
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
