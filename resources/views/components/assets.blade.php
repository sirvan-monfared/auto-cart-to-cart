@php
    // Cache-busting via filemtime; falls back gracefully if dist was not built.
    $dist = __DIR__.'/../../../dist';
    $ver = fn (string $file): string => is_file($dist.'/'.$file) ? '?v='.md5_file($dist.'/'.$file) : '';
@endphp
<link rel="stylesheet" href="/vendor/cardpay/app.css{{ $ver('app.css') }}">
<script src="/vendor/cardpay/app.js" defer></script>
<script src="/vendor/cardpay/passkeys.js{{ $ver('passkeys.js') }}" defer></script>
