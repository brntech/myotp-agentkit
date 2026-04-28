@extends('layouts.app')

@section('heading', 'Enter code')

@section('content')
  <p>We sent a code via <strong>{{ $channel }}</strong> to <strong>+{{ $phone }}</strong>. Enter it below.</p>

  <form method="POST" action="{{ route('verify.submit') }}">
    @csrf
    <label>
      <span>Verification code</span>
      <input
        name="otp"
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="8"
        required
      />
    </label>

    @if (! empty($error))
      <p class="error">{{ $error }}</p>
    @elseif ($errors->any())
      <p class="error">{{ $errors->first() }}</p>
    @endif

    <button type="submit">Verify</button>
  </form>
@endsection
