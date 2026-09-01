<x-layouts.app :title="__('Audit Log')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Audit log') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    <div class="panel-card mt-4 !p-0 overflow-hidden">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Actor') }}</flux:table.column>
            <flux:table.column>{{ __('Action') }}</flux:table.column>
            <flux:table.column>{{ __('Entity') }}</flux:table.column>
            <flux:table.column>IP</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($entries as $entry)
                <flux:table.row>
                    <flux:table.cell class="text-xs">{{ $entry->created_at?->format('Y-m-d H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->actor_type }}#{{ $entry->actor_id ?? '—' }}</flux:table.cell>
                    <flux:table.cell><flux:badge>{{ $entry->action }}</flux:badge></flux:table.cell>
                    <flux:table.cell class="text-xs">{{ $entry->entity_type ?? '—' }}{{ $entry->entity_id ? '#'.$entry->entity_id : '' }}</flux:table.cell>
                    <flux:table.cell class="text-xs">{{ $entry->ip ?? '—' }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5">{{ __('No audit entries.') }}</flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    {{ $entries->links() }}
</x-cardpay::layouts.app>
