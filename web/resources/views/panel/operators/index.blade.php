@extends('layouts.app')
@section('title', 'Mis controladores')
@section('content')
@php $creating = old('_form') === 'create'; @endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h1 class="h3 mb-0">Mis controladores</h1>
    <button type="button" class="btn btn-primary btn-sm btn-icon" data-bs-toggle="collapse" data-bs-target="#create-operator" aria-expanded="{{ $creating ? 'true' : 'false' }}" aria-controls="create-operator"><svg width="16" height="16" aria-hidden="true"><use href="#plus"/></svg> Nuevo controlador</button>
</div>
<p class="text-muted">Cuentas de usuario y contraseña con las que tus controladores puntúan el combate actual de los tatamis que les asignes. Una misma cuenta sirve para todos tus eventos.</p>

@if ($errors->any() && old('_form') !== 'create')
    <div class="alert alert-danger" role="alert">
        @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('panel.my-operators.store') }}" class="collapse{{ $creating ? ' show' : '' }} mb-4" id="create-operator">
    @csrf
    <input type="hidden" name="_form" value="create">
    <div class="card operator-create">
        <div class="card-header d-flex align-items-center gap-2">
            <svg width="20" height="20" aria-hidden="true"><use href="#user"/></svg>
            <strong>Nueva cuenta de controlador</strong>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-6"><x-field name="username" label="Usuario" :value="old('username')" required autocomplete="off" help="Letras minúsculas, números, punto, guion y guion bajo." /></div>
                <div class="col-12 col-md-6">
                    <label for="password" class="form-label">Contraseña <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="text" id="password" name="password" value="{{ old('password') }}" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" minlength="6" required>
                        <button type="button" class="btn btn-outline-secondary" data-generate-password="#password" title="Generar una contraseña aleatoria">Generar</button>
                        <button type="button" class="btn btn-outline-secondary" data-copy-password="#password" title="Copiar la contraseña" aria-label="Copiar la contraseña">Copiar</button>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-text">Mínimo 6. Cópiala antes de guardar: después no se puede ver.</div>
                </div>
                <div class="col-12 col-md-6"><x-field name="name" label="Nombre (opcional)" :value="old('name')" help="Si lo dejas vacío se usa el usuario." /></div>
                <div class="col-12 col-md-6"><x-field name="email" type="email" label="Correo (opcional)" :value="old('email')" help="Sin correo no hay «recuperar contraseña»: se la cambias tú." /></div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#create-operator">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-icon"><svg width="16" height="16" aria-hidden="true"><use href="#plus"/></svg> Crear cuenta</button>
        </div>
    </div>
</form>

@if ($operators->isEmpty())
    <p class="text-muted">Aún no tienes controladores. Crea una cuenta con «Nuevo controlador».</p>
@else
    <div class="d-flex flex-column gap-3">
        @foreach ($operators as $operator)
            @php
                $own = (int) $operator->created_by_user_id === (int) $organizerId;
                $byEvent = $operator->tatamiOperations->groupBy('event_id');
            @endphp
            <div class="card" id="operator-{{ $operator->id }}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <strong>{{ $operator->name }}</strong>
                            <span class="text-muted">{{ $operator->username ? '@'.$operator->username : '' }}{{ $operator->email ? ' · '.$operator->email : '' }}</span>
                            <div class="mt-2">
                                @forelse ($byEvent as $rows)
                                    <div class="small">
                                        <span class="fw-semibold">{{ $rows->first()->event->name }}</span>:
                                        @foreach ($rows->sortBy('tatami') as $row)<span class="badge text-bg-secondary">Tatami {{ $row->tatami }}</span> @endforeach
                                    </div>
                                @empty
                                    <span class="small text-muted">Sin asignar a ningún evento.</span>
                                @endforelse
                            </div>
                        </div>
                        @if ($own)
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-yellow btn-sm btn-icon" data-bs-toggle="collapse" data-bs-target="#edit-operator-{{ $operator->id }}" aria-expanded="false" aria-controls="edit-operator-{{ $operator->id }}"><svg width="16" height="16" aria-hidden="true"><use href="#pencil"/></svg> Editar</button>
                                <form method="POST" action="{{ route('panel.my-operators.destroy', $operator) }}" onsubmit="return confirm('¿Eliminar la cuenta «{{ $operator->username }}»? Perderá el acceso a todos tus eventos.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar</button>
                                </form>
                            </div>
                        @endif
                    </div>
                    @unless ($own)
                        <p class="small text-muted mb-0 mt-2">Esta cuenta no la creaste tú: no puedes cambiar sus credenciales.</p>
                    @endunless

                    {{-- Aquí irá la asignación por evento (tarea 2). --}}

                    @if ($own)
                        <form method="POST" action="{{ route('panel.my-operators.update', $operator) }}" class="collapse mt-3 row g-2 align-items-start" id="edit-operator-{{ $operator->id }}">
                            @csrf
                            @method('PUT')
                            <div class="col-12 col-md-3"><label class="form-label" for="eu-{{ $operator->id }}">Usuario</label><input type="text" class="form-control" id="eu-{{ $operator->id }}" name="username" value="{{ $operator->username }}" required autocomplete="off"></div>
                            <div class="col-12 col-md-3"><label class="form-label" for="en-{{ $operator->id }}">Nombre</label><input type="text" class="form-control" id="en-{{ $operator->id }}" name="name" value="{{ $operator->name }}"></div>
                            <div class="col-12 col-md-3"><label class="form-label" for="ee-{{ $operator->id }}">Correo (opcional)</label><input type="email" class="form-control" id="ee-{{ $operator->id }}" name="email" value="{{ $operator->email }}"></div>
                            <div class="col-12 col-md-3">
                                <label class="form-label" for="new-password-{{ $operator->id }}">Nueva contraseña</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="new-password-{{ $operator->id }}" name="password" minlength="6" placeholder="Sin cambios" autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" data-generate-password="#new-password-{{ $operator->id }}" title="Generar una contraseña aleatoria">Generar</button>
                                    <button type="button" class="btn btn-outline-secondary" data-copy-password="#new-password-{{ $operator->id }}" title="Copiar la contraseña" aria-label="Copiar la contraseña">Copiar</button>
                                </div>
                            </div>
                            <div class="col-12"><button type="submit" class="btn btn-outline-primary btn-sm">Guardar cambios</button></div>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
