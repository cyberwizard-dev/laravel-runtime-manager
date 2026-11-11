@extends('runtime-manager::layout')
@section('title','Sign in')
@section('chrome','1')
@section('content')
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px">
  <div class="card" style="width:360px;max-width:100%">
    <h1 style="margin-top:0">⚙ Runtime Manager</h1>
    @if(! $configured)
      <div class="banner warn">No dashboard password is set. Run <span class="mono">php artisan runtime:install</span> to create one.</div>
    @endif
    @if($errors->any())
      <div class="banner fail">{{ $errors->first('password') }}</div>
    @endif
    <form method="post" action="{{ route('runtime.login.submit') }}">
      @csrf
      <label class="muted" for="password">Dashboard password</label>
      <input id="password" type="password" name="password" autofocus autocomplete="current-password"
             style="width:100%;margin:6px 0 14px" required>
      <button class="btn primary" type="submit" style="width:100%">Sign in</button>
    </form>
    <p class="muted" style="margin:14px 0 0;font-size:12px">This dashboard is independent of your application's login.</p>
  </div>
</div>
@endsection
