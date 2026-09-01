<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Per-platform onboarding guides for trusted relay devices (§FR-5/§FR-9):
 * the Android automation route (full HMAC signing) and the iOS Shortcut
 * route (static key+secret). Persian RTL pages an operator can follow — or
 * share with whoever owns the relay phone — while pairing a device.
 */
final class DeviceGuideController extends Controller
{
    public function android(): View
    {
        return view('cardpay::admin.guides.android');
    }

    public function ios(): View
    {
        return view('cardpay::admin.guides.ios-shortcut');
    }
}
