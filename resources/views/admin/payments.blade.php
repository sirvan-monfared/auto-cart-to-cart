<x-layouts.app :title="__('Payments')">
    <div class="page-title-row">
        <div>
            <flux:heading size="text-xl" level="1">{{ __('Payments') }}</flux:heading>
        </div>
        <x-admin.docs-button />
    </div>

    <div class="mt-4 mb-4 flex gap-2">
        <flux:button :href="route('admin.payments')" size="xs" variant="ghost" :variant-filled="! request('status')">{{ __('All') }}</flux:button>
        @foreach (['pending', 'paid', 'expired', 'manual_review'] as $status)
            <flux:button :href="route('admin.payments', ['status' => $status])" size="xs" variant="ghost" :variant-filled="request('status') === $status">
                {{ __(str_replace('_', ' ', $status)) }}
            </flux:button>
        @endforeach
    </div>

    <div class="panel-card !p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Payment') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }} ({{ \CartBecart\CardPay\Models\Payment::query()->first()?->currency ?? 'IRR' }})</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Expires') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($payments as $payment)
                    <flux:table.row :href="route('admin.payments.show', $payment->public_id)">
                        <flux:table.cell><span class="font-mono text-xs">{{ $payment->public_id }}</span></flux:table.cell>
                        <flux:table.cell>{{ number_format($payment->payable_amount) }}</flux:table.cell>
                        <flux:table.cell><flux:badge color="{{ match ($payment->status->value) { 'paid' => 'emerald', 'pending' => 'amber', 'manual_review' => 'cyan', default => 'zinc' } }}">{{ $payment->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $payment->expires_at->format('Y-m-d H:i') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="4">{{ __('No payments.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{ $payments->links() }}
</x-cardpay::layouts.app>
