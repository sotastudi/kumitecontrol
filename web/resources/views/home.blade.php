@extends('layouts.app')
@section('content')
    <h1 class="h3">Campeonatos de artes marciales</h1>
    <p class="text-muted">Consulta los eventos publicados o accede como organizador.</p>
    @php
        $hasFilters = filled($filters['q']) || filled($filters['desde']) || filled($filters['hasta']);
        $keep = array_filter(['q' => $filters['q'], 'desde' => $filters['desde'], 'hasta' => $filters['hasta']]);
    @endphp
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">{{ $showingPast ? 'Eventos pasados' : 'Próximos eventos' }}</h2>
        @if ($showingPast)
            <a href="{{ route('home', $keep) }}" class="btn btn-outline-primary btn-sm">Ver próximos eventos</a>
        @else
            <a href="{{ route('home', $keep + ['pasados' => 1]) }}" class="btn btn-outline-secondary btn-sm">Ver eventos pasados</a>
        @endif
    </div>
    <form method="GET" action="{{ route('home') }}" class="row g-2 align-items-end mb-4" role="search">
        @if ($showingPast)<input type="hidden" name="pasados" value="1">@endif
        <div class="col-12 col-md-5">
            <label for="filter-q" class="form-label small mb-1">Nombre</label>
            <input type="search" id="filter-q" name="q" value="{{ $filters['q'] }}" maxlength="100" class="form-control" placeholder="Buscar por nombre">
        </div>
        <div class="col-6 col-md-2">
            <label for="filter-desde" class="form-label small mb-1">Desde</label>
            <input type="date" id="filter-desde" name="desde" value="{{ $filters['desde'] }}" class="form-control">
        </div>
        <div class="col-6 col-md-2">
            <label for="filter-hasta" class="form-label small mb-1">Hasta</label>
            <input type="date" id="filter-hasta" name="hasta" value="{{ $filters['hasta'] }}" class="form-control">
        </div>
        <div class="col-12 col-md-3 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1">Filtrar</button>
            @if ($hasFilters)<a href="{{ route('home', $showingPast ? ['pasados' => 1] : []) }}" class="btn btn-outline-secondary">Limpiar</a>@endif
        </div>
    </form>
    @if ($dateError)
        <div class="alert alert-warning py-2" role="alert">El periodo de fechas no válido se ha ignorado: «desde» debe ser anterior o igual a «hasta».</div>
    @endif
    @forelse ($events as $event)
        @if ($loop->first)<div class="row g-4">@endif
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <a href="{{ route('public.events.show', $event) }}" class="text-decoration-none">
                    @if ($event->hasPoster())
                        <img src="{{ route('public.events.poster', $event) }}" class="card-img-top bg-body-secondary" style="max-height: 400px; object-fit: contain" alt="Póster de {{ $event->name }}" loading="lazy">
                    @else
                        <div class="card-img-top bg-body-secondary d-flex align-items-center justify-content-center text-muted" style="height: 200px">Sin póster</div>
                    @endif
                </a>
                <div class="card-body d-flex flex-column">
                    <h2 class="h5 card-title">{{ $event->name }}</h2>
                    <p class="card-text text-muted small mb-3">
                        {{ $event->venue }}<br>
                        {{ $event->startsAtLabel() }}
                    </p>
                    <div class="mt-auto">
                        @if ($event->acceptsPublicRegistrations())
                            <a href="{{ route('public.registration.create', $event) }}" class="btn btn-primary w-100">Inscribirse</a>
                        @else
                            <a href="{{ route('public.events.show', $event) }}" class="btn btn-outline-primary w-100">Ver evento</a>
                        @endif
                        @php $dateNote = $event->registrationDateNote(); @endphp
                        @if ($dateNote)
                            <div class="small text-muted text-center mt-2 registration-date">
                                @if ($dateNote['type'] === 'closes')
                                    Inscripciones abiertas hasta el {{ $dateNote['at']->translatedFormat('j \d\e F \d\e Y, H:i') }}
                                @else
                                    Las inscripciones abren el {{ $dateNote['at']->translatedFormat('j \d\e F \d\e Y, H:i') }}
                                @endif
                            </div>
                            @if (! empty($dateNote['countdown']))
                                @include('partials.registration-countdown', ['closesAt' => $dateNote['at']])
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @if ($loop->last)</div>@endif
    @empty
        <p class="text-muted">
            {{ $hasFilters ? 'Ningún evento coincide con el filtro.' : ($showingPast ? 'No hay eventos pasados.' : 'No hay próximos eventos.') }}
            <a href="{{ $showingPast ? route('home', $keep) : route('home', $keep + ['pasados' => 1]) }}">{{ $showingPast ? 'Ver próximos eventos' : 'Ver eventos pasados' }}</a>
        </p>
    @endforelse
    <div class="mt-4">{{ $events->links() }}</div>
@endsection
