@extends('layouts.app')

@section('heading', 'Verified')

@section('content')
  <div class="success">
    <p>Phone number <strong>+{{ $phone }}</strong> has been verified.</p>
    <p><a href="{{ route('phone.form') }}">Verify another number</a></p>
  </div>
@endsection
