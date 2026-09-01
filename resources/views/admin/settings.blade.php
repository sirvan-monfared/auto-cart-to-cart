<x-layouts.app :title="__('Settings')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Settings') }}</flux:heading>
        <flux:subheading>{{ __('Typed values; only rows marked public may appear on the checkout page.') }}</flux:subheading>
    </div>
    <x-admin.docs-button />
</div>

    @if (session('settings_ok'))
        <flux:callout variant="success" class="mt-4">{{ __('Settings saved. Changes are in the audit log.') }}</flux:callout>
    @endif
    @if (session('settings_error'))
        <flux:callout variant="danger" class="mt-4">{{ session('settings_error') }}</flux:callout>
    @endif

    <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('PUT')

        @foreach ($sections as $section => $fields)
            <div class="panel-card">
                <flux:heading size="text-base">{{ __("cardpay::settings.section.$section") }}</flux:heading>
                <div class="mt-3 grid gap-4 md:grid-cols-2">
                    @foreach ($fields as $f)
                        <div>
                            <label class="block text-sm font-medium mb-1">{{ $f['label'] }}</label>
                            @if (in_array($f['key'], ['payment_help', 'success_text', 'expired_text']))
                                <textarea name="settings[{{ $f['key'] }}]" rows="2"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-500/30">{{ $f['value'] }}</textarea>
                            @else
                                <input type="text" name="settings[{{ $f['key'] }}]" value="{{ $f['value'] }}"
                                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-500/30">
                            @endif

                            @if ($f['can_be_public'])
                                <label class="mt-1 flex items-center gap-2 text-xs text-zinc-500">
                                    <input type="checkbox" name="public[{{ $f['key'] }}]" value="1"
                                        @checked($f['is_public'])> {{ __('public (visible on checkout)') }}
                                </label>
                            @else
                                <p class="mt-1 text-xs text-zinc-400">{{ __('internal — never exposed publicly') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <flux:button variant="primary" type="submit">{{ __('Save settings') }}</flux:button>
    </form>
</x-cardpay::layouts.app>
