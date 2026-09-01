<x-layouts.app :title="__('Reports')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Reports') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    <form method="GET" action="{{ route('admin.reports') }}" class="mt-4 flex items-end gap-3">
        <flux:input type="date" name="from" :value="$from" label="{{ __('From') }}" />
        <flux:input type="date" name="to" :value="$to" label="{{ __('To') }}" />
        <flux:button variant="outline" type="submit">{{ __('Apply') }}</flux:button>
        <flux:button :href="route('admin.reports.csv', ['from' => $from, 'to' => $to])" variant="primary">{{ __('Export CSV') }}</flux:button>
    </form>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Count') }}</flux:table.column>
            <flux:table.column>{{ __('Volume (payable sum)') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($rows as $row)
                <flux:table.row>
                    <flux:table.cell><flux:badge>{{ __(str_replace('_', ' ', $row['status'])) }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($row['volume']) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    <p class="mt-2 text-xs text-zinc-500">{{ __('Window') }} {{ $from }} → {{ $to }} ({{ __('UTC, inclusive') }}).</p>
</x-cardpay::layouts.app>
