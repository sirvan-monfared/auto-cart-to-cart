@php
    // Route name → docs section key. Deep-links each admin page's header
    // button to that exact page's Persian guide.
    $docsKey = match (true) {
        request()->routeIs('admin.dashboard') => 'dashboard',
        request()->routeIs('admin.payments.show') => 'payment-detail',
        request()->routeIs('admin.payments') => 'payments',
        request()->routeIs('admin.reviews*') => 'reviews',
        request()->routeIs('admin.sms') => 'sms',
        request()->routeIs('admin.cards*') => 'cards',
        request()->routeIs('admin.parsers*') => 'parsers',
        request()->routeIs('admin.applications*') => 'applications',
        request()->routeIs('admin.devices*') => 'devices',
        request()->routeIs('admin.webhooks*') => 'webhooks',
        request()->routeIs('admin.reports*') => 'reports',
        request()->routeIs('admin.settings*') => 'settings',
        request()->routeIs('admin.audit') => 'audit',
        request()->routeIs('admin.system') => 'system',
        default => null,
    };
@endphp

@if ($docsKey !== null && \CartBecart\CardPay\Support\AdminDocs::get($docsKey) !== null)
    <a href="{{ route('admin.docs.show', $docsKey) }}"
       target="_blank"
       rel="noopener"
       title="{{ __('راهنمای فارسی همین صفحه') }}"
       class="inline-flex items-center gap-1.5 rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-sm font-medium text-teal-700 transition hover:border-teal-400 hover:bg-teal-100">
        <flux:icon icon="book-open" class="h-4 w-4" />
        راهنمای همین بخش
    </a>
@endif
