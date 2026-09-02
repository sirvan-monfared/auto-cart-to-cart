@extends('cardpay::setup.layout')

@section('steps')
    <div class="steps" aria-hidden="true">
        @for ($i = 1; $i <= 4; $i++)
            <div class="step done">step {{ $i }}</div>
        @endfor
    </div>
@endsection

@section('content')
    <h2>Installation complete</h2>
    <div class="success">
        CardPay is installed and locked. The setup wizard is now permanently disabled.
    </div>

    @if ($application_created)
        <div class="warn">
            <strong>Write these merchant credentials down NOW — the secret is shown only this once.</strong>
        </div>
        <div class="secret-box mono">
            Application public key: <strong>{{ $public_key }}</strong><br>
            API public key: <strong>{{ $api_public_key ?? '—' }}</strong><br>
            API secret: <strong>{{ $secret }}</strong>
        </div>
        <p class="sub">Store the secret in your merchant integration immediately. It cannot be recovered later — only rotated.</p>
    @else
        <div class="success">
            The default application already existed — its credentials are unchanged.
        </div>
    @endif

    <p class="sub" style="margin-top:18px;">
        <strong>Reminder:</strong> if you supplied database credentials different from your .env file,
        update <code>.env</code> now to match (the installer never edits .env). APP_KEY was not touched.
    </p>

    <a class="btn" href="{{ cardpay_route('dashboard') }}">Go to CardPay panel →</a>
@endsection
