@extends('cardpay::setup.layout', ['stepNumber' => 1])

@section('steps')
    <div class="steps" aria-hidden="true">
        @for ($i = 1; $i <= 4; $i++)
            <div class="step {{ $i < 2 ? 'done' : ($i === 2 ? 'active' : '') }}">step {{ $i }}</div>
        @endfor
    </div>
@endsection

@section('content')
    <h2>Step 1 — Server requirements</h2>

    @unless ($requirementsOk)
        <div class="error">Fix the failing checks below, then reload this page to continue.</div>
    @endunless

    <ul class="checks">
        <li>
            PHP version
            <span class="{{ $requirements['php_ok'] ? 'ok' : 'bad' }}">
                {{ $requirements['php_version'] }} {{ $requirements['php_ok'] ? 'OK (≥ 8.3)' : '— too old' }}
            </span>
        </li>
        @foreach ($requirements['extensions'] as $ext)
            <li>Extension: <code>{{ $ext['name'] }}</code>
                <span class="{{ $ext['ok'] ? 'ok' : 'bad' }}">{{ $ext['ok'] ? 'loaded' : 'MISSING' }}</span>
            </li>
        @endforeach
        @foreach ($requirements['writable'] as $dir)
            <li>Writable: <code>{{ str_replace(base_path().'/', '', $dir['path']) }}</code>
                <span class="{{ $dir['ok'] ? 'ok' : 'bad' }}">{{ $dir['ok'] ? 'yes' : 'NO' }}</span>
            </li>
        @endforeach
        <li>
            APP_KEY present in .env
            <span class="{{ $requirements['app_key_set'] ? 'ok' : 'bad' }}">
                {{ $requirements['app_key_set'] ? 'set — secrets will be encrypted under it' : 'NOT SET — run `php artisan key:generate` first' }}
            </span>
        </li>
    </ul>

    @if ($dbMigrated || $hasAdmin || $hasHostUsers)
        <div class="warn">
            Existing installation state detected:
            @if ($dbMigrated) database tables already exist @endif
            @if ($dbMigrated && ($hasAdmin || $hasHostUsers)) and @endif
            @if ($hasHostUsers) host user accounts already exist (admin step will be skipped) @elseif ($hasAdmin) an active admin account already exists @endif.
            The installer will only fill in what is missing — nothing existing will be overwritten.
        </div>
    @endif

    @if ($requirementsOk)
        <a class="btn" href="{{ cardpay_setup_route('admin') }}">Continue → Step 2</a>
    @endif
@endsection
