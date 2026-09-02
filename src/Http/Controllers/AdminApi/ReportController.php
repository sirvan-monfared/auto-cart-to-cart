<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Payment aggregates over a date window, as JSON or streamed CSV.
 *
 * Bounds are inclusive UTC days and unparseable input silently falls back to
 * the last 30 days — a report is a read-only convenience and must never 500 on
 * a malformed query string. The CSV is streamed row by row so exporting a
 * year of traffic costs the same memory as exporting a day.
 */
final class ReportController extends Controller
{
    use RespondsWithJson;

    private const STATUSES = ['pending', 'paid', 'expired', 'canceled', 'rejected', 'manual_review'];

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->bounds($request);

        $byStatus = Payment::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as n, coalesce(sum(payable_amount),0) as volume')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->getAttribute('status')->value);

        $rows = [];
        foreach (self::STATUSES as $status) {
            $rows[] = [
                'status' => $status,
                // Aggregate aliases are plain attributes, not model properties.
                'count' => isset($byStatus[$status]) ? (int) $byStatus[$status]->getAttribute('n') : 0,
                'volume' => isset($byStatus[$status]) ? (int) $byStatus[$status]->getAttribute('volume') : 0,
            ];
        }

        return $this->ok([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => (string) config('cardpay.currency', 'IRR'),
            'rows' => $rows,
        ]);
    }

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

            fputcsv($out, ['public_id', 'external_order_id', 'bank_card_id', 'original_amount',
                'token', 'payable_amount', 'currency', 'status', 'created_at', 'paid_at']);

            Payment::query()
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')
                ->chunk(500, function ($payments) use ($out): void {
                    foreach ($payments as $payment) {
                        fputcsv($out, [
                            $payment->public_id, $payment->external_order_id, $payment->bank_card_id,
                            $payment->original_amount, $payment->token, $payment->payable_amount,
                            $payment->currency, $payment->status->value,
                            $payment->created_at?->toIso8601String(),
                            $payment->paid_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(Request $request): array
    {
        try {
            $from = (new Carbon((string) $request->date('from')))->startOfDay();
        } catch (Throwable) {
            $from = new Carbon(now()->subDays(30)->startOfDay());
        }

        try {
            $to = (new Carbon((string) $request->date('to')))->endOfDay();
        } catch (Throwable) {
            $to = new Carbon(now()->endOfDay());
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
