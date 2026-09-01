<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings editor (§FR-16), split into sections with typed values and
 * `is_public` exposure control: only public rows may reach the hosted
 * checkout, so flipping that flag is itself an audited security decision.
 *
 * The editable key set is WHITELISTED below — arbitrary keys can never be
 * injected through the form (§SR-10 spirit: identifiers are whitelisted).
 */
final class SettingsAdminController extends Controller
{
    /**
     * Editable settings whitelist: key => [type, section, label].
     *
     * @var array<string, array{string, string, string}>
     */
    private const EDITABLE = [
        'app_name' => ['string', 'general', 'settings.label.app_name'],
        'currency' => ['string', 'general', 'settings.label.currency'],
        'timezone' => ['string', 'general', 'settings.label.timezone'],
        'locale' => ['string', 'general', 'settings.label.locale'],
        'default_token_digits' => ['int', 'payments', 'settings.label.default_token_digits'],
        'default_expiration_minutes' => ['int', 'payments', 'settings.label.default_expiration_minutes'],
        'token_cooldown_minutes' => ['int', 'payments', 'settings.label.token_cooldown_minutes'],
        'payment_title' => ['string', 'branding', 'settings.label.payment_title'],
        'payment_help' => ['string', 'branding', 'settings.label.payment_help'],
        'success_text' => ['string', 'branding', 'settings.label.success_text'],
        'expired_text' => ['string', 'branding', 'settings.label.expired_text'],
        'primary_color' => ['string', 'branding', 'settings.label.primary_color'],
        'accent_color' => ['string', 'branding', 'settings.label.accent_color'],
    ];

    /** Keys whose is_public flag the admin may toggle. */
    private const PUBLICABLE = [
        'currency', 'timezone', 'locale',
        'payment_title', 'payment_help', 'success_text', 'expired_text',
        'primary_color', 'accent_color',
    ];

    private const SECTIONS = ['general', 'payments', 'branding'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $rows = Setting::query()->whereIn('setting_key', array_keys(self::EDITABLE))->get()->keyBy('setting_key');

        $sections = [];
        foreach (self::SECTIONS as $section) { // section labels translate in view
            foreach (self::EDITABLE as $key => [$type, $ownSection, $label]) {
                if ($ownSection === $section) {
                    $row = $rows->get($key);
                    $sections[$section][] = [
                        'key' => $key,
                        'label' => __('cardpay::'.$label),
                        'type' => $type,
                        'value' => $row !== null ? (string) $row->setting_value : '',
                        'is_public' => $row !== null ? (bool) $row->is_public : false,
                        'can_be_public' => in_array($key, self::PUBLICABLE, true),
                    ];
                }
            }
        }

        return view('cardpay::admin.settings', ['sections' => $sections]);
    }

    public function update(Request $request): RedirectResponse
    {
        $changed = [];

        foreach (self::EDITABLE as $key => [$type]) {
            if (! $request->has("settings.$key")) {
                continue;
            }

            $raw = trim((string) $request->input("settings.$key"));

            // Type coercion happens BEFORE persistence; bad values are rejected.
            // filter_var returns int|false — false means "not a valid integer".
            $value = match ($type) {
                'int' => filter_var($raw, FILTER_VALIDATE_INT),
                default => mb_substr($raw, 0, 2000),
            };

            if ($value === false) {
                return back()->with('settings_error', __('Invalid integer for :key.', ['key' => $key]));
            }

            $isPublic = in_array($key, self::PUBLICABLE, true)
                && $request->boolean("public.$key");

            $old = Setting::query()->where('setting_key', $key)->first();
            $before = $old !== null ? ['v' => $old->setting_value, 'pub' => $old->is_public] : null;

            Setting::put($key, $value, $type, $isPublic);

            $after = ['v' => (string) $value, 'pub' => $isPublic];
            if ($before !== $after) {
                $changed[$key] = ['from' => $before, 'to' => $after];
            }
        }

        if ($changed !== []) {
            $this->audit->log('settings.updated', 'admin', $request->user()?->id,
                'settings', null, null, $changed);
        }

        return back()->with('settings_ok', true);
    }
}
