@props(['competitor', 'event', 'size' => 'sm'])
{{-- Miniatura pública del inscrito; no pinta nada si la regla de publicación no se cumple. --}}
@php($url = $competitor->publicPhotoUrl($event))
@if ($url)
    <img src="{{ $url }}" alt="Foto de {{ $competitor->displayName() }}" loading="lazy" onerror="this.remove()" {{ $attributes->class(['competitor-photo', 'competitor-photo-'.$size]) }}>
@endif
