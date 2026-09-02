<x-layouts.app :title="__('Review Queue')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Manual review queue') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    @if (session('decision_ok'))
        <flux:callout variant="success" class="mt-4">
            {{ __('Decision recorded') }}: {{ __(session('decision_ok')) }}.
        </flux:callout>
    @endif
    @if (session('decision_error'))
        <flux:callout variant="danger" class="mt-4">
            {{ __('Could not decide') }}: {{ session('decision_error') }}.
        </flux:callout>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($pending as $review)
            <div class="panel-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <a class="font-mono text-xs underline decoration-teal-300 hover:text-teal-700" href="{{ cardpay_route('payments.show', $review->payment?->public_id) }}">
                            {{ $review->payment?->public_id }}
                        </a>
                        <span class="ms-2 text-sm">{{ number_format($review->reported_amount ?? $review->payment?->payable_amount ?? 0) }} {{ $review->payment?->currency }}</span>
                        <span class="ms-2 text-xs text-zinc-500">{{ __('payment') }}: {{ $review->payment?->status?->label() }}</span>
                        @if ($review->receipt_path)
                            <a href="{{ cardpay_route('reviews.receipt', $review) }}" target="_blank" rel="noopener"
                               class="ms-2 text-xs font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">{{ __('View receipt') }}</a>
                        @endif
                    </div>

                    <form method="POST" action="{{ cardpay_route('reviews.approve', $review->id) }}" class="flex items-center gap-2">
                        @csrf
                        <input type="hidden" name="note" value="">
                        <flux:input name="sms_id" size="sm" placeholder="{{ __('SMS id (optional)') }}" class="w-40" />
                        <flux:button variant="primary" type="submit" size="sm">{{ __('Approve') }}</flux:button>
                    </form>
                    <form method="POST" action="{{ cardpay_route('reviews.reject', $review->id) }}">
                        @csrf
                        <flux:button variant="danger" type="submit" size="sm">{{ __('Reject') }}</flux:button>
                    </form>
                </div>
            </div>
        @empty
            <flux:text class="text-zinc-500">{{ __('Queue is empty.') }}</flux:text>
        @endforelse
    </div>

    {{ $pending->links() }}

    @if ($decided->isNotEmpty())
        <flux:heading size="text-base" class="mt-8">{{ __('Recently decided') }}</flux:heading>
        <ul class="mt-3 space-y-1 text-sm text-zinc-600">
            @foreach ($decided as $review)
                <li>#{{ $review->id }} — payment {{ $review->payment?->public_id }} — {{ $review->status }} by #{{ $review->reviewed_by }}</li>
            @endforeach
        </ul>
    @endif
</x-cardpay::layouts.app>
