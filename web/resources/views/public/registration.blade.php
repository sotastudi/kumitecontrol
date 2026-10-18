@extends('layouts.app')
@section('title', 'Inscripción · '.$event->name)
@section('content')
<h1 class="h3 mb-3">Inscripción: {{ $event->name }}</h1>
<div class="alert alert-info">Inscribes como club <strong>{{ $clubName }}</strong>. Los datos de contacto y la fecha de nacimiento no se publican; la foto solo se muestra si el organizador lo permite y se marca en cada ficha.</div>
<section class="card mb-4" id="plantel">
    <div class="card-body">
        <h2 class="h5">Agregar desde mis competidores</h2>
        @if (count($roster) === 0)
            <p class="text-muted mb-0">Aún no tienes competidores guardados. <a href="{{ route('panel.athletes.index') }}">Añádelos en «Mis competidores»</a> y podrás agregarlos a una categoría con un clic, sin rellenar el formulario.</p>
        @else
            <p class="text-muted small">Pulsa en la categoría para inscribir al competidor (queda pendiente de confirmar). Solo se ofrecen las categorías cuyos requisitos cumple; las demás aparecen apagadas: púlsalas para ver el motivo.</p>
            <div class="list-group list-group-flush">
                @foreach ($roster as $row)
                    @php $athlete = $row['athlete']; @endphp
                    <div class="list-group-item px-0">
                        <div class="fw-semibold">{{ $athlete->displayName() }} <span class="text-muted fw-normal small">· {{ $athlete->birth_date->format('d/m/Y') }} · {{ $athlete->sex->label() }}@if ($athlete->weight !== null) · {{ $athlete->weight }} kg @endif · {{ $athlete->level }}</span></div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            @foreach ($row['options'] as $option)
                                @if ($option['registered'])
                                    <span class="badge text-bg-success align-self-center">Inscrito en {{ $option['category']->name }}</span>
                                @elseif ($option['problems'] === [])
                                    <form method="POST" action="{{ route('public.registration.roster', $event) }}">@csrf
                                        <input type="hidden" name="athlete_id" value="{{ $athlete->id }}"><input type="hidden" name="category_id" value="{{ $option['category']->id }}">
                                        <button class="btn btn-outline-primary btn-sm btn-icon"><svg width="14" height="14" aria-hidden="true"><use href="#plus"/></svg> Agregar a {{ $option['category']->name }}</button>
                                    </form>
                                @else
                                    {{-- No disponible: un botón apagado (no «disabled», para poder pulsarlo) que al pulsarse muestra el motivo. --}}
                                    <button type="button" class="btn btn-outline-secondary btn-sm opacity-50" data-bs-toggle="collapse" data-bs-target="#why-{{ $athlete->id }}-{{ $option['category']->id }}" aria-expanded="false" aria-controls="why-{{ $athlete->id }}-{{ $option['category']->id }}">{{ $option['category']->name }}</button>
                                @endif
                            @endforeach
                        </div>
                        @foreach ($row['options']->filter(fn ($o) => ! $o['registered'] && $o['problems'] !== []) as $option)
                            <div class="collapse small text-danger mt-1" id="why-{{ $athlete->id }}-{{ $option['category']->id }}"><strong>{{ $option['category']->name }}:</strong> no disponible, {{ lcfirst(implode('; ', $option['problems'])) }}.</div>
                        @endforeach
                        @if ($row['options']->reject(fn ($o) => $o['registered'])->isNotEmpty() && $row['options']->reject(fn ($o) => $o['registered'])->every(fn ($o) => $o['problems'] !== []))
                            <div class="small text-muted mt-1">No cumple los requisitos de ninguna categoría disponible (pulsa en cada una para ver el motivo).</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
<h2 class="h5">O inscribe a un competidor nuevo</h2>
@if ($categories->isEmpty())
    <div class="alert alert-warning">Este evento aún no tiene categorías disponibles.</div>
@endif
<form method="POST" enctype="multipart/form-data" action="{{ route('public.registration.store', $event) }}" @include('panel.competitors._form_data', ['categoryModels' => $categories])>
    @csrf
    <x-select name="category_id" label="Categoría" :options="$categories->pluck('name', 'id')" placeholder="Selecciona una categoría" required />
    @include('panel.competitors._fields', ['lockedClub' => $clubName])
    <div data-guardian-section>
        <h2 class="h5 mt-4">Datos del representante legal (obligatorios al ser menor de edad)</h2>
        @include('panel.competitors._guardian')
        <x-check name="guardian_consent" label="Declaro ser el padre, madre o representante legal del menor y consiento su inscripción y el tratamiento de sus datos para este evento." />
        @error('guardian_consent')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
        <p class="small text-muted">Los datos del representante no se publican.</p>
    </div>
    <button type="submit" class="btn btn-primary">Enviar inscripción</button>
    <a href="{{ route('public.events.show', $event) }}" class="btn btn-link">Cancelar</a>
</form>
@endsection
