@php
    $editable ??= false;
    $totalRounds = $bouts->where('is_bronze', false)->max('round');
    $rounds = $bouts->where('is_bronze', false)->groupBy('round')->sortKeys();
    $bronze = $bouts->firstWhere('is_bronze', true);
    $firstRound = $rounds->keys()->first();

    // Filas de la cuadrícula: un combate sin combates de origen ocupa una fila propia y cada combate con origen abarca
    // las filas de sus combates de origen (los de next_bout_id = combate.id, por hueco a/b). Así un combate de ronda
    // previa queda a la izquierda de su combate de destino, en su misma fila, y uno de ronda 2 con dos exentos, en la suya.
    $main = $bouts->where('is_bronze', false);
    $sources = $main->whereNotNull('next_bout_id')->groupBy('next_bout_id')->map(fn ($group) => $group->sortBy(fn ($b) => $b->next_slot.'-'.$b->position)->values());
    $span = [];
    $row = 0;
    $place = function ($bout) use (&$place, &$span, &$row, $sources) {
        $children = $sources[$bout->id] ?? collect();
        if ($children->isEmpty()) {
            $span[$bout->id] = [$row, ++$row];

            return;
        }
        foreach ($children as $child) {
            $place($child);
        }
        $span[$bout->id] = [$span[$children->first()->id][0], $span[$children->last()->id][1]];
    };
    foreach ($main->whereNull('next_bout_id')->sortBy(['round', 'position']) as $root) {
        $place($root);
    }

    // Huecos ya ocupados de una ronda posterior sin combate de origen: exentos de la ronda previa.
    $exempt = [];
    foreach ($main->where('round', '>', $firstRound) as $bout) {
        foreach (['a', 'b'] as $slot) {
            if ($bout->{'slot_'.$slot.'_id'} && ! ($sources[$bout->id] ?? collect())->contains('next_slot', $slot)) {
                $exempt[$bout->id][] = $slot;
            }
        }
    }
@endphp
<div class="bracket-wrap pb-3">
    <div class="bracket-grid" style="--rounds: {{ $rounds->count() }}">
        @foreach ($rounds as $round => $roundBouts)
            <h3 class="bracket-head h6 text-center text-muted" style="grid-column: {{ $loop->iteration }}; grid-row: 1">{{ \App\Tournament\RoundLabel::for($round, $totalRounds, (bool) $roundBouts->first()?->is_prelim) }}</h3>
            @foreach ($roundBouts->sortBy('position') as $bout)
                @php
                    // Salida: hacia arriba o abajo si el destino tiene dos combates de origen; recta si es el único.
                    $siblings = $bout->next_bout_id ? ($sources[$bout->next_bout_id] ?? collect()) : collect();
                    $role = $siblings->isEmpty() ? '' : ($siblings->count() === 1 ? 'link-single' : ($siblings->first()->id === $bout->id ? 'link-top' : 'link-bottom'));
                @endphp
                <div class="bracket-cell {{ $role }} {{ ($sources[$bout->id] ?? collect())->count() === 2 ? 'has-in' : '' }}"
                     data-bout="{{ $bout->id }}"
                     style="grid-column: {{ $loop->parent->iteration }}; grid-row: {{ $span[$bout->id][0] + 2 }} / {{ $span[$bout->id][1] + 2 }}">
                    @include('partials.bout-card', ['bout' => $bout, 'exemptSlots' => $exempt[$bout->id] ?? []])
                </div>
            @endforeach
        @endforeach
    </div>
</div>
@if ($bronze)
    <h3 class="h6 text-muted mt-3">Combate por el bronce</h3>
    <div style="max-width: 20rem">@include('partials.bout-card', ['bout' => $bronze])</div>
@endif
