@extends('cardpay::admin.guides.layout', ['title' => 'راهنمای دستگاه اندروید — مخابرهٔ پیامک با امضای HMAC', 'platform' => 'android'])

@section('content')
    <p class="mt-2 max-w-3xl leading-8 text-zinc-600">
        دستگاه اندرویدی (اپ اتوماسیون مانند Tasker، MacroDroid یا اپ اختصاصی) پیامک واریز بانک را بلافاصله پس از رسیدن،
        با امضای رمزنگاری‌شدهٔ HMAC به سرور مخابره می‌کند. این امن‌ترین حالت است: هیچ رمز خامی روی سیم جابه‌جا نمی‌شود و
        هر درخواست ضدِ پخش (anti-replay) محافظت می‌شود.
    </p>

    <div class="mt-6 max-w-3xl panel-card !rounded-2xl">
        <flux:heading size="text-base">گام ۱ — ساخت دستگاه در پنل</flux:heading>
        <ol class="mt-3 list-decimal space-y-2 ps-6 text-sm leading-7">
            <li>به <a href="{{ cardpay_route('devices') }}" class="font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">صفحهٔ دستگاه‌ها</a> بروید و «افزودن دستگاه» را بزنید.</li>
            <li>پلتفرم را <strong>android</strong> انتخاب کنید و دستگاه را به کارت بانکی مرتبط گره بزنید — این دستگاه فقط واریزهای همان کارت را تأیید می‌کند.</li>
            <li><code>device_key</code> و <code>device_secret</code> فقط یک بار نمایش داده می‌شوند؛ هر دو را فوراً یادداشت کنید (secret بعداً قابل بازیابی نیست و برای دیدن دوباره باید چرخش بزنید).</li>
        </ol>
    </div>

    <div class="mt-4 max-w-3xl panel-card !rounded-2xl">
        <flux:heading size="text-base">گام ۲ — امضای هر درخواست در اپ</flux:heading>
        <p class="mt-2 text-sm leading-7">
            هر پیام با متد <code>POST /api/v1/devices/incoming-sms</code> ارسال می‌شود. رشتهٔ متعارف امضا (canonical string) با
            خط جدید (LF) بین فیلدها ساخته می‌شود — <strong>ترتیب و فرمت دقیق حیاتی است</strong>:
        </p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-6 text-zinc-100"><code>METHOD          ← POST (بزرگ)
PATH            ← /api/v1/devices/incoming-sms
RAW_QUERY       ← رشتهٔ کوئری خام (معمولاً خالی)
SHA256(RAW_BODY)← هگزِ sha256 متنِ دقیقِ بدنه
UNIX_TS         ← ثانیه‌های یونیکس (لحظهٔ امضا)
NONCE           ← رشتهٔ یکتا ۱۲ تا ۱۹۰ کاراکتر (برای هر درخواست جدید)</code></pre>
        <p class="mt-3 text-sm leading-7">
            سپس امضا: <code>signature = HMAC_SHA256(canonical_string, device_secret)</code> به‌صورت هگز کوچک.
            چهار هدر روی درخواست بگذارید:
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-6 text-zinc-100"><code>X-Device-Key:       همان device_key پنل
X-Device-Timestamp: همان UNIX_TS
X-Device-Nonce:     همان NONCE
X-Device-Signature: امضای محاسبه‌شده
Content-Type:       application/json</code></pre>
        <p class="mt-3 text-sm leading-7">نمونهٔ بدنهٔ درخواست:</p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-6 text-zinc-100"><code>{
  "message_id":  "sms-8812",              ← یکتا برای هر پیام (کلید حذف تکرار)
  "sender":      "+98700077",              ← شمارهٔ فرستندهٔ پیامک بانک
  "received_at": "2026-08-25T15:35:10+03:30",
  "raw_sms":     "بانک سامان واریز مبلغ 250,417 ریال ..."
}</code></pre>
        <p class="mt-3 text-sm leading-7">
            نمونهٔ امضا در PHP (منطق مشابه در هر زبانی):
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-6 text-zinc-100"><code>$canonical = implode("\n", [
    'POST', '/api/v1/devices/incoming-sms', '',
    hash('sha256', $rawBody), (string) time(), $nonce,
]);
$signature = hash_hmac('sha256', $canonical, $deviceSecret);</code></pre>
    </div>

    <div class="mt-4 max-w-3xl panel-card !rounded-2xl">
        <flux:heading size="text-base">گام ۳ — تست و سلامت دستگاه</flux:heading>
        <ol class="mt-3 list-decimal space-y-2 ps-6 text-sm leading-7">
            <li>یک پرداخت آزمایشی بسازید و مبلغ دقیق آن را به کارت واریز کنید؛ پیامک باید ظرف چند ثانیه پرداخت را تأیید کند (در پنل: لاگ پیامک → matched).</li>
            <li>در صفحهٔ دستگاه‌ها، ستون شمار پیامک‌ها و «آخرین فعالیت» باید در حال رشد باشد.</li>
            <li>اگر رمز دستگاه جایی لو رفت، همان‌جا «چرخش» بزنید: جفت جدید صادر و قدیمی بلافاصله بی‌اعتبار می‌شود؛ اپ را با مقادیر جدید به‌روزرسانی کنید.</li>
            <li>گوشی گم‌شده یا بازنشده را فوراً «ابطال» کنید — ابطال دائمی است.</li>
        </ol>
    </div>

    <div class="mt-4 max-w-3xl rounded-lg border border-teal-200 bg-teal-50 p-5 rounded-2xl">
        <flux:heading size="text-base" class="text-teal-800">عیب‌یابی</flux:heading>
        <ul class="mt-3 list-disc space-y-2 ps-6 text-sm leading-7 text-teal-900">
            <li><strong>401 invalid_device_key</strong> — کلید اشتباه است یا دستگاه باطل/غیرفعال شده؛ صفحهٔ دستگاه‌ها را چک کنید.</li>
            <li><strong>401 invalid_device_signature</strong> — رشتهٔ متعارف دقیقاً مطابقت ندارد: جداکنندهٔ LF، ترتیب فیلدها، هشِ بدنهٔ خام (نه JSON قالب‌بندی‌شده)، یا اختلاف ساعت بیش از ۵ دقیقه بین گوشی و سرور. ساعت گوشی را روی «خودکار» بگذارید.</li>
            <li><strong>تکرار پیام‌ها</strong> — message_id باید برای هر پیام یکتا باشد؛ پیام تکراری با همان id فقط پاسخ اولیه را برمی‌گرداند (duplicate=true) و دوباره پردازش نمی‌شود.</li>
            <li><strong>پرداخت تأیید نمی‌شود</strong> — پیامک در لاگ با چه وضعیتی ثبت شده؟ ignored (کلیدواژه/فرستنده)، failed (الگوی مبلغ)، یا unmatched (مبلغ با هیچ سفارش بازی یکی نبوده). راهنمای «تجزیه‌گر پیامک» را ببینید.</li>
        </ul>
    </div>
@endsection
