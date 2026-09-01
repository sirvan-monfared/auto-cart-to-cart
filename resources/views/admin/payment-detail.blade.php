<x-layouts.app :title="__('Payment Detail')">
    <div class="page-title-row">
        <div>
            <flux:heading size="text-xl" level="1">{{ __('Payment') }} {{ $payment->public_id }}</flux:heading>
            <flux:subheading>{{ $payment->status->label() }} · {{ number_format($payment->payable_amount) }} {{ $payment->currency }}</flux:subheading>
        </div>
        <x-admin.docs-button />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="panel-card">
            <flux:heading size="text-base">{{ __('Details') }}</flux:heading>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Application') }}</dt><dd>{{ $payment->application->slug ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Card') }}</dt><dd>{{ $payment->bankCard?->bank_name }} ····{{ $payment->bankCard?->card_number_last_four }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Original / token</dt><dd>{{ number_format($payment->original_amount) }} + {{ $payment->token }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Customer') }}</dt><dd>{{ $payment->customer_name ?? '—' }} {{ $payment->customer_mobile ?? '' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Created') }}</dt><dd>{{ $payment->created_at?->format('Y-m-d H:i') }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Paid') }}</dt><dd>{{ $payment->paid_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                @if ($payment->return_url)
                    <div class="flex justify-between"><dt class="text-zinc-500">Return URL</dt><dd class="max-w-[50%] truncate font-mono text-xs">{{ $payment->return_url }}</dd></div>
                @endif
            </dl>
        </div>

        <div class="panel-card">
            <flux:heading size="text-base">{{ __('SMS evidence') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($smsEvidence as $sms)
                    <li class="rounded-lg border border-zinc-200 p-2">
                        <span class="font-mono text-xs">{{ $sms->message_id }}</span> —
                        {{ number_format($sms->parsed_amount ?? 0) }} · {{ $sms->match_status->label() }}
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No linked SMS.') }}</li>
                @endforelse
            </ul>
        </div>

        <div class="panel-card">
            <flux:heading size="text-base">{{ __('Webhook events') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($webhookEvents as $event)
                    <li>
                        <flux:badge>{{ $event->event_type->label() }}</flux:badge>
                        @foreach ($event->deliveries as $delivery)
                            · {{ $delivery->status->label() }} ({{ __('attempt') }} {{ $delivery->attempt }})
                        @endforeach
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No events.') }}</li>
                @endforelse
            </ul>
        </div>

        <div class="panel-card">
            <flux:heading size="text-base">{{ __('Reviews') }}</flux:heading>
            <ul class="mt-3 space-y-1 text-sm">
                @forelse ($payment->reviews as $review)
                    <li>
                        #{{ $review->id }} — {{ $review->status }} @if ($review->internal_note)· {{ $review->internal_note }}@endif
                        @if ($review->receipt_path)
                            · <a href="{{ route('admin.reviews.receipt', $review) }}" target="_blank" rel="noopener" class="font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">{{ __('Download receipt') }}</a>
                        @endif
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('No reviews.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-cardpay::layouts.app>
