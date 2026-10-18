@php
    $editable ??= false;
    $totalRounds = $bouts->where('is_bronze', false)->max('round');
    $rounds = $bouts->where('is_bronze', false)->groupBy('round')->sortKeys();
    $bronze = $bouts->firstWhere('is_bronze', true);
    $firstRound = $rounds->keys()->first();

    // Filas de la cuadrícula: cada combate de la 1.ª ronda ocupa una fila y cada combate posterior abarca las de sus dos combates de origen.
    $span = [];
    $feeders = [];
    foreach ($rounds as $round => $roundBouts) {
        $byPosition = $roundBouts->sortBy('position')->keyBy('position');
        foreach ($byPosition as $position => $bout) {
            if ($round === $firstRound) {
                $span[$bout->id] = [$position, $position + 1];
                $feeders[$bout->id] = 0;
                continue;
            }
            $previous = $rounds[$round - 1]->keyBy('position');
            $sources = collect([$previous[2 * $position - 1] ?? null, $previous[2 * $position] ?? null])->filter();
            $span[$bout->id] = [$sources->map(fn ($b) => $span[$b->id][0])->min(), $sources->map(fn ($b) => $span[$b->id][1])->max()];
            $feeders[$bout->id] = $sources->count();
        }
    }
@endphp
<div class="bracket-wrap pb-3">
    <div class="bracket-grid" style="--rounds: {{ $rounds->count() }}">
        @foreach ($rounds as $round => $roundBouts)
            <h3 class="bracket-head h6 text-center text-muted" style="grid-column: {{ $loop->iteration }}; grid-row: 1">{{ \App\Tournament\RoundLabel::for($round, $totalRounds, (bool) $roundBouts->first()?->is_prelim) }}</h3>
            @php $count = $roundBouts->count(); @endphp
            @foreach ($roundBouts->sortBy('position') as $bout)
                @php
                    $position = $bout->position;
                    $hasNext = $round < $totalRounds;
                    $role = ! $hasNext ? '' : ($position % 2 === 0 ? 'link-bottom' : ($position < $count ? 'link-top' : 'link-single'));
                @endphp
                <div class="bracket-cell {{ $role }} {{ $feeders[$bout->id] === 2 ? 'has-in' : '' }}"
                     style="grid-column: {{ $loop->parent->iteration }}; grid-row: {{ $span[$bout->id][0] + 1 }} / {{ $span[$bout->id][1] + 1 }}">
                    @include('partials.bout-card', ['bout' => $bout])
                </div>
            @endforeach
        @endforeach
    </div>
</div>
@if ($bronze)
    <h3 class="h6 text-muted mt-3">Combate por el bronce</h3>
    <div style="max-width: 20rem">@include('partials.bout-card', ['bout' => $bronze])</div>
@endif
