@extends('cardpay::setup.layout')

@section('steps')
    <div class="steps" aria-hidden="true">
        @for ($i = 1; $i <= 4; $i++)
            <div class="step {{ $i <= 1 ? 'done' : ($i === 2 ? 'active' : '') }}">step {{ $i }}</div>
        @endfor
    </div>
@endsection

@section('content')
    <h2>Step 2 — Database</h2>
    <p class="sub">The connection is tested before anything is written. Migrations run only when the schema is absent.</p>

    @if (session('setup_error'))
        <div class="error">{{ session('setup_error') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('setup.database') }}">
        @csrf
        <label for="db_host">Host</label>
        <input id="db_host" type="text" name="db_host" required value="{{ old('db_host', '127.0.0.1') }}">

        <label for="db_port">Port</label>
        <input id="db_port" type="number" name="db_port" required value="{{ old('db_port', 3306) }}">

        <label for="db_database">Database name</label>
        <input id="db_database" type="text" name="db_database" required value="{{ old('db_database') }}">

        <label for="db_username">Username</label>
        <input id="db_username" type="text" name="db_username" required autocomplete="off" value="{{ old('db_username') }}">

        <label for="db_password">Password</label>
        <input id="db_password" type="password" name="db_password" autocomplete="new-password">

        <button class="btn" type="submit">Test & install schema → Step 3</button>
    </form>
@endsection
