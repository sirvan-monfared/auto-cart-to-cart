<x-layouts.app :title="__('Webhook Monitor')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Webhook monitor') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    @if (session('webhook_requeued'))
        <flux:callout variant="success" class="mt-4">{{ __('Delivery re-queued for immediate retry.') }}</flux:callout>
    @endif
    @if (session('webhook_retry_failed'))
        <flux:callout variant="danger" class="mt-4">{{ __('Only failed or exhausted deliveries can be retried.') }}</flux:callout>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($events as $event)
            <div class="panel-card">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:badge color="cyan">{{ $event->event_type->label() }}</flux:badge>
                        <span class="ms-2 font-mono text-xs">{{ $event->event_id }}</span>
                        <span class="ms-2 text-xs text-zinc-500">{{ __('app') }}: {{ $event->application?->slug }}</span>
                    </div>
                </div>

                @foreach ($event->deliveries as $delivery)
                    <div class="mt-2 flex items-center justify-between rounded-lg border border-zinc-200 p-2 text-sm">
                        <div>
                            {{ $delivery->status->label() }} · {{ __('attempt') }} {{ $delivery->attempt }}
                            @if ($delivery->response_status) · HTTP {{ $delivery->response_status }} @endif
                            @if ($delivery->error_message)<span class="text-red-500"> · {{ \Illuminate\Support\Str::limit($delivery->error_message, 80) }}</span>@endif
                            <div class="text-xs text-zinc-500">{{ $delivery->url }}</div>
                        </div>
                        @if (in_array($delivery->status->value, ['failed', 'exhausted'], true))
                            <form method="POST" action="{{ route('admin.webhooks.retry', $delivery->id) }}">
                                @csrf
                                <flux:button size="xs" variant="outline" type="submit">{{ __('Retry now') }}</flux:button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <flux:text class="text-zinc-500">{{ __('No webhook events.') }}</flux:text>
        @endforelse
    </div>

    {{ $events->links() }}
</x-cardpay::layouts.app>
