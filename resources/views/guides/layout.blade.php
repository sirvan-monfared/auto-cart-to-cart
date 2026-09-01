<x-layouts.app :title="__('راهنمای دستگاه')">
    <div dir="rtl" class="docs-rtl">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="text-xl" level="1">{{ $title }}</flux:heading>
            <flux:button :href="route('admin.docs')" variant="ghost" size="sm">← فهرست راهنما</flux:button>
        </div>

        @yield('content')

        <div class="mt-8 max-w-3xl rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
            <flux:heading size="text-sm">راهنمای دیگر</flux:heading>
            <div class="mt-2 flex gap-3">
                <a href="{{ route('admin.guides.devices.android') }}" class="text-sm font-medium text-teal-700 underline decoration-teal-300 {{ $platform === 'android' ? 'font-bold' : '' }}">راهنمای دستگاه اندروید (امضای HMAC)</a>
                <a href="{{ route('admin.guides.devices.ios') }}" class="text-sm font-medium text-teal-700 underline decoration-teal-300 {{ $platform === 'ios' ? 'font-bold' : '' }}">راهنمای میان‌بر iOS</a>
            </div>
        </div>
    </div>

    <style>
        .docs-rtl { direction: rtl; text-align: right; }
        .docs-rtl ol, .docs-rtl ul { direction: rtl; }
        .docs-rtl code, .docs-rtl pre { direction: ltr; text-align: left; }
    </style>
</x-cardpay::layouts.app>
