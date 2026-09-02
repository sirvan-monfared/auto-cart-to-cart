@extends('cardpay::setup.layout')

@section('steps')
    <div class="steps" aria-hidden="true">
        @for ($i = 1; $i <= 4; $i++)
            <div class="step {{ $i <= 2 ? 'done' : ($i === 3 ? 'active' : '') }}">step {{ $i }}</div>
        @endfor
    </div>
@endsection

@section('content')
    @if (session('setup_error'))
        <div class="error">{{ session('setup_error') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    @if ($hasAdmin)
        <h2>Step 3 — Admin account</h2>
        <div class="success">
            An active admin account already exists — this step is skipped.
            Nothing will be overwritten (adapt-safely policy).
        </div>
        <a class="btn" href="{{ cardpay_setup_route('finalize') }}">Continue → Step 4</a>
    @else
        <h2>Step 3 — Create the administrator</h2>
        <p class="sub">This account signs in to the admin panel. Password must be at least 10 characters.</p>

        <form method="POST" action="{{ cardpay_setup_route('admin.store') }}">
            @csrf
            <label for="name">Full name</label>
            <input id="name" type="text" name="name" required maxlength="120" value="{{ old('name') }}">

            <label for="username">Username</label>
            <input id="username" type="text" name="username" required minlength="3" maxlength="190"
                   value="{{ old('username') }}" autocomplete="username">

            <label for="mobile">Mobile (optional)</label>
            <input id="mobile" type="text" name="mobile" maxlength="30" value="{{ old('mobile') }}">

            <label for="password">Password (min 10 chars)</label>
            <input id="password" type="password" name="password" required minlength="10" autocomplete="new-password">

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   required minlength="10" autocomplete="new-password">

            <button class="btn" type="submit">Create admin → Step 4</button>
        </form>
    @endif
@endsection
