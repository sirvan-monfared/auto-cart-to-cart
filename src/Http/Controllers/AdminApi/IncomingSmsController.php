<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\IncomingSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The relayed bank SMS log — the diagnostic surface for "the customer paid but
 * nothing happened".
 *
 * Filtering by match_status=unmatched or parse_status=failed is how an
 * operator finds the two real failure modes: a deposit that arrived with no
 * open payment at that amount, and a message the bank's parser could not read.
 * Read-only: re-matching is the matcher's job, and settling by hand goes
 * through the review queue so the money path keeps its guarantees.
 */
final class IncomingSmsController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $query = IncomingSms::query();

        if (($match = MatchStatus::tryFrom((string) $request->string('match_status'))) !== null) {
            $query->where('match_status', $match);
        }

        if (($parse = ParseStatus::tryFrom((string) $request->string('parse_status'))) !== null) {
            $query->where('parse_status', $parse);
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->integer('device_id'));
        }

        if (($from = $request->date('from')) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $request->date('to')) !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $this->page(
            $query->latest('id')->paginate($this->perPage($request))->withQueryString(),
            Present::sms(...),
        );
    }

    public function show(IncomingSms $sms): JsonResponse
    {
        return $this->ok(Present::sms($sms));
    }
}
