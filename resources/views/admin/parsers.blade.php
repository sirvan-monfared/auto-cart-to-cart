<x-layouts.app :title="__('SMS Parsers')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('SMS parsers') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>
    <flux:subheading>{{ __('Per-bank deposit extraction rules. Test live before trusting them.') }}</flux:subheading>

    @if (session('parser_ok'))
        <flux:callout variant="success" class="mt-4">{{ __('Parser') }} {{ __(session('parser_ok')) }}.</flux:callout>
    @endif
    @if (session('live_test'))
        <flux:callout variant="{{ session('live_test')['status'] === 'parsed' ? 'success' : 'warning' }}" class="mt-4">
            Live test — status: {{ session('live_test')['status'] }}
            @if (session('live_test')['amount'] !== null) · {{ __('amount') }}: {{ number_format(session('live_test')['amount']) }} @endif
            @if (session('live_test')['error']) · {{ session('live_test')['error'] }} @endif
        </flux:callout>
    @endif

    <div class="mt-6 space-y-6">
        @foreach ($parsers as $parser)
            <div class="panel-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:text class="font-medium">{{ $parser->name }} — {{ $parser->bank_name }}</flux:text>
                        <flux:text size="xs" class="font-mono text-zinc-500">{{ $parser->amount_regex }}</flux:text>
                    </div>
                    <flux:badge color="{{ $parser->is_active ? 'emerald' : 'zinc' }}">{{ $parser->is_active ? 'active' : 'inactive' }}</flux:badge>
                </div>

                {{-- Live test against THIS parser's rules --}}
                <form method="POST" action="{{ cardpay_route('parsers.test', $parser->id) }}" class="mt-3 flex flex-wrap items-end gap-2">
                    @csrf
                    <flux:input name="test_sender" size="sm" placeholder="{{ __('Sender (optional)') }}" class="w-44" />
                    <flux:input name="test_text" size="sm" placeholder="{{ __('Paste a sample SMS…') }}" class="min-w-[280px] flex-1" required />
                    <flux:button size="sm" variant="outline" type="submit">{{ __('Test') }}</flux:button>
                </form>
            </div>
        @endforeach

        {{ $parsers->links() }}
    </div>

    <div class="panel-card mt-8">
        <flux:heading size="text-base">{{ __('Add parser') }}</flux:heading>
        <form method="POST" action="{{ cardpay_route('parsers.store') }}" class="mt-3 grid gap-3 md:grid-cols-2">
            @csrf
            <flux:input name="name" label="{{ __('Name') }}" required />
            <flux:input name="bank_name" label="{{ __('Bank name') }}" required />
            <flux:input name="sender_pattern" label="{{ __('Sender pattern (optional regex)') }}" placeholder="/^98700077$/" />
            <flux:input name="amount_regex" label="{{ __('Amount regex (named group `amount`)') }}" required />
            <flux:textarea name="positive_keywords" label="{{ __('Positive keywords (comma/newline)') }}" rows="2" />
            <flux:textarea name="negative_keywords" label="{{ __('Negative keywords (comma/newline)') }}" rows="2" />
            <input type="hidden" name="is_active" value="1">
            <div class="md:col-span-2">
                <flux:button variant="primary" type="submit" size="sm">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </div>
</x-cardpay::layouts.app>
