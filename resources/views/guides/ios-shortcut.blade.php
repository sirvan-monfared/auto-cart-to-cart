@extends('cardpay::admin.guides.layout', ['title' => 'راهنمای میان‌بر iOS — مخابرهٔ پیامک با کلید و رمز ثابت', 'platform' => 'ios'])

@section('content')
    <p class="mt-2 max-w-3xl leading-8 text-zinc-600">
        iOS Shortcuts نمی‌توانند امضای HMAC بسازند؛ به همین دلیل حالت میان‌بر (shortcut mode) با یک جفت
        کلید + رمز ثابت کار می‌کند. سرور رمز را با مقایسهٔ اثر SHA-256 در زمان ثابت بررسی می‌کند و رمز خام هرگز
        ذخیره نمی‌شود. این حالت ساده‌تر است اما سطح حفاظت کمتری از امضای HMAC اندروید دارد — برای گوشی‌های
        شخصیِ تحت کنترل شما مناسب است.
    </p>

    <div class="mt-6 max-w-3xl panel-card !rounded-2xl">
        <flux:heading size="text-base">گام ۱ — ساخت دستگاه در پنل</flux:heading>
        <ol class="mt-3 list-decimal space-y-2 ps-6 text-sm leading-7">
            <li>به <a href="{{ route('admin.devices') }}" class="font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">صفحهٔ دستگاه‌ها</a> بروید و «افزودن دستگاه» را بزنید.</li>
            <li>پلتفرم را <strong>ios-shortcut</strong> انتخاب کنید و دستگاه را به کارت بانکی مرتبط گره بزنید.</li>
            <li><code>device_key</code> و <code>device_secret</code> فقط یک بار نمایش داده می‌شوند — فوراً ذخیره کنید.</li>
        </ol>
    </div>

    <div class="mt-4 max-w-3xl panel-card !rounded-2xl">
        <flux:heading size="text-base">گام ۲ — ساخت میان‌بر در iOS</flux:heading>
        <ol class="mt-3 list-decimal space-y-2 ps-6 text-sm leading-7">
            <li>در اپ Shortcuts یک Automation جدید بسازید: تریگر «Message received» با فیلتر فرستندهٔ پیامک بانک (مثلاً 98700077).</li>
            <li>اقدام «Get Contents of URL» اضافه کنید:</li>
        </ol>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-6 text-zinc-100"><code>URL:      https://آدرس-سرور/api/v1/devices/shortcut-sms
Method:   POST
Headers:  Content-Type: application/json
          X-Device-Key:    مقدار device_key
          X-Device-Secret: مقدار device_secret
Body (JSON):
{
  "raw_sms": &lt;متن کامل پیام دریافتی&gt;
}</code></pre>
        <p class="mt-3 text-sm leading-7">
            سه فیلد اختیاری‌اند و اگر خالی باشند مقدار پیش‌فرض می‌گیرند:
        </p>
        <ul class="mt-2 list-disc space-y-1 ps-6 text-sm leading-7">
            <li><code>message_id</code> → خودکار <code>ios_</code> + هش محتوای پیامک می‌شود؛ پس ارسال دوبارهٔ همان پیام دوبار تأیید ایجاد نمی‌کند.</li>
            <li><code>sender</code> → پیش‌فرض «iOS Shortcut». اگر تجزیه‌گر کارت شما الگوی فرستنده دارد، شمارهٔ واقعی را بفرستید وگرنه پیام ignored می‌شود.</li>
            <li><code>received_at</code> → پیش‌فرض لحظهٔ رسیدن به سرور.</li>
        </ul>
        <p class="mt-3 text-sm leading-7">
            ارسال اعتبارنامه‌ها در بدنه هم مجاز است (وقتی گذاشتن هدر در میان‌بر سخت است)؛ اگر هر دو باشن، هدر برنده است:
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-xs leading-6 text-zinc-100"><code>{
  "device_key":    "…",
  "device_secret": "…",
  "raw_sms":       "…"
}</code></pre>
    </div>

    <div class="mt-4 max-w-3xl panel-card !rounded-2xl">
        <flux:heading size="text-base">گام ۳ — تست و نگهداری</flux:heading>
        <ol class="mt-3 list-decimal space-y-2 ps-6 text-sm leading-7">
            <li>یک پرداخت آزمایشی بسازید، مبلغ دقیق را واریز کنید و میان‌بر را با «Run» دستی هم می‌توانید اجرا کنید؛ نتیجه باید در <a href="{{ route('admin.sms') }}" class="font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">لاگ پیامک</a> با match=matched دیده شود.</li>
            <li>پاسخ موفق: 201 با <code>parse_status=parsed</code>؛ پیام تکراری: 200 با <code>duplicate=true</code>.</li>
            <li>چرخش رمز = صدور جفت جدید و به‌روزرسانی فوریِ دو مقدار در میان‌بر؛ رمز قبلی همان لحظه می‌میرد.</li>
        </ol>
    </div>

    <div class="mt-4 max-w-3xl rounded-lg border border-teal-200 bg-teal-50 p-5 rounded-2xl">
        <flux:heading size="text-base" class="text-teal-800">عیب‌یابی</flux:heading>
        <ul class="mt-3 list-disc space-y-2 ps-6 text-sm leading-7 text-teal-900">
            <li><strong>401 invalid_device_key</strong> — کلید اشتباه یا دستگاه باطل شده؛ پنل دستگاه‌ها را چک کنید.</li>
            <li><strong>401 invalid_device_signature</strong> — رمز با اثر ذخیره‌شده مطابقت ندارد؛ پس از چرخش، میان‌بر را با رمز جدید به‌روزرسانی کرده‌اید؟</li>
            <li><strong>429 rate_limit_exceeded</strong> — سقف ۶۰ درخواست در دقیقه پر شده؛ میان‌بر نباید برای هر پیام چند بار اجرا شود.</li>
            <li><strong>پیام‌ها ignored/sender_mismatch</strong> — فرستندهٔ پیش‌فرض «iOS Shortcut» با الگوی فرستندهٔ تجزیه‌گر نمی‌خواند؛ شمارهٔ واقعی فرستنده را در بدنه بفرستید یا الگو را در تجزیه‌گر بازبینی کنید.</li>
        </ul>
    </div>
@endsection
