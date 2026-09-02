<x-layouts.app :title="__('Applications')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('Merchant applications') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    @if (session('application_ok'))
        <flux:callout variant="success" class="mt-4">{{ __('Application') }} {{ __(session('application_ok')) }}.</flux:callout>
    @endif
    @if (session('revealed_secret'))
        {{-- ONE-TIME secret reveal (§FR-3): never stored, gone after this render. --}}
        <flux:callout variant="warning" class="mt-4">
            <strong>{{ __('Copy the secret now — it is shown only once.') }}</strong><br>
            {{ __('Public key') }}: <span class="font-mono">{{ session('revealed_secret')['public_key'] }}</span><br>
            {{ __('Secret') }}: <span class="font-mono">{{ session('revealed_secret')['secret'] }}</span>
        </flux:callout>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($applications as $app)
            <div class="panel-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:text class="font-medium">{{ $app->name }} <span class="text-zinc-500">({{ $app->slug }})</span></flux:text>
                        <flux:text size="xs" class="font-mono text-zinc-500">{{ $app->public_key }}</flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:badge color="{{ $app->is_active ? 'emerald' : 'zinc' }}">{{ $app->is_active ? 'active' : 'inactive' }}</flux:badge>
                        <form method="POST" action="{{ cardpay_route('applications.rotate', $app->id) }}">
                            @csrf
                            <flux:button size="xs" variant="outline" type="submit">{{ __('Rotate key') }}</flux:button>
                        </form>
                    </div>
                </div>
                <flux:text size="xs" class="mt-2 text-zinc-500">
                    {{ $app->apiKeys->whereNull('revoked_at')->count() }} {{ __('active key(s)') }}
                    · {{ __('tokens') }} {{ $app->token_digits }} {{ __('digits') }} · {{ __('expiry') }} {{ $app->payment_expiration_minutes }} {{ __('min') }}
                </flux:text>
            </div>
        @empty
            <flux:text class="text-zinc-500">{{ __('No applications.') }}</flux:text>
        @endforelse

        {{ $applications->links() }}
    </div>

    <div class="panel-card mt-8">
        <flux:heading size="text-base">{{ __('Add application') }}</flux:heading>
        <form method="POST" action="{{ cardpay_route('applications.store') }}" class="mt-3 grid gap-3 md:grid-cols-3">
            @csrf
            <flux:input name="name" label="{{ __('Name') }}" required />
            <flux:input name="webhook_url" label="{{ __('Webhook URL (https)') }}" />
            <flux:input name="callback_url" label="{{ __('Callback URL (https)') }}" />
            <flux:select name="token_digits" label="{{ __('Token digits') }}">
                <option value="2">2</option>
                <option value="3" selected>3</option>
            </flux:select>
            <flux:input name="payment_expiration_minutes" label="{{ __('Expiry (minutes)') }}" type="number" value="30" required />
            <input type="hidden" name="is_active" value="1">
            <div class="md:col-span-3">
                <flux:button variant="primary" type="submit" size="sm">{{ __('Create + show credentials once') }}</flux:button>
            </div>
        </form>
    </div>
</x-cardpay::layouts.app>
