@extends('layouts.app')
@section('title', 'Restablecer contraseña')
@section('content')
<div class="col-md-6 col-lg-5 mx-auto">
    <h1 class="h3 mb-3">Restablecer contraseña</h1>
    <form method="POST" action="{{ route('password.update') }}">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" type="email" label="Correo electrónico" :value="$email" :readonly="filled($email)" required />
        <x-field name="password" type="password" label="Nueva contraseña" help="Mínimo 8 caracteres" required />
        <x-field name="password_confirmation" type="password" label="Repite la contraseña" required />
        <button class="btn btn-primary w-100">Restablecer contraseña</button>
    </form>
</div>
@endsection
