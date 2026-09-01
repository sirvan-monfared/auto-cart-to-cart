<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Api;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Http\Middleware\MerchantHmacAuth;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant payment endpoints (§11.2 #1–4), all behind the `merchant.hmac` gate.
 *
 * Every success is the §11.1 envelope `{"success":true,"data":{…}}`; failures are
 * thrown as {@see ApiException} and rendered as the error envelope by the handler.
 * Ownership is enforced inside {@see PaymentService} — lookups are scoped to the
 * authenticated application, so a foreign payment id is indistinguishable from a
 * missing one (`payment_not_found`), with no cross-tenant leak.
 */
final class PaymentApiController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** POST /api/v1/payments — create (201) or idempotent replay (200). */
    public function store(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        $result = $this->payments->create($application, $this->body($request), $idempotencyKey);

        return $this->success($result->data, $result->status());
    }

    /** GET /api/v1/payments/{payment} — current status (200). */
    public function show(Request $request, string $payment): JsonResponse
    {
        $application = $this->application($request);
        $found = $this->payments->find($application, $payment);

        return $this->success($this->payments->present($found, replay: false), 200);
    }

    /** POST /api/v1/payments/{payment}/verify — same presentment as show (200). */
    public function verify(Request $request, string $payment): JsonResponse
    {
        return $this->show($request, $payment);
    }

    /** POST /api/v1/payments/{payment}/cancel — cancel a pending payment (200). */
    public function cancel(Request $request, string $payment): JsonResponse
    {
        $application = $this->application($request);

        $result = $this->payments->cancel($application, $payment);

        return $this->success($result->data, $result->status());
    }

    /**
     * The application authenticated by `merchant.hmac`. Its absence would mean the
     * gate was bypassed; fail closed rather than act unauthenticated.
     */
    private function application(Request $request): Application
    {
        $application = $request->attributes->get(MerchantHmacAuth::APPLICATION_ATTRIBUTE);

        if (! $application instanceof Application) {
            throw ApiException::invalidApiKey();
        }

        return $application;
    }

    /**
     * The decoded JSON body as an associative array (empty when absent).
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->json()->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data, int $status): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}
