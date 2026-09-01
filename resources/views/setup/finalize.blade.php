@extends('cardpay::setup.layout')

@section('steps')
    <div class="steps" aria-hidden="true">
        @for ($i = 1; $i <= 4; $i++)
            <div class="step {{ $i <= 3 ? 'done' : ($i === 4 ? 'active' : '') }}">step {{ $i }}</div>
        @endfor
    </div>
@endsection

@section('content')
    <h2>Step 4 — Store settings & finish</h2>
    <p class="sub">These values brand the customer checkout page. All fields are optional — sensible defaults are already seeded.</p>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('setup.finalize.complete') }}">
        @csrf
        <label for="title">Checkout title (Persian)</label>
        <input id="title" type="text" name="title" maxlength="500" value="{{ old('title', 'پرداخت امن کارت به کارت') }}">

        <label for="currency">Currency</label>
        <input id="currency" type="text" name="currency" maxlength="10" value="{{ old('currency', 'IRR') }}">

        <label for="timezone">Display timezone (IANA)</label>
        <input id="timezone" type="text" name="timezone" maxlength="64" value="{{ old('timezone', 'Asia/Tehran') }}">

        <label for="primary_color">Primary color</label>
        <input id="primary_color" type="text" name="primary_color" placeholder="#155EEF"
               pattern="#[0-9a-fA-F]{6}" value="{{ old('primary_color', '#155EEF') }}">

        <label for="accent_color">Accent color</label>
        <input id="accent_color" type="text" name="accent_color" placeholder="#12B76A"
               pattern="#[0-9a-fA-F]{6}" value="{{ old('accent_color', '#12B76A') }}">

        <button class="btn" type="submit">Finish installation</button>
    </form>
@endsection
