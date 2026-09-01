<x-layouts.app :title="__('SMS Log')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('SMS log') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    <div class="mt-4 mb-4 flex gap-2">
        <flux:button :href="route('admin.sms')" size="xs" variant="ghost" :variant-filled="! request('match')">{{ __('All') }}</flux:button>
        <flux:button :href="route('admin.sms', ['match' => 'unmatched'])" size="xs" variant="ghost" :variant-filled="request('match') === 'unmatched'">{{ __('Unmatched only') }}</flux:button>
    </div>

    <div class="panel-card !p-0 overflow-hidden">
        <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Device') }}</flux:table.column>
            <flux:table.column>{{ __('Message') }}</flux:table.column>
            <flux:table.column>{{ __('Amount') }}</flux:table.column>
            <flux:table.column>{{ __('Parse') }}</flux:table.column>
            <flux:table.column>{{ __('Match') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($messages as $sms)
                <flux:table.row>
                    <flux:table.cell>{{ $sms->device?->name ?? $sms->device_id }}</flux:table.cell>
                    <flux:table.cell class="max-w-[280px] truncate font-mono text-xs">{{ $sms->message_id }}</flux:table.cell>
                    <flux:table.cell>{{ $sms->parsed_amount !== null ? number_format($sms->parsed_amount) : '—' }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $sms->parse_status->label() }}
                        @if ($sms->parse_error)<span class="text-xs text-red-500">{{ $sms->parse_error }}</span>@endif
                    </flux:table.cell>
                    <flux:table.cell><flux:badge>{{ $sms->match_status->label() }}</flux:badge></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="5">{{ __('No messages.') }}</flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    {{ $messages->links() }}
</x-cardpay::layouts.app>
