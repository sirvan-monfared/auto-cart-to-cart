(function () {
    'use strict';

    var panel = document.querySelector('.panel');
    if (!panel) { return; }

    var state = panel.getAttribute('data-state');
    var publicId = panel.getAttribute('data-public-id');
    var returnUrl = panel.getAttribute('data-return-url') || '';
    var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

    function faDigits(value) {
        return String(value).replace(/[0-9]/g, function (d) { return FA_DIGITS[+d]; });
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    // --- Copy buttons (clipboard API with a non-secure-origin fallback) ---
    function legacyCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        document.body.removeChild(ta);
        return ok;
    }

    function bindCopy(btn) {
        var text = btn.getAttribute('data-copy');
        if (!text) { return; }
        btn.addEventListener('click', function () {
            var done = function () {
                btn.classList.add('copied');
                setTimeout(function () { btn.classList.remove('copied'); }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    if (legacyCopy(text)) { done(); }
                });
            } else if (legacyCopy(text)) {
                done();
            }
        });
    }

    var copyButtons = document.querySelectorAll('[data-copy]');
    for (var c = 0; c < copyButtons.length; c++) { bindCopy(copyButtons[c]); }

    // --- Display grouping for the PAN (source HTML stays contiguous) ---
    var panEl = document.getElementById('card-pan');
    if (panEl) {
        panEl.textContent = panEl.textContent.replace(/(\d{4})(?=\d)/g, '$1 ');
    }

    // --- Countdown: Persian digits, drains toward zero, red under 5 min ---
    var timerEl = document.getElementById('timer');
    var timeEl = document.getElementById('timer-value');
    var fillEl = document.getElementById('timer-fill');
    var remaining = parseInt(panel.getAttribute('data-expires-in'), 10) || 0;
    var total = remaining;

    function renderTimer() {
        if (!timeEl) { return; }
        var h = Math.floor(remaining / 3600);
        var m = Math.floor((remaining % 3600) / 60);
        var s = remaining % 60;
        var text = h > 0 ? h + ':' + pad2(m) + ':' + pad2(s) : pad2(m) + ':' + pad2(s);
        timeEl.textContent = faDigits(text);
        if (fillEl && total > 0) {
            fillEl.style.width = Math.max(0, Math.round((remaining / total) * 100)) + '%';
        }
        if (remaining < 300 && timerEl) { timerEl.classList.add('warn'); }
    }

    function tick() {
        if (!timerEl) { return; }
        if (remaining <= 0) {
            remaining = 0;
            renderTimer();
            return; // the status poller flips the page when expiry lands server-side
        }
        renderTimer();
        remaining -= 1;
        setTimeout(tick, 1000);
    }

    // --- Status polling with capped exponential backoff (2s → 15s) ---
    var statusEl = document.getElementById('status');
    var statusText = document.getElementById('status-text');
    var delay = 2000;
    var MAX_DELAY = 15000;
    var reviewAnnounced = false;

    function setStatus(kind, text) {
        if (!statusEl || !statusText) { return; }
        statusEl.className = 'status ' + kind;
        statusText.textContent = text;
    }

    function isTerminal(status) {
        return status === 'paid' || status === 'expired' || status === 'canceled' || status === 'rejected';
    }

    function redirectAfterPayment() {
        setTimeout(function () {
            if (returnUrl) { window.location.href = returnUrl; } else { window.location.reload(); }
        }, 1400);
    }

    function poll() {
        fetch('/api/v1/public/payments/' + encodeURIComponent(publicId) + '/status', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (res) {
            if (!res.ok) { throw new Error('http_' + res.status); }
            return res.json();
        }).then(function (body) {
            var data = body && body.data ? body.data : null;
            if (!data) { throw new Error('bad_payload'); }

            if (data.status === 'paid') {
                setStatus('is-paid', 'پرداخت تأیید شد.');
                redirectAfterPayment();
                return;
            }
            if (isTerminal(data.status)) {
                window.location.reload();
                return;
            }
            if (data.status === 'manual_review' && !reviewAnnounced) {
                reviewAnnounced = true;
                setStatus('is-review', 'پرداخت شما در حال بررسی دستی است.');
            }
            setTimeout(poll, delay);
            delay = Math.min(delay * 2, MAX_DELAY);
        }).catch(function () {
            // Transient network/API trouble: back off and keep trying silently.
            setTimeout(poll, delay);
            delay = Math.min(delay * 2, MAX_DELAY);
        });
    }

    if (state === 'paid') {
        if (returnUrl) {
            setTimeout(function () { window.location.href = returnUrl; }, 1500);
        }
        return;
    }

    if (state === 'pending' || state === 'review') {
        if (state === 'pending') { tick(); }
        setStatus(state === 'review' ? 'is-review' : 'is-waiting',
            state === 'review' ? 'در حال بررسی دستی…' : 'در انتظار تأیید پرداخت…');
        setTimeout(poll, delay);
    }
    // State 'expired' or 'terminal': the page is final; no JS activity.
})();
