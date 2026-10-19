<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light" data-theme-mode="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Campeonatos') · KumiteControl</title>
    @include('layouts.partials.favicons')
    <script>
        // Modo de tema: light | dark | auto (sistema). Se aplica antes de pintar para evitar parpadeo.
        (function () {
            var mode = null;
            try { mode = localStorage.getItem('theme'); } catch (e) {}
            if (mode !== 'light' && mode !== 'dark') { mode = 'auto'; }
            var theme = mode === 'auto'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : mode;
            document.documentElement.setAttribute('data-theme-mode', mode);
            document.documentElement.setAttribute('data-bs-theme', theme);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-body-tertiary d-flex flex-column min-vh-100">
<svg xmlns="http://www.w3.org/2000/svg" class="d-none" aria-hidden="true">
    <defs>
        <clipPath id="bulb-left-half"><rect x="0" y="0" width="12" height="24"/></clipPath>
    </defs>
    {{-- Bombilla encendida (claro), apagada (oscuro) y mitad/mitad (automático). --}}
    {{-- Estado de sincronización: nube con check (todo guardado), nube (pendiente) y triángulo de aviso. --}}
    <symbol id="cloud-check" viewBox="0 0 24 24">
        <path d="M7 19a4.5 4.5 0 0 1-.6-8.96A6.5 6.5 0 0 1 18.9 9.6 4.7 4.7 0 0 1 18 19H7z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
        <path d="M9 13.5l2.2 2.2L15.5 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="cloud-pending" viewBox="0 0 24 24">
        <path d="M7 19a4.5 4.5 0 0 1-.6-8.96A6.5 6.5 0 0 1 18.9 9.6 4.7 4.7 0 0 1 18 19H7z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-dasharray="3 2"/>
    </symbol>
    <symbol id="warn" viewBox="0 0 24 24">
        <path d="M12 3.5 22 20.5H2L12 3.5z" fill="currentColor"/>
        <path d="M12 9.5v5M12 17.5h.01" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    {{-- Iconos de acciones: lápiz (editar), diagrama de árbol (cuadro), papelera (eliminar) y compartir (publicar). --}}
    <symbol id="calendar" viewBox="0 0 24 24">
        <rect x="3.5" y="5" width="17" height="15" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/>
        <path d="M3.5 10h17M8 3v4M16 3v4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
    </symbol>
    <symbol id="flag" viewBox="0 0 24 24">
        <path d="M5 21V4M5 4.5h12l-2.5 4 2.5 4H5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="pin" viewBox="0 0 24 24">
        <path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
        <circle cx="12" cy="9.5" r="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/>
    </symbol>
    <symbol id="printer" viewBox="0 0 24 24">
        <path d="M7 9V4h10v5M7 17H4.5A1.5 1.5 0 0 1 3 15.5v-5A1.5 1.5 0 0 1 4.5 9h15a1.5 1.5 0 0 1 1.5 1.5v5a1.5 1.5 0 0 1-1.5 1.5H17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="7" y="14" width="10" height="6.5" rx="0.8" fill="none" stroke="currentColor" stroke-width="1.7"/>
    </symbol>
    <symbol id="check" viewBox="0 0 24 24">
        <path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="ban" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.9"/>
        <path d="M6 6l12 12" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
    </symbol>
    <symbol id="clock" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/>
        <path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="reset" viewBox="0 0 24 24">
        <path d="M20 12a8 8 0 1 1-2.6-5.9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        <path d="M20 4.5v4.5h-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="download" viewBox="0 0 24 24">
        <path d="M12 4v11M7.5 11l4.5 4.5 4.5-4.5M5 19.5h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="undo" viewBox="0 0 24 24">
        <path d="M9 7H4v5M4.5 11.5A8 8 0 1 1 6 17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="plus" viewBox="0 0 24 24">
        <path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </symbol>
    <symbol id="logout" viewBox="0 0 24 24">
        <path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M15 8l4 4-4 4M19 12H9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="help" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.7"/>
        <path d="M9.5 9.5a2.5 2.5 0 1 1 3.6 2.25c-.75.4-1.1.9-1.1 1.75M12 17h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="eye" viewBox="0 0 24 24">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
        <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.7"/>
    </symbol>
    <symbol id="pencil" viewBox="0 0 24 24">
        <path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19l-4 1z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
        <path d="M14.5 6.5l3 3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
    </symbol>
    <symbol id="sitemap" viewBox="0 0 24 24">
        <rect x="9" y="3" width="6" height="4.5" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <rect x="2.5" y="16.5" width="6" height="4.5" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <rect x="15.5" y="16.5" width="6" height="4.5" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <path d="M12 7.5V12M5.5 16.5V12h13v4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
    </symbol>
    <symbol id="trash" viewBox="0 0 24 24">
        <path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v6M14 11v6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </symbol>
    <symbol id="share" viewBox="0 0 24 24">
        <circle cx="18" cy="5.5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <circle cx="6" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <circle cx="18" cy="18.5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/>
        <path d="M8.3 10.8l7.4-3.9M8.3 13.2l7.4 3.9" fill="none" stroke="currentColor" stroke-width="1.6"/>
    </symbol>
    <symbol id="user" viewBox="0 0 24 24">
        <circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="1.7"/>
        <path d="M4 20a8 8 0 0 1 16 0" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
    </symbol>
    <symbol id="bulb-on" viewBox="0 0 24 24">
        <path d="M12 2a7 7 0 0 0-4 12.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26A7 7 0 0 0 12 2z" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        <path d="M9.5 21h5M10 18v0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
    </symbol>
    <symbol id="bulb-off" viewBox="0 0 24 24">
        <path d="M12 2a7 7 0 0 0-4 12.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26A7 7 0 0 0 12 2z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        <path d="M9.5 21h5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
    </symbol>
    <symbol id="bulb-half" viewBox="0 0 24 24">
        <path d="M12 2a7 7 0 0 0-4 12.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26A7 7 0 0 0 12 2z" fill="currentColor" clip-path="url(#bulb-left-half)"/>
        <path d="M12 2a7 7 0 0 0-4 12.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26A7 7 0 0 0 12 2z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
        <path d="M9.5 21h5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
    </symbol>
</svg>
<nav class="navbar navbar-expand-md bg-body border-bottom mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand" href="{{ route('home') }}" aria-label="KumiteControl">
            {{-- Logo horizontal desde tablet (md) hacia arriba; icono en móvil. Claro/oscuro según el tema activo. --}}
            <img src="{{ asset('img/brand/light-logo-horizontal.svg') }}" alt="KumiteControl" height="40" class="brand-logo brand-full brand-light">
            <img src="{{ asset('img/brand/dark-logo-horizontal.svg') }}" alt="" height="40" class="brand-logo brand-full brand-dark" aria-hidden="true">
            <img src="{{ asset('img/brand/light-icon-logo.svg') }}" alt="KumiteControl" height="36" class="brand-logo brand-icon brand-light">
            <img src="{{ asset('img/brand/dark-icon-logo.svg') }}" alt="" height="36" class="brand-logo brand-icon brand-dark" aria-hidden="true">
        </a>
        <div class="d-flex align-items-center gap-2 ms-auto order-md-last nav-actions">
        @auth
            <button type="button" id="sync-indicator" data-csrf-url="{{ route('panel.csrf') }}" data-login-url="{{ route('login') }}" class="btn btn-sm btn-link text-decoration-none d-flex align-items-center gap-1 sync-indicator" data-sync-state="synced" aria-live="polite" title="Todo guardado en el servidor">
                <svg class="sync-cloud-ok text-success" width="22" height="22" aria-hidden="true"><use href="#cloud-check"/></svg>
                <span class="sync-pending d-none align-items-center gap-1 text-warning-emphasis">
                    <svg class="text-warning" width="18" height="18" aria-hidden="true"><use href="#warn"/></svg>
                    <svg class="text-warning" width="22" height="22" aria-hidden="true"><use href="#cloud-pending"/></svg>
                    <span class="sync-count small fw-semibold"></span>
                </span>
                <span class="visually-hidden sync-label">Todo guardado en el servidor</span>
            </button>
            <div id="sync-panel" class="card shadow-sm d-none no-print" role="dialog" aria-label="Resultados pendientes de sincronizar"></div>
        @endauth
            <a class="btn btn-outline-secondary btn-sm d-flex align-items-center" href="{{ route('help') }}" aria-label="Ayuda" title="Ayuda">
                <svg width="18" height="18" aria-hidden="true"><use href="#help"/></svg>
            </a>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" id="theme-menu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Tema de color">
                    <svg class="theme-icon theme-icon-light" width="18" height="18" aria-hidden="true"><use href="#bulb-on"/></svg>
                    <svg class="theme-icon theme-icon-dark" width="18" height="18" aria-hidden="true"><use href="#bulb-off"/></svg>
                    <svg class="theme-icon theme-icon-auto" width="18" height="18" aria-hidden="true"><use href="#bulb-half"/></svg>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="theme-menu">
                    <li><button class="dropdown-item d-flex align-items-center gap-2" type="button" data-theme-value="light"><svg width="18" height="18" aria-hidden="true"><use href="#bulb-on"/></svg> Claro</button></li>
                    <li><button class="dropdown-item d-flex align-items-center gap-2" type="button" data-theme-value="dark"><svg width="18" height="18" aria-hidden="true"><use href="#bulb-off"/></svg> Oscuro</button></li>
                    <li><button class="dropdown-item d-flex align-items-center gap-2" type="button" data-theme-value="auto"><svg width="18" height="18" aria-hidden="true"><use href="#bulb-half"/></svg> Automático (sistema)</button></li>
                </ul>
            </div>
            {{-- Usuario: solo el icono; el texto aparece al pasar el cursor o al tocar (en tablet, un primer toque lo muestra y el segundo entra). --}}
            <a class="btn btn-outline-secondary btn-sm d-flex align-items-center user-chip" href="{{ auth()->check() ? route('panel.profile.edit') : route('login') }}" aria-label="{{ auth()->check() ? 'Mi perfil ('.auth()->user()->name.')' : 'Iniciar sesión' }}" title="{{ auth()->check() ? 'Mi perfil' : 'Iniciar sesión' }}" data-user-chip>
                <svg width="18" height="18" aria-hidden="true"><use href="#user"/></svg>
                <span class="user-chip-text">{{ auth()->check() ? auth()->user()->name : 'Iniciar sesión' }}</span>
            </a>
            @auth
                {{-- Salir: icono; el texto aparece al pasar el cursor y, en pantallas táctiles, el primer toque lo muestra y el segundo sale. --}}
                <form method="POST" action="{{ route('logout') }}" class="d-flex">@csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm d-flex align-items-center user-chip" data-user-chip data-chip-two-tap aria-label="Cerrar sesión" title="Cerrar sesión ({{ auth()->user()->name }})">
                        <svg width="18" height="18" aria-hidden="true"><use href="#logout"/></svg>
                        <span class="user-chip-text">Cerrar sesión</span>
                    </button>
                </form>
            @endauth
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Menú">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                @auth
                    @if (auth()->user()->isClubAccount())
                    <li class="nav-item"><a class="nav-link d-flex align-items-center gap-2" href="{{ route('panel.athletes.index') }}"><svg width="18" height="18" aria-hidden="true"><use href="#user"/></svg>Mis competidores</a></li>
                    @endif
                    @if (auth()->user()->isSuperAdmin())
                    <li class="nav-item"><a class="nav-link d-flex align-items-center gap-2" href="{{ route('panel.admin.index') }}"><svg width="18" height="18" aria-hidden="true"><use href="#user"/></svg>Administración</a></li>
                    @else
                    <li class="nav-item"><a class="nav-link d-flex align-items-center gap-2" href="{{ route('panel.events.index') }}"><svg width="18" height="18" aria-hidden="true"><use href="#{{ auth()->user()->isTatamiAccount() ? 'flag' : 'calendar' }}"/></svg>{{ auth()->user()->isTatamiAccount() ? 'Mis tatamis' : 'Mis eventos' }}</a></li>
                    @endif
                    @if (! auth()->user()->isClubAccount() && ! auth()->user()->isTatamiAccount() && ! auth()->user()->isSuperAdmin())
                    <li class="nav-item"><a class="nav-link d-flex align-items-center gap-2" href="{{ route('panel.my-operators.index') }}"><svg width="18" height="18" aria-hidden="true"><use href="#user"/></svg>Mis controladores</a></li>
                    @endif
                @endauth
            </ul>
        </div>
    </div>
</nav>
<main class="container pb-5">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @auth
        @if (auth()->user()->isClubAccount() && ! request()->routeIs('panel.invitations.*') && ($invitationCount = auth()->user()->pendingInvitations()->count()) > 0)
            <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Tienes {{ $invitationCount }} {{ $invitationCount === 1 ? 'invitación pendiente' : 'invitaciones pendientes' }} para participar en un evento.</span>
                <a href="{{ route('panel.invitations.index') }}" class="btn btn-sm btn-primary">Ver invitaciones</a>
            </div>
        @endif
    @endauth
    @error('error')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror
    {{-- Errores de campos de formularios del cuadro (resultado, bajas, intercambio) que no se pintan junto al campo. --}}
    @foreach (['winner_id', 'registration_id', 'type', 'reason', 'registration_a', 'registration_b', 'judge_votes', 'abandoned_slot', 'a_wazari', 'b_wazari', 'a_warnings', 'b_warnings'] as $field)
        @error($field)
            <div class="alert alert-danger">{{ $message }}</div>
        @enderror
    @endforeach
    @yield('content')
</main>
<footer class="mt-auto border-top bg-body py-3 no-print">
    <div class="container text-center small text-body-secondary">
        <p class="mb-1">
            <strong>Sitio en pruebas.</strong> Los datos introducidos son ficticios y pueden eliminarse en cualquier momento,
            incluidos los registros e inscripciones realizados.
        </p>
        <p class="mb-0">
            <a href="{{ route('help') }}" class="link-secondary">Ayuda</a> · Web by <a href="https://sotastudi.com" target="_blank" rel="noopener" class="link-secondary">sotastudi</a>
            · v{{ config('app.version') }}@if (config('app.version_stage')) ({{ config('app.version_stage') }})@endif
        </p>
    </div>
</footer>
</body>
</html>
