<x-layouts.app :title="__('راهنمای پنل مدیریت')">
    <div dir="rtl" class="docs-rtl">
        <flux:heading size="text-xl" level="1">راهنمای استفاده از پنل مدیریت</flux:heading>
        <p class="mt-2 max-w-3xl leading-8 text-zinc-600">
            این راهنما تمام بخش‌های پنل مدیریت کارت‌پی را گام‌به‌گام توضیح می‌دهد.
            در هر صفحه از پنل نیز دکمهٔ «راهنمای همین بخش» در بالای صفحه قرار دارد که مستقیماً شما را به راهنمای همان صفحه می‌برد.
        </p>

        <div class="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($sections as $key => $doc)
                <a href="{{ cardpay_route('docs.show', $key) }}"
                   class="group panel-card transition hover:border-teal-400 hover:shadow-md hover:shadow-teal-900/10">
                    <div class="flex items-center gap-2">
                        <flux:icon :icon="$doc['icon']" class="h-5 w-5 text-teal-700" />
                        <span class="font-bold">{{ $doc['title'] }}</span>
                    </div>
                    <p class="mt-2 line-clamp-3 text-sm leading-6 text-zinc-500">
                        {{ $doc['intro'] }}
                    </p>
                    <span class="mt-2 inline-block text-sm font-medium text-teal-700 group-hover:underline">مشاهدهٔ راهنما ←</span>
                </a>
            @endforeach
        </div>

        <flux:heading size="text-lg" class="mt-10">راهنمای راه‌اندازی دستگاه‌ها</flux:heading>
        <p class="mt-1 max-w-3xl text-sm leading-7 text-zinc-500">
            راهنمای گام‌به‌گام اتصال گوشی مخابره‌گر پیامک، به تفکیک پلتفرم.
        </p>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <a href="{{ cardpay_route('guides.devices.android') }}"
               class="group panel-card transition hover:border-teal-400 hover:shadow-md hover:shadow-teal-900/10">
                <div class="flex items-center gap-2">
                    <flux:icon icon="device-phone-mobile" class="h-5 w-5 text-teal-700" />
                    <span class="font-bold">دستگاه اندروید (امضای HMAC)</span>
                </div>
                <p class="mt-2 text-sm leading-6 text-zinc-500">
                    اپ اتوماسیون اندروید (Tasker / MacroDroid / اپ اختصاصی) با امضای HMAC روی هر درخواست — امن‌ترین روش مخابرهٔ پیامک.
                </p>
                <span class="mt-2 inline-block text-sm font-medium text-teal-700 group-hover:underline">مشاهدهٔ راهنما ←</span>
            </a>
            <a href="{{ cardpay_route('guides.devices.ios') }}"
               class="group panel-card transition hover:border-teal-400 hover:shadow-md hover:shadow-teal-900/10">
                <div class="flex items-center gap-2">
                    <flux:icon icon="device-phone-mobile" class="h-5 w-5 text-teal-700" />
                    <span class="font-bold">میان‌بر iOS (کلید و رمز ثابت)</span>
                </div>
                <p class="mt-2 text-sm leading-6 text-zinc-500">
                    راه‌اندازی میان‌بر iOS Shortcuts با جفت کلید/رمز ثابت — ساده‌تر، مناسب گوشی‌های شخصی تحت کنترل شما.
                </p>
                <span class="mt-2 inline-block text-sm font-medium text-teal-700 group-hover:underline">مشاهدهٔ راهنما ←</span>
            </a>
        </div>
    </div>
</x-cardpay::layouts.app>
