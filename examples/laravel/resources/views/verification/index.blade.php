@extends('layouts.app')

@section('content')
  <p>Enter your phone number in international format (no leading +). MyOTP will send a 6-digit code.</p>

  <form method="POST" action="{{ route('phone.send') }}">
    @csrf
    <label>
      <span>Phone number</span>
      <input name="phone" type="tel" inputmode="numeric" placeholder="14155551234" value="{{ old('phone') }}" required />
    </label>

    <fieldset>
      <legend>Channel</legend>
      <label class="inline"><input type="radio" name="channel" value="sms" {{ old('channel', 'sms') === 'sms' ? 'checked' : '' }} /> SMS</label>
      <label class="inline"><input type="radio" name="channel" value="whatsapp" {{ old('channel') === 'whatsapp' ? 'checked' : '' }} /> WhatsApp</label>
      <label class="inline"><input type="radio" name="channel" value="telegram" {{ old('channel') === 'telegram' ? 'checked' : '' }} /> Telegram</label>
    </fieldset>

    @if (! empty($error))
      <p class="error">{{ $error }}</p>
    @elseif ($errors->any())
      <p class="error">{{ $errors->first() }}</p>
    @endif

    <button type="submit">Send code</button>
  </form>
@endsection
