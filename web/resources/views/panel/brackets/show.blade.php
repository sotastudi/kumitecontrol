@extends('layouts.app')
@section('title', 'Cuadro · '.$category->name)
@section('content')
@php
    $participants = $bouts->whereIn('round', [1, 2])->where('is_bronze', false)->flatMap(fn ($b) => [$b->slotA, $b->slotB])->filter()->unique('id');
@endphp
@if ($readinessProblem && ! $regenerationLocked)
    <div class="alert alert-warning no-print">{{ $readinessProblem }} <a href="{{ route('panel.events.show', $event) }}" class="alert-link">Ir al evento</a></div>
@endif
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <a href="{{ route('panel.events.show', $event) }}" class="small">&larr; {{ $event->name }}</a>
        @if ($siblings->count() > 1)
            @php
                $at = $siblings->search(fn ($c) => $c->id === $category->id);
                $previous = $at !== false && $at > 0 ? $siblings[$at - 1] : null;
                $next = $at !== false ? $siblings->get($at + 1) : null;
            @endphp
            <div class="d-flex align-items-center flex-wrap gap-2 my-2 no-print">
                @if ($previous)
                    <a href="{{ route('panel.brackets.show', [$event, $previous]) }}" class="btn btn-outline-secondary btn-sm">&lsaquo; Anterior</a>
                @else
                    <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">&lsaquo; Anterior</span>
                @endif
                <select class="form-select form-select-sm w-auto" aria-label="Cambiar de categoría" onchange="location = this.value">
                    @foreach ($siblings as $sibling)
                        <option value="{{ route('panel.brackets.show', [$event, $sibling]) }}" @selected($sibling->id === $category->id)>{{ $sibling->name }}@if ($sibling->tatami) · Tatami {{ $sibling->tatami }}@endif</option>
                    @endforeach
                </select>
                @if ($next)
                    <a href="{{ route('panel.brackets.show', [$event, $next]) }}" class="btn btn-outline-secondary btn-sm">Siguiente &rsaquo;</a>
                @else
                    <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">Siguiente &rsaquo;</span>
                @endif
            </div>
        @endif
        <h1 class="h3 mb-1">{{ $category->name }}</h1>
        <div class="mb-1 category-tatami">
            @if ($category->tatami)
                <span class="badge text-bg-secondary">Tatami {{ $category->tatami }}</span>
                @if ($category->referees)<span class="text-muted small">{{ $category->referees }} jueces</span>@endif
            @else
                <span class="text-muted small fst-italic">Sin tatami asignado</span>
            @endif
        </div>
        <div class="text-muted">{{ $confirmedCount }} {{ $confirmedCount === 1 ? 'inscrito confirmado' : 'inscritos confirmados' }}</div>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        @unless ($regenerationLocked)
            @if ($readinessProblem)
                <button type="button" class="btn btn-primary" disabled>{{ $bouts->isEmpty() ? 'Generar cuadro' : 'Regenerar cuadro' }}</button>
            @else
            <form method="POST" action="{{ route('panel.brackets.store', [$event, $category]) }}"
                  @if ($bouts->isNotEmpty()) onsubmit="return confirm('Se descartará el cuadro actual y se generará uno nuevo. ¿Continuar?')" @endif>
                @csrf
                <button type="submit" class="btn btn-primary">{{ $bouts->isEmpty() ? 'Generar cuadro' : 'Regenerar cuadro' }}</button>
            </form>
            @endif
        @endunless
        @if ($bouts->isNotEmpty() || $category->is_published)
            <form method="POST" action="{{ route('panel.categories.publication', [$event, $category]) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-outline-success">{{ $category->is_published ? 'Ocultar' : 'Publicar' }}</button>
            </form>
        @endif
        @if ($category->is_published && $event->isListed())
            <a href="{{ route('public.categories.show', [$event, $category]) }}" class="btn btn-outline-secondary btn-icon" title="Ver página pública" aria-label="Ver página pública"><svg width="16" height="16" aria-hidden="true"><use href="#eye"/></svg> Ver</a>
        @endif
        @php $withResults = $bouts->filter(fn ($b) => $b->required_participants == 2 && ($b->winner_id || $b->a_wazari || $b->b_wazari || $b->a_ippon || $b->b_ippon || $b->a_warnings || $b->b_warnings || $b->abandoned_slot || ! empty($b->judge_votes)))->count(); @endphp
        @if ($withResults > 0)
            <button type="button" class="btn btn-outline-danger btn-icon" data-bs-toggle="modal" data-bs-target="#reset-modal"
                    data-reset-url="{{ route('panel.categories.reset', [$event, $category]) }}"
                    data-reset-title="Reiniciar TODOS los resultados de «{{ $category->name }}»"
                    data-reset-message="Se borrarán los resultados y la puntuación de {{ $withResults }} {{ $withResults === 1 ? 'combate' : 'combates' }} de esta categoría y se retirará de las rondas siguientes a quienes avanzaron. El cuadro y los emparejamientos de la primera ronda se conservan."><svg width="16" height="16" aria-hidden="true"><use href="#undo"/></svg> Reiniciar resultados</button>
        @endif
        <a href="{{ route('panel.exports.standings', $event) }}" class="btn btn-outline-secondary">Exportar clasificación (CSV)</a>
        <button type="button" class="btn btn-outline-secondary btn-icon" onclick="window.print()"><svg width="16" height="16" aria-hidden="true"><use href="#printer"/></svg> Imprimir</button>
        @if ($bouts->isNotEmpty())
            <a href="{{ route('panel.brackets.pdf', [$event, $category]) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-icon" title="Descargar el cuadro en PDF (A4 horizontal) para rellenar a mano" aria-label="Descargar el cuadro en PDF para rellenar a mano"><svg width="16" height="16" aria-hidden="true"><use href="#download"/></svg> PDF</a>
        @endif
    </div>
