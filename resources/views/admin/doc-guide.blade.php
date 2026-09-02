<x-layouts.app :title="$doc['title']">
    <div dir="rtl" class="docs-rtl">
        <div class="flex items-center justify-between gap-4">
            <flux:heading size="text-xl" level="1">راهنما: {{ $doc['title'] }}</flux:heading>
            <flux:button :href="cardpay_route('docs')" variant="ghost" size="sm">← فهرست راهنما</flux:button>
        </div>

        <p class="mt-3 max-w-3xl leading-8 text-zinc-600">{{ $doc['intro'] }}</p>

        <div class="mt-6 max-w-3xl panel-card">
            <flux:heading size="text-base">مرحله‌به‌مرحله</flux:heading>
            <ol class="mt-3 list-decimal space-y-3 ps-6 text-sm leading-7 text-zinc-700">
                @foreach ($doc['steps'] as $step)
                    <li>{!! $step !!}</li>
                @endforeach
            </ol>
        </div>

        @if ($doc['notes'])
            <div class="mt-4 max-w-3xl rounded-lg border border-teal-200 bg-teal-50 p-5">
                <flux:heading size="text-base" class="text-teal-800">نکات مهم</flux:heading>
                <ul class="mt-3 list-disc space-y-2 ps-6 text-sm leading-7 text-teal-900">
                    @foreach ($doc['notes'] as $note)
                        <li>{!! $note !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-8 max-w-3xl">
            <flux:heading size="text-base">بخش‌های دیگر</flux:heading>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($others as $key => $other)
                    @continue($key === $section)
                    <a href="{{ cardpay_route('docs.show', $key) }}"
                       class="rounded-full border border-zinc-300 px-3 py-1 text-xs hover:border-teal-500">
                        {{ $other['title'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <style>
        .docs-rtl { direction: rtl; text-align: right; }
        .docs-rtl ol, .docs-rtl ul { direction: rtl; }
    </style>
</x-cardpay::layouts.app>
