@php($competitors = [$next->slotA->competitor, $next->slotB->competitor])
<li class="mb-1">
    {{ $showCategory ?? true ? $next->category->name.' · ' : '' }}@if (isset($status) && isset($reviewUrl))<a href="{{ $reviewUrl }}">Combate {{ $next->number }}</a>@else Combate {{ $next->number }}@endif:
    @foreach ($competitors as $competitor)
        <x-bib :competitor="$competitor" :event="$event" />
        <x-competitor-photo :competitor="$competitor" :event="$event" size="sm" />
        <span @class(['fw-semibold' => $next->winner_id && $next->winner_id === ($loop->first ? $next->slot_a_id : $next->slot_b_id)])>{{ $competitor->displayName() }}</span>
        <span class="text-muted">(@if ($competitor->club)<x-club-name :club="$competitor->club" :event="$event" :logo="false" />@else Sin club @endif)</span>
        @if (! $loop->last)<span class="text-muted">vs</span>@endif
    @endforeach
    @if (isset($status))<span class="badge text-bg-{{ $status[1] }} ms-1">{{ $status[0] }}</span>@endif
</li>
