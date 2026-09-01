{{-- Hosted checkout (§FR-8): Persian / RTL, self-contained, strict-CSP safe. --}}
{{-- The checkout JS is served by the package asset route (/vendor/cardpay/checkout.js) — no inline scripts, no CSP hash pinning. --}}
{{-- The destination PAN is rendered contiguous in the HTML source (tests assert the raw string); JS groups it for display only. --}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $branding['title'] }}</title>
<meta name="robots" content="noindex, nofollow">
<style>
    /* Self-hosted Vazirmatn (Persian/Arabic subset) — same-origin, CSP font-src 'self' safe. */
    @font-face {
        font-family: 'Vazirmatn';
        font-style: normal;
        font-weight: 400;
        font-display: swap;
        src: url('/fonts/vazirmatn-arabic-400-normal.woff2') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+FB50-FDFF, U+FE70-FEFC, U+200C-200E, U+2010-2011;
    }
    @font-face {
        font-family: 'Vazirmatn';
        font-style: normal;
        font-weight: 500;
        font-display: swap;
        src: url('/fonts/vazirmatn-arabic-500-normal.woff2') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+FB50-FDFF, U+FE70-FEFC, U+200C-200E, U+2010-2011;
    }
    @font-face {
        font-family: 'Vazirmatn';
        font-style: normal;
        font-weight: 700;
        font-display: swap;
        src: url('/fonts/vazirmatn-arabic-700-normal.woff2') format('woff2');
        unicode-range: U+0600-06FF, U+0750-077F, U+FB50-FDFF, U+FE70-FEFC, U+200C-200E, U+2010-2011;
    }

    /*
      Derived color system: every tone comes from the merchant-configured
      primary/accent via color-mix(), so any brand re-colors the whole page.
      Static fallbacks precede each color-mix for older engines. Status colors
      (success/warn/danger) stay fixed — they are signals, not branding.
    */
    :root {
        --primary: {{ $branding['primary'] }};
        --accent: {{ $branding['accent'] }};
        --ink: #1c2624;
        --muted: #5a6b69;
        --faint: #7d8c8a;
        --line: #dbe4e3;
        --line: color-mix(in oklab, var(--primary) 16%, #dbe4e3);
        --surface: #f7fafa;
        --surface: color-mix(in oklab, var(--primary) 5%, #ffffff);
        --bg: #f3f7f6;
        --bg: color-mix(in oklab, var(--primary) 6%, #f2f6f5);
        --ring: rgba(13, 148, 136, .18);
        --ring: color-mix(in srgb, var(--primary) 18%, transparent);
        --ok: #067a55;
        --ok-bg: #e7f7f0;
        --bad: #991b1b;
        --bad-bg: #fdeaea;
        --radius: 14px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: Vazirmatn, Tahoma, "Segoe UI", sans-serif;
        background: var(--bg);
        color: var(--ink);
        direction: rtl;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 16px;
        line-height: 1.7;
    }

    .panel {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 22px;
        max-width: 480px;
        width: 100%;
        padding: 30px 26px;
        box-shadow: 0 12px 44px rgba(15, 42, 40, .09);
    }

    .icon { flex-shrink: 0; }

    /* ---- Header ---- */
    .secure-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: .76rem;
        font-weight: 500;
        color: var(--primary);
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 999px;
        padding: 3px 12px;
        margin-bottom: 12px;
    }
    .page-title { font-size: 1.08rem; font-weight: 700; }

    /* ---- Amount hero ---- */
    .amount-block { margin-top: 20px; text-align: center; }
    .amount-label { font-size: .82rem; color: var(--muted); }
    .amount-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin-top: 4px;
    }
    .amount {
        font-size: 2.05rem;
        font-weight: 700;
        color: var(--primary);
        letter-spacing: .3px;
    }
    .amount-unit { font-size: .85rem; color: var(--muted); font-weight: 500; }
    .token-note {
        margin-top: 4px;
        font-size: .76rem;
        color: var(--faint);
    }

    .mini-copy {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-family: inherit;
        font-size: .76rem;
        font-weight: 500;
        color: var(--primary);
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 9px;
        padding: 6px 11px;
        cursor: pointer;
        transition: background .15s;
    }
    .mini-copy:hover { background: var(--bg); }
    .mini-copy .txt-done { display: none; }
    .mini-copy.copied { color: var(--ok); border-color: #b5e3d2; background: var(--ok-bg); }
    .mini-copy.copied .txt-copy { display: none; }
    .mini-copy.copied .txt-done { display: inline; }

    /* ---- Card face (signature element) ---- */
    .card-face {
        position: relative;
        overflow: hidden;
        margin-top: 18px;
        border-radius: 18px;
        padding: 16px 20px;
        min-height: 172px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        color: #fff;
        background: var(--primary);
        background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25), 0 10px 26px color-mix(in srgb, var(--primary) 30%, transparent);
    }
    .card-face::after {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(120% 90% at 85% -10%, rgba(255, 255, 255, .22), transparent 55%);
        pointer-events: none;
    }
    .card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        z-index: 1;
    }
    .card-bank { font-size: .92rem; font-weight: 700; }
    .card-kind {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: .72rem;
        opacity: .85;
    }
    .card-mid {
        display: flex;
        align-items: center;
        gap: 14px;
        position: relative;
        z-index: 1;
    }
    .card-pan {
        font-size: clamp(1.05rem, 4.6vw, 1.3rem);
        font-weight: 700;
        letter-spacing: .16em;
        direction: ltr;
        unicode-bidi: embed;
        white-space: nowrap;
    }
    .card-bottom {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 10px;
        position: relative;
        z-index: 1;
    }
    .card-holder-label { font-size: .64rem; opacity: .75; display: block; }
    .card-holder { font-size: .86rem; font-weight: 500; }
    .copy-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: inherit;
        font-size: .76rem;
        font-weight: 500;
        color: #fff;
        background: rgba(255, 255, 255, .17);
        border: 1px solid rgba(255, 255, 255, .42);
        border-radius: 9px;
        padding: 6px 11px;
        cursor: pointer;
        transition: background .15s;
    }
    .copy-btn:hover { background: rgba(255, 255, 255, .27); }
    .copy-btn .i-check { display: none; }
    .copy-btn.copied { background: rgba(6, 122, 85, .45); border-color: rgba(255, 255, 255, .6); }
    .copy-btn.copied .i-copy { display: none; }
    .copy-btn.copied .i-check { display: inline; }

    /* ---- Countdown ---- */
    .timer { margin-top: 20px; }
    .timer-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: .84rem;
        color: var(--muted);
        margin-bottom: 7px;
    }
    .timer-row time { font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; }
    .timer-bar {
        height: 6px;
        border-radius: 999px;
        background: var(--surface);
        border: 1px solid var(--line);
        overflow: hidden;
    }
    .timer-fill {
        height: 100%;
        width: 100%;
        border-radius: inherit;
        background: var(--primary);
        background: linear-gradient(90deg, var(--primary), var(--accent));
        transition: width 1s linear;
    }
    .timer.warn time { color: #dc2626; }
    .timer.warn .timer-fill { background: #dc2626; }

    /* ---- Steps ---- */
    .steps {
        margin-top: 20px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--surface);
        padding: 14px 16px;
        display: grid;
        gap: 11px;
    }
    .step { display: flex; align-items: baseline; gap: 10px; font-size: .84rem; color: #33413f; }
    .step-num {
        flex-shrink: 0;
        width: 21px;
        height: 21px;
        border-radius: 50%;
        background: var(--primary);
        color: #fff;
        font-size: .72rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transform: translateY(3px);
    }

    /* ---- Live status ---- */
    .status {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 1.7em;
        margin-top: 18px;
        font-size: .92rem;
        font-weight: 500;
    }
    .status .dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        background: var(--primary);
        animation: pulse 1.7s ease-in-out infinite;
    }
    .status.is-review .dot { background: #d97706; }
    .status.is-paid { color: var(--ok); font-weight: 700; }
    .status.is-waiting { color: var(--muted); }

    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: .3; transform: scale(.75); }
    }

    /* ---- Banners ---- */
    .banner {
        display: flex;
        align-items: center;
        gap: 9px;
        border-radius: 12px;
        padding: 11px 14px;
        font-size: .87rem;
        font-weight: 500;
        margin-top: 18px;
    }
    .banner.ok { background: var(--ok-bg); color: var(--ok); }
    .banner.bad { background: var(--bad-bg); color: var(--bad); }
    .banner.neutral { background: var(--surface); color: var(--muted); border: 1px solid var(--line); }

    /* ---- Terminal / success states ---- */
    .state-hero { text-align: center; padding: 26px 0 6px; }
    .state-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
    }
    .state-icon.ok { background: var(--ok-bg); color: var(--ok); }
    .state-icon.bad { background: var(--bad-bg); color: #dc2626; }
    .state-title { font-size: 1.05rem; font-weight: 700; color: var(--ink); }
    .state-sub { font-size: .84rem; color: var(--muted); margin-top: 6px; }

    /* ---- Report form ---- */
    .report { margin-top: 20px; border-top: 1px dashed var(--line); padding-top: 4px; }
    .report summary {
        list-style: none;
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: .84rem;
        font-weight: 500;
        color: var(--primary);
        cursor: pointer;
        padding: 9px 0;
    }
    .report summary::-webkit-details-marker { display: none; }
    .report summary .chev { transition: transform .2s; }
    .report[open] summary .chev { transform: rotate(180deg); }
    .report-form { display: grid; gap: 9px; padding-bottom: 8px; }
    .input {
        width: 100%;
        padding: 9px 12px;
        font-family: inherit;
        font-size: .86rem;
        color: var(--ink);
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 10px;
    }
    .input::placeholder { color: var(--faint); }
    .input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--ring); }
    .file-note { font-size: .72rem; color: var(--faint); }
    .btn-primary {
        background: var(--primary);
        color: #fff;
        border: 0;
        border-radius: 10px;
        padding: 10px;
        font-family: inherit;
        font-size: .88rem;
        font-weight: 700;
        cursor: pointer;
        transition: filter .15s;
    }
    .btn-primary:hover { filter: brightness(1.07); }
    .btn-primary:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }

    /* ---- Help + footer ---- */
    .help {
        font-size: .8rem;
        color: var(--muted);
        margin-top: 20px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: 11px 14px;
    }
    .foot {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin-top: 18px;
        font-size: .73rem;
        color: var(--faint);
    }

    :is(button, summary, input, a):focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: .01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: .01ms !important;
        }
    }
