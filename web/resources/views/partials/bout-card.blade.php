@php
    $editable ??= false;
    $canRecord ??= true;
    $exemptSlots ??= []; // huecos ocupados por un exento de la ronda previa (desde partials.bracket)
    $names = [
        'a' => $bout->slotA?->competitor->displayName(),
        'b' => $bout->slotB?->competitor->displayName(),
    ];
    $empty = $bout->isBye() ? 'Pase' : 'Por determinar';
    $scorable = $editable && $canRecord && $bout->filledSlots() === 2 && ! $bout->isBye() && ! $bout->isWalkover();
    $scoreForm = 'score-form-'.$bout->id;
    $scorePanel = 'score-panel-'.$bout->id;
    $showGana ??= true;
    $panelOpen ??= false;
    $hasScore = $bout->a_wazari > 0 || $bout->b_wazari > 0 || $bout->a_ippon || $bout->b_ippon || $bout->a_warnings > 0 || $bout->b_warnings > 0 || $bout->abandoned_slot || ! empty($bout->judge_votes);
    $resettable = $editable && $showGana && ! $bout->isBye() && ! $bout->isWalkover() && ($bout->isCompleted() || $hasScore);
@endphp
<div class="bout-card mb-2">
    <div class="small text-muted px-2 pt-1">
        {{ $bout->number ? 'Combate '.$bout->number : 'Pase' }}@if ($bout->is_bronze) · Bronce @endif
    </div>
    @foreach (['a' => $bout->slot_a_id, 'b' => $bout->slot_b_id] as $slot => $registrationId)
        @php $registration = $slot === 'a' ? $bout->slotA : $bout->slotB; @endphp
        <div class="slot d-flex justify-content-between gap-2 {{ $registrationId && $registrationId === $bout->winner_id ? 'winner' : '' }}">
            <span class="{{ $registrationId ? '' : 'text-muted fst-italic' }}">
                @if ($registration?->competitor->bib_number)
                    <div class="mb-1"><x-bib :competitor="$registration->competitor" :event="$event" /></div>
                @endif
                @if (! $bout->isBye())
                    <span class="side-chip {{ $slot === 'a' ? 'side-aka' : 'side-shiro' }}" tabindex="0" role="img" aria-label="{{ $slot === 'a' ? 'AKA (rojo)' : 'SHIRO (blanco)' }}"><span class="side-text">{{ $slot === 'a' ? 'AKA' : 'SHIRO' }}</span></span>
                @endif
                {{ $names[$slot] ?? $empty }}
                @if (in_array($slot, $exemptSlots, true))
                    <small class="badge text-bg-light border fw-normal">Exento</small>
                @endif
                @if ($editable && $registration?->isWithdrawn())
                    <small class="text-danger">({{ $registration->isDisqualified() ? 'descalificado' : 'retirado' }})</small>
                @endif
                @if ($registration)
                    <small class="d-block text-muted{{ $registration->competitor->club ? '' : ' fst-italic' }}">{{ $registration->competitor->club ?: 'Sin club' }}</small>
                @endif
                @php
                    // W: wazari (1-2), I: ippon, F: faltas (1-4, solo en el panel).
                    $marks = [];
                    if ($bout->{$slot.'_wazari'} > 0) { $marks[] = 'W:'.$bout->{$slot.'_wazari'}; }
                    if ($bout->{$slot.'_ippon'}) { $marks[] = 'I'; }
                    if ($editable && $bout->{$slot.'_warnings'} > 0) { $marks[] = 'F:'.$bout->{$slot.'_warnings'}; }
                @endphp
                @if ($marks)
                    <small class="d-block fw-semibold score-marks">{{ implode(' ', $marks) }}</small>
                @endif
                @php $opponentIppon = (bool) $bout->{($slot === 'a' ? 'b' : 'a').'_ippon'}; @endphp
                @if ($scorable)
                    {{-- Controles de wazari, ippon y falta de este participante: viven en el formulario de puntuación (atributo form). --}}
                    <div class="collapse{{ $panelOpen ? ' show' : '' }} {{ $scorePanel }} no-print small mt-1">
                        @include('partials.score-participant-controls')
                    </div>
                @endif
            </span>
            @if ($scorable && $showGana)
                <form method="POST" action="{{ route('panel.bouts.result', [$event, $category, $bout]) }}" class="no-print">
                    @csrf @method('PUT')
                    <input type="hidden" name="winner_id" value="{{ $registrationId }}">
                    <button class="btn btn-sm {{ $registrationId === $bout->winner_id ? 'btn-success' : 'btn-outline-primary' }}">Gana</button>
                </form>
            @endif
        </div>
    @endforeach
    @if ($bout->isWalkover())
        <div class="small text-muted px-2 pb-1">Gana por incomparecencia</div>
    @endif
    @php
        $notes = [];
        if ($bout->abandoned_slot) { $notes[] = 'Abandona '.($bout->abandoned_slot === 'a' ? '🟥' : '⬜'); }
        $tally = $bout->judge_votes ? array_count_values(array_filter($bout->judge_votes, fn ($v) => $v !== null)) : [];
    @endphp
    @if ($notes)
        <div class="small text-muted px-2 pb-1">{{ implode(' · ', $notes) }}</div>
    @endif
    @if ($tally)
        {{-- Votos de los jueces con las mismas banderas de los botones: roja (AKA), blanca (SHIRO) y blanca y roja partida (empate). --}}
        <div class="small text-muted px-2 pb-1" title="Votos de los jueces: bandera roja, blanca y partida (empate)">
            Jueces:
            <span class="flag flag-aka flag-sm" aria-label="AKA"></span> {{ $tally['aka'] ?? 0 }} ·
            <span class="flag flag-shiro flag-sm" aria-label="SHIRO"></span> {{ $tally['shiro'] ?? 0 }} ·
            <span class="flag flag-hikiwake flag-sm" aria-label="Empate"></span> {{ $tally['hikiwake'] ?? 0 }}
        </div>
    @endif
    @if ($scorable)
        <div class="px-2 pb-2 no-print">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <button type="button" class="btn btn-link btn-sm p-0 small" data-bs-toggle="collapse" data-bs-target=".{{ $scorePanel }}" aria-expanded="false">Puntuación</button>
                @if ($resettable)
                    <button type="button" class="btn btn-outline-danger btn-sm py-0 btn-icon" data-bs-toggle="modal" data-bs-target="#reset-modal"
                            data-reset-url="{{ route('panel.bouts.reset', [$event, $category, $bout]) }}"
                            data-reset-title="Reiniciar el resultado del combate {{ $bout->number }}"
                            data-reset-message="Se borrarán el ganador, los puntos, las faltas y los votos de los jueces del combate {{ $bout->number }}, y se retirará de las rondas siguientes a quien avanzó de él. Si algún combate posterior ya tiene resultado, antes hay que reiniciarlo."
                            title="Reiniciar el resultado de este combate"><svg width="14" height="14" aria-hidden="true"><use href="#undo"/></svg> Reiniciar</button>
                @endif
            </div>
            <form id="{{ $scoreForm }}" data-score-form method="POST" action="{{ $scoreAction ?? route('panel.bouts.score', [$event, $category, $bout]) }}" class="collapse{{ $panelOpen ? ' show' : '' }} {{ $scorePanel }} small mt-2">
                @csrf @method('PUT')
                @include('partials.score-form-fields')
            </form>
        </div>
    @endif
</div>
