<x-layouts.app :title="__('System')">
    
<div class="page-title-row">
    <div>
        <flux:heading size="text-xl" level="1">{{ __('System') }}</flux:heading>
    </div>
    <x-admin.docs-button />
</div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="panel-card">
            <flux:heading size="text-base">{{ __('Runtime') }}</flux:heading>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('PHP') }}</dt><dd>{{ $php }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Laravel') }}</dt><dd>{{ $laravel }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Database driver') }}</dt><dd>{{ $server }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Database') }}</dt><dd class="font-mono text-xs">{{ $database }}</dd></div>
                <div class="flex justify-between">
                    <dt class="text-zinc-500">{{ __('Installer lock') }}</dt>
                    <dd><flux:badge color="{{ $installed ? 'green' : 'amber' }}">{{ $installed ? __('installed') : __('not installed') }}</flux:badge></dd>
                </div>
            </dl>

            <flux:heading size="text-base" class="mt-4">{{ __('Pending migrations') }}</flux:heading>
            @if (empty($migrations))
                <p class="mt-2 text-sm text-green-600">{{ __('All migrations applied.') }}</p>
            @else
                <ul class="mt-2 space-y-0.5 text-sm text-red-600">
                    @foreach ($migrations as $m)
                        <li class="font-mono text-xs">{{ $m }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="panel-card">
            <flux:heading size="text-base">{{ __('Extensions') }}</flux:heading>
            <ul class="mt-3 grid grid-cols-2 gap-1 text-sm">
                @foreach ($extensions as $ext)
                    <li class="flex items-center gap-2">
                        <span class="{{ $ext['loaded'] ? 'text-green-600' : 'text-red-600' }}">{{ $ext['loaded'] ? '●' : '○' }}</span>
                        {{ $ext['name'] }}
                    </li>
                @endforeach
            </ul>

            <flux:heading size="text-base" class="mt-4">{{ __('Writable directories') }}</flux:heading>
            <ul class="mt-3 space-y-1 text-xs font-mono">
                @foreach ($writable as $dir)
                    <li class="flex items-center gap-2">
                        <span class="{{ $dir['ok'] ? 'text-green-600' : 'text-red-600' }}">{{ $dir['ok'] ? '✓' : '✗' }}</span>
                        {{ str_replace(base_path().'/', '', $dir['path']) }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-cardpay::layouts.app>
