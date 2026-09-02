<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi\Concerns;

use CartBecart\CardPay\Http\ApiExceptionRenderer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Admin API speaks the same envelope as the merchant API (§11.1):
 * `{"success": true, "data": …}` on success, and the error envelope rendered
 * by {@see ApiExceptionRenderer} on failure — so a
 * host admin can use one response handler for every CardPay call.
 */
trait RespondsWithJson
{
    protected function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    /**
     * A paginated collection: the rows in `data`, cursor info in `meta`.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  callable(mixed): array<string, mixed>  $transform
     */
    protected function page(LengthAwarePaginator $paginator, callable $transform): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => array_map($transform, $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Caller-controlled page size, clamped so a hostile or careless
     * `per_page=100000` can never turn a listing into a denial of service.
     */
    protected function perPage(Request $request): int
    {
        $default = max(1, (int) config('cardpay.admin_api.per_page', 25));
        $requested = (int) $request->integer('per_page');

        return $requested < 1 ? $default : min($requested, 100);
    }
}
