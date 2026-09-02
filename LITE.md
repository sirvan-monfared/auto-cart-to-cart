# CardPay — Lite edition

Lite is the **single-shop, API-only** build of the same gateway. It is not a
cut-down engine: automatic card-to-card matching, the amount-token allocator,
idempotency, webhooks, manual review, and the hosted checkout are all identical
to full. What it drops is the part you do not need when you are running one
store: the bundled admin panel and the multi-merchant surface around it.

You drive it from **your own admin**, in your own theme, over a JSON API.

```
CARDPAY_EDITION=lite
```

---

## What changes

| | full | lite |
|---|---|---|
| Bundled Blade/Livewire panel | yes | **no** |
| Browser setup wizard | yes | **no** — `php artisan cardpay:install` |
| `cp_audit_logs` table | yes | **not created** |
| `cp_settings` table | yes | **not created** — settings come from config |
| Multi-application CRUD | yes | **no** — exactly one gateway |
| Admin JSON API | yes | yes (the only admin surface) |
| Hosted checkout `/p/{id}` | yes | yes |
| Merchant HMAC API | yes | yes |
| Device SMS relay | yes | yes |
| Manual review + receipts | yes | yes |
| Webhooks | yes | yes |

Lite creates **16 tables instead of 18**. The rest are load-bearing: removing
them would remove automatic matching, not bloat.

### Feature flags

The edition only picks defaults. Any individual feature can be forced either
way, so you are never stuck between two fixed bundles:

```env
CARDPAY_EDITION=lite
CARDPAY_FEATURE_AUDIT=true       # lite, but keep the audit trail
CARDPAY_FEATURE_MERCHANT_API=false  # in-process only, no HMAC surface
```

```php
CartBecart\CardPay\Support\Edition::isLite();
CartBecart\CardPay\Support\Edition::enabled('panel');
cardpay_feature('admin_api');
```

Turning a feature back on later is safe: `cp_audit_logs` and `cp_settings` live
in their own migration, so re-enabling the feature makes it pending and a plain
`php artisan migrate` creates the table.

---

## Install

```bash
composer require sirvan-monfared/auto-card-to-card
php artisan vendor:publish --tag=cardpay-config
# set CARDPAY_EDITION=lite in .env
php artisan cardpay:install
```

`cardpay:install` migrates, seeds the single gateway application, and prints its
API credentials **once**. Store them then — the secret is stored encrypted with
only a fingerprint for verification, so nothing can read it back.

Lost it? `php artisan cardpay:api-key:rotate` mints a new pair and revokes the
old one immediately.

Two manual steps the installer cannot do for you:

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    CartBecart\CardPay\Http\ApiExceptionRenderer::configure($exceptions);
})
```

```php
// AppServiceProvider::boot() — who may administer the gateway
Gate::define('cardpay.access', fn ($user) => $user->isAdmin());
```

---

## Taking payments

Do **not** sign HMAC requests against your own application. Use the facade: it
resolves the single gateway implicitly, so your code never mentions
`application_id`.

```php
use CartBecart\CardPay\Facades\CardPay;

$payment = CardPay::createPayment([
    'amount' => 250_000,                  // minor units, integer
    'external_order_id' => (string) $order->id,
    'description' => "Order #{$order->id}",
    'customer' => ['name' => $order->name, 'mobile' => $order->mobile],
    'return_url' => route('orders.show', $order),
], idempotencyKey: "order-{$order->id}");

