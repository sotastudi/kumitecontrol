@extends('layouts.app')
@section('title', 'Clubes · '.$event->name)
@section('content')
<a href="{{ route('panel.events.show', $event) }}" class="small">&larr; {{ $event->name }}</a>
<h1 class="h3 mb-3">Clubes registrados</h1>
<p class="text-muted">Solo los clubes aprobados pueden inscribir competidores en el formulario público. Tus altas manuales no dependen de esta lista.</p>
@php $formErrors = collect($errors->messages())->except('invite_email')->flatten(); @endphp
@if ($formErrors->isNotEmpty())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($formErrors as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif
@if (session('generated_password'))
    @php $generated = session('generated_password'); @endphp
    <div class="alert alert-warning">
        <strong>Contraseña generada para «{{ $generated['club'] }}»:</strong> <code class="user-select-all fs-5">{{ $generated['password'] }}</code>
        <div class="small">Acceso con el correo <strong>{{ $generated['email'] }}</strong>. Cópiala y entrégasela al club: no se vuelve a mostrar.</div>
    </div>
@endif
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <form method="POST" action="{{ route('panel.clubs.add', $event) }}" class="card h-100">@csrf
            <div class="card-body">
                <h2 class="h6">Añadir un club que ya conoces</h2>
                @if ($knownClubs->isEmpty())
                    <p class="text-muted small mb-0">No hay clubes tuyos disponibles: aparecen aquí los que has creado o los aprobados en otros eventos tuyos y que aún no están en este.</p>
                @else
                    <div class="input-group">
                        <select name="user_id" class="form-select" aria-label="Club" required>
                            @foreach ($knownClubs as $known)<option value="{{ $known->id }}">{{ $known->name }}</option>@endforeach
                        </select>
                        <button class="btn btn-primary">Añadir y aprobar</button>
                    </div>
                @endif
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <form method="POST" action="{{ route('panel.clubs.invite', $event) }}" class="card h-100">@csrf
            <div class="card-body">
                <h2 class="h6">Invitar a un club que ya tiene cuenta</h2>
                <div class="input-group">
                    <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('invite_email') is-invalid @enderror" placeholder="Correo del club" aria-label="Correo del club" required>
                    <button class="btn btn-outline-primary">Enviar invitación</button>
                    @error('invite_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-text">Recibirá un correo y un aviso en su cuenta para confirmar; hasta entonces no participa.</div>
            </div>
        </form>
    </div>
</div>
<button type="button" class="btn btn-primary btn-sm btn-icon mb-3" data-bs-toggle="collapse" data-bs-target="#create-club" aria-expanded="{{ old('_form') === 'create' ? 'true' : 'false' }}" aria-controls="create-club"><svg width="16" height="16" aria-hidden="true"><use href="#plus"/></svg> Crear cuenta de club</button>
<form method="POST" action="{{ route('panel.clubs.store', $event) }}" class="collapse{{ old('_form') === 'create' ? ' show' : '' }} mb-4" id="create-club">@csrf <input type="hidden" name="_form" value="create">
    <div class="card">
        <div class="card-body">
            <p class="text-muted small">Crea la cuenta de un club y queda aprobado en este evento. Entrega al club su correo y contraseña; si el correo ya es de una cuenta de club, solo se aprueba en el evento y no se cambia su contraseña.</p>
            <div class="row">
                <div class="col-md-4"><x-field name="name" label="Nombre del club" required /></div>
                <div class="col-md-4"><x-field name="email" type="email" label="Correo electrónico" required /></div>
                <div class="col-md-4"><x-field name="password" type="password" label="Contraseña" help="Mínimo 8 caracteres" autocomplete="new-password" /></div>
            </div>
            <x-check name="generate_password" label="Generar contraseña automática (se muestra una sola vez)" />
            <button class="btn btn-primary">Crear y aprobar</button>
        </div>
    </div>
</form>

@if ($clubs->isEmpty())
    <p class="text-muted">Ningún club se ha registrado todavía en este evento.</p>
@else
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Club</th><th>Color</th><th>Correo</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            @foreach ($clubs as $club)
                <tr>
                    <td>@if ($club->isApproved() && $club->user->hasLogo())<img src="{{ route('public.clubs.logo', [$event, $club]) }}?v={{ $club->user->updated_at?->timestamp }}" alt="Logo de {{ $club->user->name }}" class="club-logo me-2">@endif{{ $club->user->name }}</td>
                    <td>
                        @php($shown = $event->clubColor($club->user->name))
                        <form method="POST" action="{{ route('panel.clubs.color', [$event, $club]) }}" class="d-flex align-items-center gap-2">@csrf @method('PUT')
                            <input type="color" name="color" value="{{ $club->color ?? $shown ?? '#0072b2' }}" class="form-control form-control-color" aria-label="Color de {{ $club->user->name }}" oninput="document.getElementById('club-auto-{{ $club->id }}').checked = false">
                            <div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="club-auto-{{ $club->id }}" name="auto_color" value="1" @checked(! $club->color)><label class="form-check-label small" for="club-auto-{{ $club->id }}">Auto</label></div>
                            <button class="btn btn-sm btn-outline-primary">Guardar</button>
                        </form>
                    </td>
                    <td>{{ $club->user->email }}</td>
                    <td><span class="badge {{ $club->status->badgeClass() }}">{{ $club->status->label() }}</span></td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('panel.clubs.update', [$event, $club]) }}" class="d-inline">@csrf @method('PUT')
                            @if (! in_array($club->status, [\App\Enums\ClubStatus::Approved, \App\Enums\ClubStatus::Invited], true))
                                <button name="status" value="approved" class="btn btn-sm btn-success">Aprobar</button>
                            @endif
                            @if ($club->status !== \App\Enums\ClubStatus::Rejected)
                                <button name="status" value="rejected" class="btn btn-sm btn-outline-secondary">Rechazar</button>
                            @endif
                        </form>
                        @if ($club->status === \App\Enums\ClubStatus::Rejected)
                            <form method="POST" action="{{ route('panel.clubs.destroy', [$event, $club]) }}" class="d-inline" onsubmit="return confirm('¿Eliminar este club de la lista del evento?')">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                            </form>
                        @endif
                        @if ($club->status === \App\Enums\ClubStatus::Invited)
                            <form method="POST" action="{{ route('panel.clubs.resend', [$event, $club]) }}" class="d-inline">@csrf
                                <button class="btn btn-sm btn-outline-primary">Reenviar invitación</button>
                            </form>
                        @endif
                        @if ($club->user->created_by_user_id === auth()->id())
                            <button type="button" class="btn btn-sm btn-yellow" data-bs-toggle="collapse" data-bs-target="#edit-club-{{ $club->id }}" aria-expanded="false" aria-controls="edit-club-{{ $club->id }}">Editar</button>
                        @endif
                    </td>
                </tr>
                @if ($club->user->created_by_user_id === auth()->id())
                    <tr class="collapse" id="edit-club-{{ $club->id }}">
                        <td colspan="5">
                            <form method="POST" enctype="multipart/form-data" action="{{ route('panel.clubs.account', [$event, $club]) }}" class="row g-2 align-items-start">@csrf @method('PUT')
                                <div class="col-md-4"><label class="form-label small mb-1" for="club-name-{{ $club->id }}">Nombre del club</label><input id="club-name-{{ $club->id }}" name="name" value="{{ $club->user->name }}" class="form-control" required></div>
                                <div class="col-md-4"><label class="form-label small mb-1" for="club-email-{{ $club->id }}">Correo</label><input id="club-email-{{ $club->id }}" type="email" name="email" value="{{ $club->user->email }}" class="form-control" required></div>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1" for="club-pass-{{ $club->id }}">Nueva contraseña</label>
                                    <input id="club-pass-{{ $club->id }}" type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Dejar vacío para no cambiarla">
                                    <div class="form-check mt-1"><input type="hidden" name="generate_password" value="0"><input type="checkbox" class="form-check-input" id="club-gen-{{ $club->id }}" name="generate_password" value="1"><label class="form-check-label small" for="club-gen-{{ $club->id }}">Generar contraseña automática</label></div>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small mb-1" for="club-logo-{{ $club->id }}">Logo (JPG, PNG o WebP, máx. 2 MB)</label>
                                    <input id="club-logo-{{ $club->id }}" type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="form-control">
                                    @if ($club->user->hasLogo())
                                        <div class="d-flex align-items-center gap-3 mt-2">
                                            @if ($club->isApproved())<img src="{{ route('public.clubs.logo', [$event, $club]) }}?v={{ $club->user->updated_at?->timestamp }}" alt="Logo actual" class="img-thumbnail" style="max-height: 4rem">@endif
                                            <div class="form-check"><input type="hidden" name="remove_logo" value="0"><input type="checkbox" class="form-check-input" id="club-rm-{{ $club->id }}" name="remove_logo" value="1"><label class="form-check-label small" for="club-rm-{{ $club->id }}">Quitar el logo actual</label></div>
                                        </div>
                                    @endif
                                </div>
                                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary">Guardar</button></div>
                            </form>
                        </td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
