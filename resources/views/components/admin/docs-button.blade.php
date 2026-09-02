@php
    // Route name → docs section key. Deep-links each admin page's header
    // button to that exact page's Persian guide.
    $docsKey = match (true) {
        cardpay_route_is('dashboard') => 'dashboard',
        cardpay_route_is('payments.show') => 'payment-detail',
        cardpay_route_is('payments') => 'payments',
        cardpay_route_is('reviews*') => 'reviews',
        cardpay_route_is('sms') => 'sms',
        cardpay_route_is('cards*') => 'cards',
        cardpay_route_is('parsers*') => 'parsers',
        cardpay_route_is('applications*') => 'applications',
        cardpay_route_is('devices*') => 'devices',
        cardpay_route_is('webhooks*') => 'webhooks',
        cardpay_route_is('reports*') => 'reports',
        cardpay_route_is('settings*') => 'settings',
        cardpay_route_is('audit') => 'audit',
        cardpay_route_is('system') => 'system',
        default => null,
    };
@endphp

@if ($docsKey !== null && \CartBecart\CardPay\Support\AdminDocs::get($docsKey) !== null)
    <a href="{{ cardpay_route('docs.show', $docsKey) }}"
       target="_blank"
       rel="noopener"
       title="{{ __('راهنمای فارسی همین صفحه') }}"
       class="inline-flex items-center gap-1.5 rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-sm font-medium text-teal-700 transition hover:border-teal-400 hover:bg-teal-100">
        <flux:icon icon="book-open" class="h-4 w-4" />
        راهنمای همین بخش
    </a>
@endif
