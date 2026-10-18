@if (count($placements))
    @php
        $byPlace = collect($placements)->groupBy('place');
        $medals = [1 => ['Oro', 'podium-gold', 'bi-1'], 2 => ['Plata', 'podium-silver', ''], 3 => ['Bronce', 'podium-bronze', '']];
        // Orden visual clásico: 2.º, 1.º, 3.º (los empates comparten columna).
        $columns = collect([2, 1, 3])->filter(fn ($p) => $byPlace->has($p));
    @endphp
    <div class="podium d-flex justify-content-center align-items-end gap-2 mb-3" role="list" aria-label="Podio">
        @foreach ($columns as $place)
            <div class="podium-col podium-{{ $place }} text-center" role="listitem">
                <div class="mb-2">
                    @foreach ($byPlace[$place] as $p)
                        <div><x-competitor-photo :competitor="$p->registration->competitor" :event="$event" size="sm" /></div>
                        <div class="fw-semibold text-break">{{ $p->registration->competitor->displayName() }}@if (($editable ?? false) && $p->withdrawn) <small class="text-danger">(retirado)</small>@endif</div>
                        @if ($p->registration->competitor->club)<div class="small text-muted text-break">{{ $p->registration->competitor->club }}</div>@endif
                    @endforeach
                </div>
                <div class="podium-step {{ $medals[$place][1] }} d-flex align-items-start justify-content-center">
                    <span class="podium-number">{{ $place }}.º</span>
                </div>
            </div>
        @endforeach
    </div>
    @if ($byPlace->has(4))
        <ul class="list-group mb-3">
            @foreach ($byPlace[4] as $p)
                <li class="list-group-item d-flex justify-content-between">
                    <span>4.º — <x-competitor-photo :competitor="$p->registration->competitor" :event="$event" size="sm" /> <strong>{{ $p->registration->competitor->displayName() }}</strong>@if (($editable ?? false) && $p->withdrawn) <small class="text-danger">(retirado)</small>@endif</span>
                    <span class="text-muted">{{ $p->registration->competitor->club }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endif