</div>

@if ($stale && ! $regenerationLocked)
    <div class="alert alert-warning">El cuadro está <strong>desactualizado</strong> respecto a las inscripciones confirmadas: regenera el cuadro.</div>
@elseif ($stale)
    <div class="alert alert-warning">Hay cambios posteriores en las inscripciones: resuélvelos manualmente según el reglamento.</div>
@endif

@if ($category->draw_warnings)
    <div class="alert alert-warning">
        <strong>Revisa el sorteo:</strong> para que ningún participante quede suelto en la primera ronda se ha forzado una regla del sorteo. Puedes ajustarlo con «Intercambiar participantes».
        <ul class="mb-0">
            @foreach ($category->draw_warnings as $warning)<li>{{ $warning }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="alert alert-info">Regla del bronce: {{ $category->bronze_rule->label() }}</div>

@if ($confirmedCount === 1)
    <div class="alert alert-secondary">Solo hay un inscrito confirmado: la clasificación se resuelve manualmente según el reglamento.</div>
@endif

@php
    // Aviso de descanso solo si el cuadro tiene ronda previa (modelo con exentos directos a la ronda 2).
    $prelimBouts = $bouts->where('is_prelim', true)->where('is_bronze', false)->count();
    $exemptCount = $participants->count() - 2 * $prelimBouts;
@endphp
@if ($prelimBouts > 0)
    <div class="alert alert-info no-print">
        <strong>Ronda previa:</strong> {{ $prelimBouts }} {{ $prelimBouts === 1 ? 'combate' : 'combates' }} ({{ 2 * $prelimBouts }} competidores) · {{ $exemptCount }} {{ $exemptCount === 1 ? 'exento' : 'exentos' }}.
        Los de la previa harán un combate más. Deja 20–30 minutos de descanso antes de la ronda siguiente.
    </div>
@endif

@if ($bouts->isNotEmpty())
    <section class="mb-4">
        <h2 class="h5">Cuadro</h2>
        @include('partials.bracket', ['editable' => true])
    </section>

    @unless ($regenerationLocked)
        <section class="mb-4 no-print">
            <h2 class="h5">Intercambiar participantes</h2>
            <form method="POST" action="{{ route('panel.brackets.swap', [$event, $category]) }}" class="row g-2 align-items-end">
                @csrf
                @foreach (['registration_a' => 'Participante A', 'registration_b' => 'Participante B'] as $field => $label)
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                        <select class="form-select" id="{{ $field }}" name="{{ $field }}" required>
                            @foreach ($participants as $registration)
                                <option value="{{ $registration->id }}">{{ $registration->competitor->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div class="col-12 col-md-auto"><button type="submit" class="btn btn-outline-primary">Intercambiar</button></div>
            </form>
        </section>
    @endunless

    <section class="mb-4">
        <h2 class="h5">Orden de combates</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>N.º</th><th>Ronda</th><th>Participantes</th><th>Estado</th></tr></thead>
                <tbody>
                @php $totalRounds = $bouts->where('is_bronze', false)->max('round'); @endphp
                @foreach ($bouts->whereNotNull('number')->sortBy('number') as $bout)
                    <tr>
                        <td>{{ $bout->number }}</td>
                        <td>{{ $bout->is_bronze ? 'Bronce' : \App\Tournament\RoundLabel::for($bout->round, $totalRounds, $bout->is_prelim) }}</td>
                        <td>{{ $bout->slotA?->competitor->displayName() ?? 'Por determinar' }} — {{ $bout->slotB?->competitor->displayName() ?? 'Por determinar' }}</td>
                        <td>{{ $bout->isCompleted() ? 'Finalizado' : ($bout->isReady() ? 'Listo' : 'Pendiente') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($canWithdraw)
        @php
            $alive = $participants->reject(fn ($r) => $r->isWithdrawn());
            $withdrawals = $participants->filter(fn ($r) => $r->isWithdrawn())->sortBy('withdrawn_at');
        @endphp
        <section class="mb-4 no-print">
            <h2 class="h5">Retiradas y descalificaciones</h2>
            @if ($alive->isNotEmpty())
                <form method="POST" action="{{ route('panel.withdrawals.store', [$event, $category]) }}" class="row g-2 align-items-end"
                      onsubmit="return confirm('Se dará por ganado cada combate pendiente de este participante a su rival. No se puede deshacer. ¿Continuar?')">
                    @csrf
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="withdrawal_registration">Participante</label>
                        <select class="form-select" id="withdrawal_registration" name="registration_id" required>
                            @foreach ($alive as $registration)
                                <option value="{{ $registration->id }}">{{ $registration->competitor->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="form-label d-block">Tipo</span>
                        @foreach (\App\Enums\WithdrawalType::options() as $value => $label)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="type" id="withdrawal_type_{{ $value }}" value="{{ $value }}" @checked($loop->first) required>
                                <label class="form-check-label" for="withdrawal_type_{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="withdrawal_reason">Motivo (privado, no se publica)</label>
                        <textarea class="form-control" id="withdrawal_reason" name="reason" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-outline-danger">Registrar baja</button></div>
                </form>
            @endif
            @if ($withdrawals->isNotEmpty())
                <div class="table-responsive mt-3">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Participante</th><th>Tipo</th><th>Motivo</th><th>Fecha</th></tr></thead>
                        <tbody>
                        @foreach ($withdrawals as $registration)
                            <tr>
                                <td>{{ $registration->competitor->displayName() }}</td>
                                <td>{{ $registration->withdrawal_type->label() }}</td>
                                <td>{{ $registration->withdrawal_reason ?? '—' }}</td>
                                <td>{{ $registration->withdrawn_at?->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    <section>
        <h2 class="h5">Podio</h2>
        @include('partials.podium', ['editable' => true])
        @if (count($vacantPlaces))
            <div class="alert alert-warning">Puesto vacante (descalificación o retirada sin combatir): resolver según el reglamento. Puestos: {{ implode(', ', $vacantPlaces) }}.</div>
        @endif
    </section>
@endif
@include('partials.reset-modal')
@endsection