</style>
</head>
<body>
@php($state = $payment->status->value)
<main class="panel"
      data-state="{{ match($state) { 'paid' => 'paid', 'canceled', 'rejected' => 'terminal', 'expired' => 'expired', 'manual_review' => 'review', default => 'pending' } }}"
      data-public-id="{{ $payment->public_id }}"
      data-expires-in="{{ $expiresInSeconds }}"
      data-return-url="{{ $payment->return_url ?? '' }}">

    @if($state === 'paid')
        <div class="secure-chip">
            <svg class="icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l2 2 3.5-3.5"/></svg>
            پرداخت امن کارت به کارت
        </div>
        <div class="state-hero">
            <span class="state-icon ok">
                <svg class="icon" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>
            </span>
            <h1 class="state-title">{{ $branding['success'] }}</h1>
            @if($payment->return_url)
                <p class="state-sub">در حال بازگشت به سایت پذیرنده…</p>
            @endif
        </div>
    @elseif(in_array($state, ['canceled', 'rejected'], true))
        <div class="secure-chip">
            <svg class="icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l2 2 3.5-3.5"/></svg>
            پرداخت امن کارت به کارت
        </div>
        <div class="state-hero">
            <span class="state-icon bad">
                <svg class="icon" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            </span>
            <h1 class="state-title">{{ $branding['expired'] }}</h1>
            <p class="state-sub">برای پیگیری با پذیرنده خود در تماس باشید.</p>
        </div>
    @else
        <div class="secure-chip">
            <svg class="icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9.5 12l2 2 3.5-3.5"/></svg>
            پرداخت امن کارت به کارت
        </div>
        <h1 class="page-title">{{ $branding['title'] }}</h1>

        @if(request('review') === 'submitted')
            <div class="banner ok">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>
                گزارش شما ثبت شد و در حال بررسی است.
            </div>
        @elseif(request('review') === 'error')
            @php($reason = (string) request('reason'))
            <div class="banner bad">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/></svg>
                @if($reason === 'payment_not_reviewable')
                    این پرداخت دیگر قابل پیگیری نیست.
                @elseif($reason === 'validation_failed')
                    اطلاعات وارد شده معتبر نیست؛ دوباره بررسی کنید.
                @elseif($reason === 'upload_too_large')
                    حجم فایل رسید بیش از حد مجاز است.
                @elseif($reason === 'invalid_upload')
                    فرمت فایل رسید پشتیبانی نمی‌شود (JPG، PNG یا PDF).
                @elseif($reason === 'upload_failed')
                    ذخیره فایل رسید ناموفق بود؛ دوباره تلاش کنید.
                @elseif($reason === 'rate_limit_exceeded')
                    تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد تلاش کنید.
                @else
                    ارسال گزارش ناموفق بود؛ دوباره تلاش کنید.
                @endif
            </div>
        @endif

        <div class="amount-block">
            <div class="amount-label">مبلغ قابل پرداخت (ریال)</div>
            <div class="amount-row">
                <span class="amount" id="amount">{{ number_format($payment->payable_amount) }}</span>
                <button type="button" class="mini-copy" data-copy="{{ $payment->payable_amount }}">
                    <svg class="icon i-copy" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>
                    <svg class="icon i-check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>
                    <span class="txt-copy">کپی مبلغ</span>
                    <span class="txt-done">کپی شد</span>
                </button>
            </div>
            <p class="token-note">مبلغ دقیقاً به همین شکل واریز شود — رقم انتهایی برای تشخیص خودکار درج شده است.</p>
        </div>

        <figure class="card-face">
            <figcaption class="card-top">
                <span class="card-bank">{{ $card?->bank_name }}@if($card && $card->title && $card->title !== $card->bank_name) — {{ $card->title }}@endif</span>
                <span class="card-kind">
                    <svg class="icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    کارت مقصد
                </span>
            </figcaption>
            <div class="card-mid">
                <svg width="40" height="29" viewBox="0 0 40 29" aria-hidden="true"><rect x="1" y="1" width="38" height="27" rx="5" fill="rgba(255,255,255,.85)"/><path d="M1 10h38M1 19h38M14 1v27M26 1v27" stroke="rgba(120,90,20,.4)" stroke-width="1.4" fill="none"/></svg>
                <span class="card-pan" id="card-pan">{{ $cardNumber }}</span>
            </div>
            <div class="card-bottom">
                <span>
                    <span class="card-holder-label">به نام</span>
                    <span class="card-holder">{{ $cardHolder }}</span>
                </span>
                <button type="button" class="copy-btn" data-copy="{{ $cardNumber }}">
                    <svg class="icon i-copy" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/></svg>
                    <svg class="icon i-check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>
                    کپی شماره
                </button>
            </div>
        </figure>

        @if($state === 'pending' && $expiresInSeconds > 0)
            <div class="timer" id="timer">
                <div class="timer-row">
                    <span>مهلت پرداخت</span>
                    <time id="timer-value">--:--</time>
                </div>
                <div class="timer-bar"><div class="timer-fill" id="timer-fill"></div></div>
            </div>
        @elseif($state === 'expired')
            <div class="banner bad" style="margin-top:18px">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/></svg>
                {{ $branding['expired'] }} اگر پیش از پایان مهلت واریز کرده‌اید، گزارش دهید.
            </div>
        @else
            <div class="banner neutral" style="margin-top:18px">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                پرداخت شما در حال بررسی دستی است؛ پس از تأیید به‌صورت خودکار به سایت پذیرنده بازگردانده می‌شوید.
            </div>
        @endif

        @if(in_array($state, ['pending', 'review'], true))
            <p class="status is-waiting" id="status">
                <span class="dot" aria-hidden="true"></span>
                <span id="status-text">{{ $state === 'review' ? 'در حال بررسی دستی…' : 'در انتظار تأیید پرداخت…' }}</span>
            </p>
        @endif

        @if($state === 'pending')
            <div class="steps">
                <div class="step"><span class="step-num">۱</span><span>مبلغ و شماره کارت را کپی کنید و مبلغ را <b>دقیقاً</b> واریز کنید.</span></div>
                <div class="step"><span class="step-num">۲</span><span>واریز به‌صورت خودکار تشخیص داده می‌شود — معمولاً کمتر از یک دقیقه.</span></div>
                <div class="step"><span class="step-num">۳</span><span>پس از تأیید، به‌صورت خودکار به سایت پذیرنده بازمی‌گردید.</span></div>
            </div>
        @endif

        @if(in_array($state, ['pending', 'expired'], true))
            <details class="report">
                <summary>
                    <svg class="icon chev" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                    پرداخت کرده‌ام اما تأیید نشده؟ گزارش مشکل
                </summary>
                <form method="POST" action="{{ url('/p/'.$payment->public_id.'/manual-review') }}"
                      enctype="multipart/form-data" class="report-form">
                    @csrf
                    <input type="number" name="reported_amount" placeholder="مبلغ پرداختی (ریال)" min="1" step="1" inputmode="numeric" class="input" aria-label="مبلغ پرداختی (ریال)">
                    <input type="datetime-local" name="approximate_paid_at" class="input" aria-label="زمان تقریبی واریز">
                    <input type="text" name="contact_mobile" placeholder="شماره تماس (اختیاری)" inputmode="tel" class="input" aria-label="شماره تماس (اختیاری)">
                    <textarea name="customer_note" rows="2" placeholder="توضیحات (اختیاری)" class="input" aria-label="توضیحات (اختیاری)"></textarea>
                    <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf" class="input" aria-label="فایل رسید">
                    <span class="file-note">رسید: JPG، PNG یا PDF — حداکثر ۵ مگابایت</span>
                    <button type="submit" class="btn-primary">ارسال گزارش</button>
                </form>
            </details>
        @endif
    @endif

    @if($branding['help'] !== '')
        <p class="help">{{ $branding['help'] }}</p>
    @endif

    <p class="foot">
        <svg class="icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
        تأیید خودکار واریزی — بدون نیاز به تماس
    </p>
</main>

<script src="/vendor/cardpay/checkout.js" defer></script>
</body>
</html>
