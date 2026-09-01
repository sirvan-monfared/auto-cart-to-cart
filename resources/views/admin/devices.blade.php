<x-layouts.app :title="__('Devices')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Relay devices') }}</flux:heading>
        <flux:subheading>
            {{ __('Onboarding guides') }}:
            <a href="{{ route('admin.guides.devices.android') }}" class="font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">{{ __('Android (HMAC)') }}</a>
            ·
            <a href="{{ route('admin.guides.devices.ios') }}" class="font-medium text-teal-700 underline decoration-teal-300 hover:text-teal-600">{{ __('iOS Shortcut') }}</a>
        </flux:subheading>
    </div>
    <x-admin.docs-button />
</div>

    @if (session('device_ok'))
        <flux:callout variant="success" class="mt-4">{{ __('Device') }} {{ __(session('device_ok')) }}.</flux:callout>
    @endif
    @if (session('revealed_secret'))
        <flux:callout variant="warning" class="mt-4">
            <strong>{{ __('Copy the device secret now — shown only once.') }}</strong><br>
            {{ __('Device key') }}: <span class="font-mono">{{ session('revealed_secret')['device_key'] }}</span><br>
            {{ __('Secret') }}: <span class="font-mono">{{ session('revealed_secret')['secret'] }}</span>
        </flux:callout>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($devices as $device)
            <div class="panel-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:text class="font-medium">{{ $device->name }} <span class="text-zinc-500">({{ $device->platform->label() }})</span></flux:text>
                        <flux:text size="xs" class="text-zinc-500">
                            {{ $device->bankCard?->title ?? '—' }} · {{ $device->sms_count }} {{ __('SMS relayed') }}
                            · {{ __('key') }} <span class="font-mono">{{ $device->device_key }}</span>
                        </flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($device->revoked_at !== null)
                            <flux:badge color="red">{{ __('revoked') }}</flux:badge>
                        @else
                            <flux:badge color="{{ $device->is_active ? 'green' : 'zinc' }}">{{ $device->is_active ? __('active') : __('paused') }}</flux:badge>
                            <form method="POST" action="{{ route('admin.devices.rotate', $device->id) }}">
                                @csrf
                                <flux:button size="xs" variant="outline" type="submit">{{ __('Rotate') }}</flux:button>
                            </form>
                            <form method="POST" action="{{ route('admin.devices.revoke', $device->id) }}"
                                  onsubmit="return confirm('@js(__("$1Revoke permanently? The device can never relay again."))')">
                                @csrf
                                <flux:button size="xs" variant="danger" type="submit">{{ __('Revoke') }}</flux:button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <flux:text class="text-zinc-500">{{ __('No devices.') }}</flux:text>
        @endforelse

        {{ $devices->links() }}
    </div>

    <div class="panel-card mt-8">
        <flux:heading size="text-base">{{ __('Add device') }}</flux:heading>
        <form method="POST" action="{{ route('admin.devices.store') }}" class="mt-3 grid gap-3 md:grid-cols-3">
            @csrf
            <flux:input name="name" label="{{ __('Name') }}" required />
            <flux:select name="platform" label="{{ __('Platform') }}">
                <option value="android">{{ __('Android') }}</option>
                <option value="ios-shortcut">{{ __('iOS Shortcut') }}</option>
            </flux:select>
            <flux:select name="bank_card_id" label="{{ __('Bound bank card') }}">
                @foreach ($cards as $card)
                    <option value="{{ $card->id }}">{{ $card->title }} ({{ $card->bank_name }})</option>
                @endforeach
            </flux:select>
            <input type="hidden" name="is_active" value="1">
            <div class="md:col-span-3">
                <flux:button variant="primary" type="submit" size="sm">{{ __('Create + show secret once') }}</flux:button>
            </div>
        </form>
    </div>
</x-cardpay::layouts.app>
