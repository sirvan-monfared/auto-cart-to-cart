<x-layouts.app :title="__('Admin Dashboard')">
    <div class="page-title-row">
        <div>
            <flux:heading size="text-2xl" level="1">{{ __('CardPay Admin') }}</flux:heading>
            <flux:subheading>{{ __('Gateway overview — payments, reviews, devices, webhooks') }}</flux:subheading>
        </div>
        <x-admin.docs-button />
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach (['pending' => [__('Pending'), 'amber'], 'paid' => [__('Paid'), 'emerald'], 'expired' => [__('Expired'), 'red'], 'manual_review' => [__('Manual review'), 'cyan'], 'canceled' => [__('Canceled'), 'zinc'], 'rejected' => [__('Rejected'), 'red']] as $key => [$label, $tone])
            @php
                $tones = [
                    'amber' => 'bg-amber-50 text-amber-600',
                    'emerald' => 'bg-emerald-50 text-emerald-600',
                    'red' => 'bg-red-50 text-red-500',
                    'cyan' => 'bg-cyan-50 text-cyan-600',
                    'zinc' => 'bg-zinc-100 text-zinc-500',
                ];
            @endphp
            <div class="panel-card flex flex-col gap-3">
                <span class="flex size-8 items-center justify-center rounded-lg {{ $tones[$tone] }}">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                    </svg>
                </span>
                <div>
                    <flux:text class="text-zinc-500">{{ $label }}</flux:text>
                    <flux:heading size="text-xl">{{ $counts[$key] }}</flux:heading>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('admin.reviews') }}" class="panel-card group flex items-center justify-between transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-teal-900/10">
            <div>
                <flux:text class="text-zinc-500">{{ __('Paid today (UTC)') }}</flux:text>
                <flux:heading size="text-xl">{{ $paidToday }}</flux:heading>
            </div>
            <flux:icon.arrow-right class="size-5 text-zinc-300 transition group-hover:text-teal-600" />
        </a>
        <a href="{{ route('admin.devices') }}" class="panel-card group flex items-center justify-between transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-teal-900/10">
            <div>
                <flux:text class="text-zinc-500">{{ __('Active devices') }}</flux:text>
                <flux:heading size="text-xl">{{ $devices }}</flux:heading>
            </div>
            <flux:icon.arrow-right class="size-5 text-zinc-300 transition group-hover:text-teal-600" />
        </a>
        <a href="{{ route('admin.reviews') }}" class="panel-card group flex items-center justify-between transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-teal-900/10">
            <div>
                <flux:text class="text-zinc-500">{{ __('Pending reviews') }}</flux:text>
                <flux:heading size="text-xl">{{ $pendingReviews }}</flux:heading>
            </div>
            <flux:icon.arrow-right class="size-5 text-zinc-300 transition group-hover:text-teal-600" />
        </a>
        <a href="{{ route('admin.sms', ['match' => 'unmatched']) }}" class="panel-card group flex items-center justify-between transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-teal-900/10">
            <div>
                <flux:text class="text-zinc-500">{{ __('Unmatched SMS') }}</flux:text>
                <flux:heading size="text-xl">{{ $unmatchedSms }}</flux:heading>
            </div>
            <flux:icon.arrow-right class="size-5 text-zinc-300 transition group-hover:text-teal-600" />
        </a>
        <a href="{{ route('admin.webhooks') }}" class="panel-card group flex items-center justify-between transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-teal-900/10">
            <div>
                <flux:text class="text-zinc-500">{{ __('Failed / exhausted webhooks') }}</flux:text>
                <flux:heading size="text-xl">{{ $failedWebhooks }}</flux:heading>
            </div>
            <flux:icon.arrow-right class="size-5 text-zinc-300 transition group-hover:text-teal-600" />
        </a>
        <a href="{{ route('admin.payments') }}" class="panel-card group flex items-center justify-between transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-teal-900/10">
            <div>
                <flux:text class="text-zinc-500">{{ __('All payments') }}</flux:text>
                <flux:heading size="text-xl">{{ __('Browse') }}</flux:heading>
            </div>
            <flux:icon.arrow-right class="size-5 text-zinc-300 transition group-hover:text-teal-600" />
        </a>
    </div>
</x-cardpay::layouts.app>
