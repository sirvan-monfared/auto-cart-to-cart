<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['fa']) ? 'rtl' : 'ltr' }}">
    <head>
        @include('cardpay::partials.head')
    </head>
    <body class="min-h-screen bg-linear-to-b from-teal-50 via-white to-cyan-50 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="/" class="mb-1 flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-linear-to-br from-teal-600 to-cyan-500 shadow-lg shadow-teal-600/25">
                        <x-app-logo-icon class="size-7 fill-current text-white" />
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6 rounded-2xl border border-zinc-200 bg-white p-8 shadow-lg shadow-teal-900/10">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
