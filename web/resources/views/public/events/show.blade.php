@extends('layouts.app')
@section('title', $event->name)
@section('content')
<section class="event-hero row g-4 align-items-start mb-4">
    @if ($event->hasPoster())
        <div class="col-12 col-md-5 col-lg-4">
            <img src="{{ route('public.events.poster', $event) }}" alt="Póster de {{ $event->name }}" class="img-fluid rounded shadow-sm w-100 event-poster">
        </div>
    @endif
    <div class="col-12 {{ $event->hasPoster() ? 'col-md-7 col-lg-8' : '' }} event-info">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="badge text-bg-dark">{{ $event->discipline }}</span>
            <span class="badge text-bg-secondary">{{ $event->status->label() }}</span>
        </div>
        <h1 class="display-6 fw-bold mb-3">{{ $event->name }}</h1>

        <ul class="list-unstyled event-facts mb-3">
            <li class="d-flex align-items-start gap-2 mb-2">
                <svg width="22" height="22" class="flex-shrink-0 text-body-secondary" aria-hidden="true"><use href="#calendar"/></svg>
                <span><span class="d-block small text-uppercase text-muted">Fecha</span><strong>{{ $event->startsAtLabel('l, j \d\e F \d\e Y') }}</strong></span>
            </li>
            <li class="d-flex align-items-start gap-2 mb-2">
                <svg width="22" height="22" class="flex-shrink-0 text-body-secondary" aria-hidden="true"><use href="#pin"/></svg>
                <span><span class="d-block small text-uppercase text-muted">Lugar</span><strong>{{ $event->venue }}</strong></span>
            </li>
            @if ($event->registration_opens_at || $event->registration_closes_at)
                <li class="d-flex align-items-start gap-2 mb-2">
                    <svg width="22" height="22" class="flex-shrink-0 text-body-secondary" aria-hidden="true"><use href="#clock"/></svg>
                    <span>
                        <span class="d-block small text-uppercase text-muted">Inscripciones</span>
                        <strong>
                            @if ($event->registration_opens_at)desde el {{ $event->registration_opens_at->translatedFormat('j \d\e F \d\e Y, H:i') }}@endif
                            @if ($event->registration_closes_at)hasta el {{ $event->registration_closes_at->translatedFormat('j \d\e F \d\e Y, H:i') }}@endif
                        </strong>
                    </span>
                </li>
            @endif
        </ul>

        @if ($event->description)<p class="mb-3" style="white-space: pre-line">{{ $event->description }}</p>@endif

        @if ($event->rules_text)
            <div class="event-rules mb-3">
                <h2 class="h6 text-uppercase text-muted mb-1">Normas</h2>
                <p class="mb-0" style="white-space: pre-line">{{ $event->rules_text }}</p>
            </div>
        @endif

        <div class="d-flex flex-wrap align-items-center gap-3">
            @if ($event->acceptsPublicRegistrations())
                <div>
                    <a class="btn btn-primary btn-lg" href="{{ route('public.registration.create', $event) }}">Inscribirse</a>
                    @if (! empty($event->registrationDateNote()['countdown']))
                        @include('partials.registration-countdown', ['closesAt' => $event->registration_closes_at])
                    @endif
                </div>
            @endif
            @if ($event->hasRulesPdf())
                <a class="btn btn-outline-secondary btn-icon" href="{{ route('public.events.rules', $event) }}"><svg width="16" height="16" aria-hidden="true"><use href="#download"/></svg> Descargar normas (PDF)</a>
            @endif
        </div>
    </div>
</section>
@unless ($canSeeResults)
    <div class="card mt-4">
        <div class="card-body">
            <h2 class="h5">Resultados</h2>
            <p class="text-muted">Para ver los cuadros, resultados y el estado de los tatamis necesitas el código de acceso que te facilitará el organizador.</p>
            <form method="POST" action="{{ route('public.events.access', $event) }}" class="row g-2 align-items-start">
                @csrf
                <div class="col-12 col-sm-6 col-md-4">
                    <input type="text" name="code" value="{{ old('code') }}" class="form-control text-uppercase @error('code') is-invalid @enderror" placeholder="Código de acceso" autocomplete="off" autocapitalize="characters" aria-label="Código de acceso" required>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto"><button type="submit" class="btn btn-primary">Ver resultados</button></div>
            </form>
        </div>
    </div>
@endunless
@if (collect($tatamis)->contains(fn ($t) => $t['categories']->isNotEmpty()))
    <h2 class="h5 mt-4">Tatamis ahora</h2>
    <div class="row g-3">
        @foreach ($tatamis as $tatami)
            @continue($tatami['categories']->isEmpty())
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100 tatami-block">
                    <div class="card-header"><strong>Tatami {{ $tatami['number'] }}</strong></div>
                    <div class="card-body">
                        @if ($tatami['now'])
                            @php $bout = $tatami['now']; @endphp
                            <div class="small text-muted mb-1">{{ $bout->category->name }} · Combate {{ $bout->number }}</div>
                            <div><span class="side-chip side-aka" role="img" aria-label="AKA (rojo)"></span><x-competitor-photo :competitor="$bout->slotA->competitor" :event="$event" size="sm" /> {{ $bout->slotA->competitor->displayName() }}@if ($bout->slotA->competitor->club) <small class="text-muted">(<x-club-name :club="$bout->slotA->competitor->club" :event="$event" />)</small>@endif</div>
                            <div><span class="side-chip side-shiro" role="img" aria-label="SHIRO (blanco)"></span><x-competitor-photo :competitor="$bout->slotB->competitor" :event="$event" size="sm" /> {{ $bout->slotB->competitor->displayName() }}@if ($bout->slotB->competitor->club) <small class="text-muted">(<x-club-name :club="$bout->slotB->competitor->club" :event="$event" />)</small>@endif</div>
                        @else
                            <p class="text-muted mb-0">Sin combate en este momento.</p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
@if ($categories->isNotEmpty())
    <h2 class="h5 mt-4">Cuadros y resultados</h2>
    <div class="list-group">
        @foreach ($categories as $category)
            @php
                $url = $category->is_published ? route('public.categories.show', [$event, $category]) : route('panel.brackets.show', [$event, $category]);
                $done = $category->total_bouts > 0 && $category->played_bouts === $category->total_bouts;
            @endphp
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center flex-wrap gap-2" href="{{ $url }}">
                <span>
                    {{ $category->name }}
                    @if ($category->tatami)<small class="text-muted">· Tatami {{ $category->tatami }}</small>@endif
                    @if (! $category->is_published)<span class="badge text-bg-warning">Sin publicar</span>@endif
                </span>
                <span class="small text-muted">
                    @if ($category->total_bouts === 0)
                        Cuadro sin generar
                    @elseif ($done)
                        <span class="badge text-bg-success">Finalizada</span>@if (! empty($champions[$category->id])) 🥇 {{ $champions[$category->id] }}@endif
                    @else
                        <span class="badge text-bg-primary">En curso</span> {{ $category->played_bouts }}/{{ $category->total_bouts }} combates
                    @endif
                </span>
            </a>
        @endforeach
    </div>
@endif
@endsection
