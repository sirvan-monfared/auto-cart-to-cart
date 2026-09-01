<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Providers;

use CartBecart\CardPay\Actions\Fortify\CreateNewUser;
use CartBecart\CardPay\Actions\Fortify\ResetUserPassword;
use CartBecart\CardPay\Contracts\GatewayUser;
use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\RateLimiting\DbRateLimiter;
use CartBecart\CardPay\Support\GatewayUsers;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * The package owns the whole Fortify integration so a host needs no Fortify
 * wiring of its own: views under cardpay::, username-based active-admin
 * authentication through the GatewayUsers resolver, DB rate limiting, and
 * audit capture. Fortify's features are set in the main provider's register()
 * (before Fortify's own provider boots).
 */
class CardPayFortifyServiceProvider extends ServiceProvider
{
    /**
     * Fortify features the gateway relies on. Applied via config() in the
     * main provider's register phase so host config edits are unnecessary.
     */
    public const FEATURES = [
        'login',
        'passwordReset',
        'emailVerification',
        'passwordConfirmation',
        'twoFactorAuthentication',
        'passkeys',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Username-based admin authentication.
     *
     * The custom resolver owns the whole gate: only an ACTIVE admin account
     * may authenticate; attempts are DB-rate-limited per IP+username; every
     * success and failure is audited with last-login capture.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?GatewayUser {
            // Fortify may invoke this resolver more than once per POST (its
            // pipeline and the session guard each attempt credentials). The
            // side effects — rate limit, audit, last-login capture — must run
            // exactly ONCE per request, so the first invocation claims a
            // per-request marker on the request instance itself.
            if ($request->attributes->get('cardpay.login_recorded') === true) {
                $user = GatewayUsers::findByUsername(Str::lower(trim((string) $request->input(Fortify::username(), ''))));

                return ($user instanceof GatewayUser && $user->isActiveAdmin()) ? $user : null;
            }
            $request->attributes->set('cardpay.login_recorded', true);

            $username = trim((string) $request->input(Fortify::username(), ''));
            $password = (string) $request->input('password', '');

            /** @var DbRateLimiter $limiter */
            $limiter = app(DbRateLimiter::class);

            // 5 attempts / 300 s per IP+username — matches LOGIN_RATE_LIMIT.
            // A tripped limiter surfaces as a login validation error, never a
            // user-existence hint.
            try {
                $limiter->hit('login', 'ipuser:'.$request->ip().'|'.Str::lower($username),
                    (int) config('cardpay.rate_limits.login', 5), 300);
            } catch (ApiException) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('Too many login attempts. Please try again later.'),
                ]);
            }

            $user = GatewayUsers::findByUsername(Str::lower($username));

            if (! $user instanceof GatewayUser
                || ! $user->isActiveAdmin()
                || ! Hash::check($password, $user->password)) {
                app(AuditLogger::class)->log(
                    action: 'auth.login_failed',
                    actorType: 'admin',
                    entityType: 'user',
                    entityId: $user !== null ? (string) $user->getKey() : null,
                );

                return null;
            }

            $user->forceFill([
                'last_login_at' => now(),
                'last_ip' => (string) $request->ip(),
            ])->saveQuietly();

            app(AuditLogger::class)->log(
                action: 'auth.login_succeeded',
                actorType: 'admin',
                actorId: $user->getKey(),
                entityType: 'user',
                entityId: (string) $user->getKey(),
            );

            session()->regenerate();

            return $user;
        });
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('cardpay::pages.auth.login'));
        Fortify::verifyEmailView(fn () => view('cardpay::pages.auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('cardpay::pages.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('cardpay::pages.auth.confirm-password'));
        Fortify::registerView(fn () => view('cardpay::pages.auth.register'));
        Fortify::resetPasswordView(fn () => view('cardpay::pages.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('cardpay::pages.auth.forgot-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
