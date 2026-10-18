{{-- Combate actual de un tatami: un participante a cada lado y la puntuación en una tercera columna (en móvil, una debajo de otra).
     Requiere: $bout, $category, $scoreAction. --}}
@php
    $scoreForm = 'score-form-'.$bout->id;
@endphp
@php $locked = $bout->isCompleted() && ! $bout->isWalkover(); @endphp
@php $totalRounds = $bout->category->bouts()->where('is_bronze', false)->max('round'); @endphp
<div class="tatami-bout-wrap" @if ($locked) data-score-lock="locked" data-score-form-id="{{ $scoreForm }}" @endif>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3 tatami-bout-header">
    <span class="bout-number-tag">Combate {{ $bout->number }}</span>
    <span class="fw-semibold">{{ $bout->category->name }}</span>
    <span class="text-muted">· {{ $bout->is_bronze ? 'Bronce' : \App\Tournament\RoundLabel::for($bout->round, $totalRounds, $bout->is_prelim) }}</span>
</div>
@if ($locked)
    <div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 score-lock-banner" role="status">
        <span><strong>Resultado guardado.</strong> Para corregirlo, desbloquea la puntuación y vuelve a guardar.</span>
        <span class="d-flex gap-2">
            <button type="button" class="btn btn-warning btn-sm btn-icon" data-score-unlock>Desbloquear</button>
            @can('manage', $bout->category->event)
                <button type="button" class="btn btn-outline-danger btn-sm btn-icon" data-bs-toggle="modal" data-bs-target="#reset-modal"
                        data-reset-url="{{ route('panel.bouts.reset', [$bout->category->event, $bout->category, $bout]) }}"
                        data-reset-title="Reiniciar el resultado del combate {{ $bout->number }}"
                        data-reset-message="Se borrarán el ganador, los puntos, las faltas y los votos de los jueces del combate {{ $bout->number }}, y se retirará de las rondas siguientes a quien avanzó de él. Si algún combate posterior ya tiene resultado, antes hay que reiniciarlo."><svg width="14" height="14" aria-hidden="true"><use href="#undo"/></svg> Reiniciar</button>
            @endcan
        </span>
    </div>
@endif
<div class="row g-3 tatami-bout">
    @foreach (['a' => $bout->slotA, 'b' => $bout->slotB] as $slot => $registration)
        @php
            $opponentIppon = (bool) $bout->{($slot === 'a' ? 'b' : 'a').'_ippon'};
            $winner = $registration && $registration->id === $bout->winner_id;
        @endphp
        <div class="col-12 col-md-4">
            <div class="card h-100 tatami-competitor tatami-competitor-{{ $slot === 'a' ? 'aka' : 'shiro' }} {{ $winner ? 'is-winner' : '' }}">
                <div class="card-body position-relative">
                    @if ($registration->competitor->club && ($clubLogo = $event->clubLogoUrl($registration->competitor->club)))
                        <img src="{{ $clubLogo }}" alt="Logo de {{ $registration->competitor->club }}" class="club-logo club-logo-corner" loading="lazy">
                    @endif
                    @if ($registration->competitor->bib_number)
                        <div class="mb-2"><x-bib :competitor="$registration->competitor" :event="$event" class="bib-tag-lg" /></div>
                    @endif
                    <div class="fs-5 fw-semibold d-flex align-items-center gap-2">
                        <span class="side-chip {{ $slot === 'a' ? 'side-aka' : 'side-shiro' }}" tabindex="0" role="img" aria-label="{{ $slot === 'a' ? 'AKA (rojo)' : 'SHIRO (blanco)' }}"><span class="side-text">{{ $slot === 'a' ? 'AKA' : 'SHIRO' }}</span></span>
                        <span class="text-break">{{ $registration->competitor->displayName() }}</span>
                        @if ($winner)<span class="badge text-bg-success">Ganador</span>@endif
                    </div>
                    <div class="text-muted{{ $registration->competitor->club ? '' : ' fst-italic' }} mb-3">@if ($registration->competitor->club)<x-club-name :club="$registration->competitor->club" :event="$event" :logo="false" />@else Sin club @endif</div>
                    @include('partials.score-participant-controls')
                </div>
            </div>
        </div>
    @endforeach
    <div class="col-12 col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="h6">Puntuación</h3>
                <form id="{{ $scoreForm }}" data-score-form method="POST" action="{{ $scoreAction }}" class="small">
                    @csrf @method('PUT')
                    @include('partials.score-form-fields')
                </form>
            </div>
        </div>
    </div>
</div>
</div>