return redirect($payment['payment_url']);
```

`$payment` is the same presentment the HTTP API returns:

```php
[
  'payment_id'      => 'PAY01J…',
  'status'          => 'pending',
  'original_amount' => 250000,
  'token'           => 417,        // the disambiguator (§A1)
  'payable_amount'  => 250417,     // what the customer must transfer EXACTLY
  'payment_url'     => 'https://shop.test/p/PAY01J…',
  'expires_at'      => '2026-09-02T12:30:00+00:00',
  'idempotent_replay' => false,
]
```

Other calls:

```php
CardPay::status($publicId);      // array, same shape
CardPay::isPaid($publicId);      // bool
CardPay::find($publicId);        // Payment model
CardPay::cancel($publicId);      // customer abandoned the order
CardPay::checkoutUrl($publicId);
```

### Idempotency

Pass a key derived from your order. Repeating the call replays the original
response instead of allocating a second amount token:

```php
CardPay::createPayment([...], idempotencyKey: "order-{$order->id}");
```

Keys are namespaced to `cardpay:<your-key>` internally — that satisfies the
ledger's length rule (so short keys like `order-7` just work) and keeps
in-process keys from colliding with HTTP ones. Omitting the key generates a
random one, which is fine for a user-initiated checkout and **wrong for a
retryable job**.

### Transactions

Because it is in-process, a payment can be created in the same transaction as
the order it belongs to — a rollback leaves nothing behind:

```php
DB::transaction(function () use ($cart) {
    $order = Order::create([...]);
    $payment = CardPay::createPayment(['amount' => $cart->total()],
        idempotencyKey: "order-{$order->id}");
    $order->update(['payment_id' => $payment['payment_id']]);
});
```

### Knowing when it is paid

Use webhooks (set the URL via the admin API below), or poll `CardPay::isPaid()`
from your order page. The customer-facing checkout page already polls
`/api/v1/public/payments/{id}/status` on its own.

---

## The Admin JSON API

Mounted at `config('cardpay.admin_api.prefix')`, default
**`/api/cardpay/admin`**.

Authorization is the default `['web', 'auth', 'cardpay.access']` stack: the
host session plus the **same Gate the panel uses**. Override `cardpay.access`
once and every surface follows. For a separate frontend, swap the middleware:

```php
'admin_api' => [
    'prefix' => 'api/cardpay/admin',
    'middleware' => ['api', 'auth:sanctum', 'cardpay.access'],
],
```

Merchant HMAC credentials grant **nothing** here — they authorize creating
payments, not administering the gateway.

Every response uses the same envelope as the merchant API:

```json
{ "success": true, "data": { … } }
{ "success": true, "data": [ … ], "meta": { "current_page": 1, "last_page": 3, "per_page": 25, "total": 61 } }
{ "success": false, "error": { "code": "payment_not_found", "message": "…", "details": {} } }
```

### Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `overview` | Dashboard counters |
| GET | `features` | Edition + resolved feature map |
| GET / PUT | `gateway` | The single application: webhook URL, allow-list, token width, expiry, default card |
| POST | `gateway/rotate-api-key` | New merchant secret (revealed once) |
| GET | `payments` | List. Filters: `status`, `external_order_id`, `q`, `from`, `to`, `per_page` |
| GET | `payments/{publicId}` | Detail + SMS evidence + reviews + webhook history |
| GET | `reviews` | Queue. `?status=pending\|approved\|rejected` |
| POST | `reviews/{id}/approve` | Settle. Body: `sms_id?`, `note?` |
| POST | `reviews/{id}/reject` | Reject. Body: `note?` |
| GET | `reviews/{id}/receipt` | Download the customer's receipt (audited) |
| GET POST | `cards` | Destination bank cards |
| GET PUT DELETE | `cards/{id}` | `DELETE` soft-disables; history keeps its card |
| POST | `cards/{id}/activate` | Re-enable |
| GET POST | `devices` | Relay phones / Shortcuts |
| PUT | `devices/{id}` | Rename, re-bind card |
| POST | `devices/{id}/rotate` | New secret (revealed once) |
| POST | `devices/{id}/revoke` | Permanent |
| GET POST | `parsers` | Per-bank SMS rules |
| PUT | `parsers/{id}` | Update |
| POST | `parsers/{id}/test` | Dry-run a regex against sample text |
| GET | `sms` | Relayed SMS log. Filters: `match_status`, `parse_status`, `device_id`, `from`, `to` |
| GET | `webhooks` | Events with deliveries |
| GET | `webhooks/deliveries` | Deliveries. `?status=failed` |
| POST | `webhooks/deliveries/{id}/retry` | Force a retry |
| GET | `reports` | Aggregates over a date window |
| GET | `reports/csv` | Streamed CSV export |

Payments are deliberately **read-only**. Settling by hand goes through the
review queue so the state machine, token release, and webhook emission all
still run — an endpoint that patched `status` directly would skip them.

### Secrets

Nothing is readable after the fact. Device and API secrets appear exactly once,
in the response that mints them. Card PANs are excluded from listings and
served only from `GET cards/{id}`.

### Example

```js
const res = await fetch('/api/cardpay/admin/reviews?status=pending', {
  headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
});
const { data, meta } = await res.json();
```

Start from `GET features` and render your menu from the returned map rather
than hardcoding what the edition exposes.

---

## Settings without a settings table

Lite reads checkout branding from config instead of `cp_settings`:

```env
CARDPAY_PAYMENT_TITLE="پرداخت امن کارت به کارت"
CARDPAY_PRIMARY_COLOR="#155EEF"
CARDPAY_ACCENT_COLOR="#12B76A"
CARDPAY_SUCCESS_TEXT="پرداخت شما با موفقیت تأیید شد."
```

See the `settings` block in `config/cardpay.php` for every key. `Setting::get()`
resolves from whichever source is active, so the checkout page works unchanged
in both editions.

---

## Going from lite to full

Set `CARDPAY_EDITION=full` and run `php artisan migrate`. The panel-only tables
become pending migrations and are created; the panel routes, setup wizard, and
Livewire pages register. No data migration, no reinstall — the core schema was
never different.
