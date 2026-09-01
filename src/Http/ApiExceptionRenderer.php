<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http;

use CartBecart\CardPay\Enums\ApiErrorCode;
use CartBecart\CardPay\Exceptions\ApiException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * API exception rendering behavior. Wire it in one of two ways:
 *
 * Host apps (Laravel 11+ bootstrap/app.php):
 *
 *   ->withExceptions(function (Exceptions $exceptions): void {
 *       CartBecart\CardPay\Http\ApiExceptionRenderer::configure($exceptions);
 *   })
 *
 * Package tests / programmatic setups get the same behavior automatically
 * via attachHandler(), which applies the identical rules to the bound
 * Illuminate\Foundation\Exceptions\Handler instance.
 */
final class ApiExceptionRenderer
{
    public static function configure(Exceptions $exceptions): void
    {
        // Catalog errors are expected client failures — never logged.
        $exceptions->dontReport(ApiException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        foreach (self::renderCallbacks() as $callback) {
            $exceptions->render($callback);
        }
    }

    /**
     * Apply the same rules to a bound exception handler instance (used where
     * the withExceptions() configuration API is not available, e.g. package
     * test suites).
     */
    public static function attachHandler(object $handler): void
    {
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        if (property_exists($handler, 'dontReport') || method_exists($handler, 'dontReport')) {
            $handler->dontReport([ApiException::class]);
        }

        if (method_exists($handler, 'shouldRenderJsonWhen')) {
            $handler->shouldRenderJsonWhen(
                fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
            );
        }

        foreach (self::renderCallbacks() as $callback) {
            $handler->renderable($callback);
        }
    }

    /**
     * @return list<callable>
     */
    private static function renderCallbacks(): array
    {
        return [
            // Error envelope for catalog errors, at the code's HTTP status.
            function (ApiException $e, Request $request): ?JsonResponse {
                if (! ($request->is('api/*') || $request->expectsJson())) {
                    return null;
                }

                $response = response()->json($e->toArray(), $e->status());

                if ($e->errorCode === ApiErrorCode::RateLimitExceeded) {
                    $retryAfter = $e->details['retry_after'] ?? 0;
                    $response->headers->set('Retry-After', (string) (is_int($retryAfter) ? max(0, $retryAfter) : 0));
                }

                return $response;
            },

            // Any other fault on the API surface: still logged by the default
            // report pipeline, but returned as a safe, generic internal_error
            // 500 that never leaks internals. Framework HTTP exceptions —
            // including the 419 CSRF rejection and auth redirects — keep
            // their OWN status via the default renderer.
            function (Throwable $e, Request $request): ?JsonResponse {
                if ($e instanceof ApiException
                    || $e instanceof HttpExceptionInterface
                    || $e instanceof TokenMismatchException
                    || $e instanceof OriginMismatchException) {
                    return null;
                }

                if (! ($request->is('api/*') || $request->expectsJson())) {
                    return null;
                }

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => ApiErrorCode::InternalError->value,
                        'message' => ApiErrorCode::InternalError->message(),
                        'details' => (object) (config('app.debug') ? ['exception' => $e->getMessage()] : []),
                    ],
                ], 500);
            },
        ];
    }
}
