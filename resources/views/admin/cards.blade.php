<x-layouts.app :title="__('Bank Cards')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Bank cards') }}</flux:heading>
        <flux:subheading>{{ __('Destination cards customers transfer to. Full numbers are encrypted at rest.') }}</flux:subheading>
    </div>
    <x-admin.docs-button />
</div>

    @if (session('card_ok'))
        <flux:callout variant="success" class="mt-4">{{ __('Card') }} {{ __(session('card_ok')) }}.</flux:callout>
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" class="mt-4">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </flux:callout>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="panel-card !p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Title') }}</flux:table.column>
                <flux:table.column>{{ __('Bank') }}</flux:table.column>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Parser') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($cards as $card)
                    <flux:table.row>
                        <flux:table.cell>{{ $card->title }}</flux:table.cell>
                        <flux:table.cell>{{ $card->bank_name }}</flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">····{{ $card->card_number_last_four }}</flux:table.cell>
                        <flux:table.cell>{{ $card->smsParser?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $card->is_active ? 'emerald' : 'zinc' }}">{{ $card->is_active ? 'active' : 'inactive' }}</flux:badge>
                            @if ($card->is_active)
                                <form method="POST" action="{{ cardpay_route('cards.destroy', $card->id) }}" class="inline ms-2">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button size="xs" variant="ghost" type="submit">{{ __('Deactivate') }}</flux:button>
                                </form>
                            @else
                                <form method="POST" action="{{ cardpay_route('cards.activate', $card->id) }}" class="inline ms-2">
                                    @csrf
                                    <flux:button size="xs" variant="ghost" type="submit">{{ __('Activate') }}</flux:button>
                                </form>
                            @endif
                            <flux:modal.trigger :name="'edit-card-'.$card->id" class="inline ms-2">
                                <flux:button size="xs" variant="ghost">{{ __('Edit') }}</flux:button>
                            </flux:modal.trigger>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No cards yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </div>

        <div class="panel-card">
            <flux:heading size="text-base">{{ __('Add card') }}</flux:heading>
            <form method="POST" action="{{ cardpay_route('cards.store') }}" class="mt-3 flex flex-col gap-3">
                @csrf
                <flux:input name="title" label="{{ __('Title') }}" required />
                <flux:input name="bank_name" label="{{ __('Bank name') }}" required />
                <flux:input name="card_number" label="{{ __('Card number') }}" required placeholder="6219861012345678" />
                <flux:input name="card_holder_name" label="{{ __('Card holder') }}" required />
                <flux:input name="iban" label="{{ __('IBAN (optional)') }}" />
                <flux:select name="sms_parser_id" label="{{ __('SMS parser') }}">
                    <option value="">— {{ __('none') }} —</option>
                    @foreach (\CartBecart\CardPay\Models\SmsParser::query()->where('is_active', true)->get(['id', 'name']) as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </flux:select>
                <flux:button variant="primary" type="submit" size="sm">{{ __('Create') }}</flux:button>
            </form>
        </div>
    </div>

    {{-- Edit modals: one per card, opened by the row's Edit button. Encrypted
         fields stay blank — leaving them untouched keeps the stored values. --}}
    @foreach ($cards as $card)
        <flux:modal name="edit-card-{{ $card->id }}">
            <div>
                <flux:heading size="text-base">{{ __('Edit card') }}</flux:heading>
                <flux:subheading>{{ $card->title }} · ····{{ $card->card_number_last_four }}</flux:subheading>
            </div>

            <form method="POST" action="{{ cardpay_route('cards.update', $card->id) }}" class="mt-4 flex flex-col gap-3">
                @csrf
                @method('PUT')
                <flux:input name="title" label="{{ __('Title') }}" :value="$card->title" required />
                <flux:input name="bank_name" label="{{ __('Bank name') }}" :value="$card->bank_name" required />
                <flux:input
                    name="card_number"
                    :label="__('Card number (leave blank to keep)')"
                    placeholder="····{{ $card->card_number_last_four }}"
                />
                <flux:input name="card_holder_name" label="{{ __('Card holder') }}" :value="$card->card_holder_name" required />
                <flux:input name="iban" label="{{ __('IBAN (leave blank to keep)') }}" />
                <flux:select name="sms_parser_id" label="{{ __('SMS parser') }}">
                    <option value="" @selected($card->sms_parser_id === null)>— {{ __('none') }} —</option>
                    @foreach (\CartBecart\CardPay\Models\SmsParser::query()->where('is_active', true)->get(['id', 'name']) as $p)
                        <option value="{{ $p->id }}" @selected($card->sms_parser_id === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </flux:select>

                <div class="mt-2 flex items-center justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost" size="sm" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" size="sm" type="submit">{{ __('Save changes') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach

    {{ $cards->links() }}
</x-cardpay::layouts.app>
