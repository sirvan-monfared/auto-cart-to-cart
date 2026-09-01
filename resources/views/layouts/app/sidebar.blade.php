<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['fa']) ? 'rtl' : 'ltr' }}">
    <head>
        @include('cardpay::partials.head')
    </head>
    <body class="min-h-screen bg-surface antialiased">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-white">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            @auth
                @if (auth()->user()->isActiveAdmin())
                    <flux:sidebar.nav>
                        <flux:sidebar.group :heading="__('CardPay')" class="grid">
                            <flux:sidebar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                                {{ __('Overview') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="credit-card" :href="route('admin.payments')" :current="request()->routeIs('admin.payments*')" wire:navigate>
                                {{ __('Payments') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="clipboard-document-check" :href="route('admin.reviews')" :current="request()->routeIs('admin.reviews')" wire:navigate>
                                {{ __('Reviews') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="chat-bubble-oval-left" :href="route('admin.sms')" :current="request()->routeIs('admin.sms')" wire:navigate>
                                {{ __('SMS Log') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    </flux:sidebar.nav>

                    <flux:sidebar.nav>
                        <flux:sidebar.group :heading="__('Configuration')" class="grid">
                            <flux:sidebar.item icon="wallet" :href="route('admin.cards')" :current="request()->routeIs('admin.cards')" wire:navigate>
                                {{ __('Bank Cards') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="variable" :href="route('admin.parsers')" :current="request()->routeIs('admin.parsers')" wire:navigate>
                                {{ __('SMS Parsers') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="building-storefront" :href="route('admin.applications')" :current="request()->routeIs('admin.applications')" wire:navigate>
                                {{ __('Applications') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="device-phone-mobile" :href="route('admin.devices')" :current="request()->routeIs('admin.devices')" wire:navigate>
                                {{ __('Devices') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    </flux:sidebar.nav>

                    <flux:sidebar.nav>
                        <flux:sidebar.group :heading="__('System')" class="grid">
                            <flux:sidebar.item icon="signal" :href="route('admin.webhooks')" :current="request()->routeIs('admin.webhooks')" wire:navigate>
                                {{ __('Webhooks') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="chart-bar" :href="route('admin.reports')" :current="request()->routeIs('admin.reports')" wire:navigate>
                                {{ __('Reports') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="cog" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>
                                {{ __('Settings') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="shield-check" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>
                                {{ __('Audit Log') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="server-stack" :href="route('admin.system')" :current="request()->routeIs('admin.system')" wire:navigate>
                                {{ __('System') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    </flux:sidebar.nav>
                @endif
            @endauth

            <flux:spacer />

            @auth
                <flux:sidebar.nav>
                    <flux:sidebar.item icon="book-open-text" :href="route('admin.docs')" :current="request()->routeIs('admin.docs*')" wire:navigate>
                        {{ __('Documentation') }}
                    </flux:sidebar.item>
                </flux:sidebar.nav>
            @endauth

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
