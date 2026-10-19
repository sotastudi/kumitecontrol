# Eventos próximos/pasados, filtro y paginación en la portada — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** La portada pública muestra por defecto los eventos futuros, con un botón para ver los pasados, una barra de filtro (nombre y periodo de fechas), resultados paginados y la cabecera del sitio fija (sticky).

**Architecture:** `HomeController` pasa a leer `pasados`, `q`, `desde` y `hasta` de la query string, aplica dos scopes nuevos de `Event` (`upcoming`, `past`) sobre `Event::listed()`, filtra y pagina con `paginate(12)->withQueryString()`. La vista `home.blade.php` añade la barra de filtro, el botón de alternancia y `links()`. La cabecera fija es solo CSS/clase en el layout.

**Tech Stack:** Laravel 13, Blade + Bootstrap 5 (paginador ya configurado con `Paginator::useBootstrapFive()`), PHPUnit (`RefreshDatabase`).

**Spec:** petición del usuario (eventos futuros por defecto, botón a pasados, pasan a «pasados» al día siguiente de la fecha, barra de filtro por nombre y periodo de fechas, paginar y cabecera sticky); no hay documento de diseño.

## Global Constraints

- Textos de interfaz en español.
- Solo eventos `Event::listed()` (públicos y no borrador), como hasta ahora.
- «Pasado» = `starts_on < hoy` (un evento de hoy sigue siendo próximo; pasa a pasado al día siguiente). «Hoy» según la zona horaria de la app (`today()`).
- Orden: próximos por `starts_on` ascendente; pasados por `starts_on` descendente (el más reciente primero).
- 12 eventos por página; los filtros y la pestaña se conservan al paginar.
- Versión, tag y push al terminar (ver memoria del proyecto); commits solo de mis cambios; tests con `APP_URL=http://localhost SESSION_PATH=/ /usr/local/bin/php -d memory_limit=1G vendor/bin/phpunit`.

## Rulings de diseño (tomadas al planificar)

- La alternancia es la misma ruta `/` con `?pasados=1`, no una página nueva: así filtros, paginación y enlaces se comparten.
- El filtro (nombre, fechas) se aplica dentro de la pestaña activa, no mezcla próximos y pasados. Si no hay resultados, el mensaje vacío ofrece el enlace a la otra pestaña.
- «Cabecera sticky» se interpreta como la barra de navegación del sitio (`<nav class="navbar">` del layout), fija arriba al hacer scroll. Si querías que fuera la barra de filtro, se cambia en la Tarea 2.

## Review Focus

- Evento con `starts_on` = hoy: aparece en próximos, no en pasados; con `starts_on` = ayer: al revés.
- Nombre con caracteres comodín (`%`, `_`) o con mayúsculas/acentos: se busca literalmente y sin distinguir mayúsculas.
- `desde` posterior a `hasta`, o fechas con formato inválido: la página no da 500; se muestra un aviso y se ignora el periodo.
- Solo `desde` o solo `hasta`: filtra con el extremo abierto.
- Segunda página con filtros activos: los enlaces del paginador conservan `q`, `desde`, `hasta` y `pasados`.

---

### Task 1: Próximos/pasados, filtro y paginación

**Files:**
- Modify: `app/Models/Event.php` (junto a `scopeListed`, ~línea 123)
- Modify: `app/Http/Controllers/HomeController.php`
- Modify: `resources/views/home.blade.php`
- Test: `tests/Feature/HomeEventsTest.php` (nuevo)

**Interfaces:**
- Produces: `Event::scopeUpcoming(Builder $query): void` (`starts_on >= today()`), `Event::scopePast(Builder $query): void` (`starts_on < today()`).
- Produces: `GET /` (`route('home')`) acepta `pasados` (`1` = eventos pasados), `q` (texto), `desde` y `hasta` (`Y-m-d`); entrega a la vista `events` (paginador), `showingPast` (bool), `filters` (array con `q`, `desde`, `hasta`).

- [ ] **Step 1: Write the failing tests** en `HomeEventsTest` (crear los eventos con `Event::factory()` públicos y no borrador, fijando `starts_on` y `Carbon::setTestNow`):
  - `test_default_lists_upcoming_events_ascending_and_hides_past_ones`: hoy y mañana visibles (hoy primero); ayer no.
  - `test_event_moves_to_past_the_day_after_its_date`: con `setTestNow` el día del evento aparece en próximos; con `setTestNow` el día siguiente aparece solo con `?pasados=1`.
  - `test_past_events_are_listed_most_recent_first_and_toggle_buttons_link_between_tabs`: la vista pasada ordena descendente y contiene el enlace `route('home')` (próximos); la vista por defecto contiene el enlace `route('home', ['pasados' => 1])`.
  - `test_filters_by_name_case_insensitive_and_treats_wildcards_literally`: `?q=copa` encuentra «Copa Otoño»; `?q=%` no devuelve todos; `?q=otono` no necesita coincidir con acento (solo `copa`).
  - `test_filters_by_date_range_open_ended_and_invalid_input_does_not_break`: `desde`, `hasta` y ambos filtran con extremos inclusivos; `desde` > `hasta` y `desde=abc` responden 200 con aviso «periodo de fechas no válido» y sin filtrar por fechas.
  - `test_results_are_paginated_by_12_and_keep_filters_in_links`: 13 próximos con `q=Copa` → 12 en la página 1 y enlace de paginador que contiene `q=Copa`; `?page=2` muestra el decimotercero.
- [ ] **Step 2: Run** `... vendor/bin/phpunit --filter=HomeEventsTest` — Expected: FAIL.
- [ ] **Step 3: Implement.** `Event::scopeUpcoming/scopePast` con `whereDate('starts_on', '>=' / '<', today())`. `HomeController::__invoke(Request $request)`: validar `q` (`nullable|string|max:100`), `desde`/`hasta` (`nullable|date_format:Y-m-d`), `pasados` (`boolean`) con `Validator::make` manual (no `$request->validate`, que redirige): si falla o `desde > hasta`, ignorar fechas y pasar `dateError = true`. Consulta: `Event::listed()->{past|upcoming}()`, `when(q, where name LIKE %q%` escapando `\`, `%` y `_` con `addcslashes`), `when(desde, whereDate('starts_on','>=',...))`, `when(hasta, whereDate('starts_on','<=',...))`, `orderBy('starts_on', pasados ? 'desc' : 'asc')`, `paginate(12)->withQueryString()`. Vista: formulario `GET` a `route('home')` con inputs `q`, `desde`, `hasta` (y `pasados` oculto cuando se está en pasados), botón «Filtrar» y enlace «Limpiar» si hay filtros; botón «Ver eventos pasados» / «Ver próximos eventos» que conserva `q`, `desde`, `hasta`; título que indique la pestaña; aviso de fechas no válidas; `{{ $events->links() }}` bajo la rejilla; mensaje vacío con enlace a la otra pestaña.
- [ ] **Step 4: Run** la suite completa con el comando de Global Constraints — Expected: todo en verde (revisar `HomeTest`, `PublicEventTest` y cualquier test que cuente eventos de la portada).
- [ ] **Step 5: Commit** `git commit -m "feat: la portada muestra los eventos próximos, con pasados, filtro y paginación"`; subir versión, tag y push.

### Task 2: Cabecera del sitio fija (sticky)

**Files:**
- Modify: `resources/views/layouts/app.blade.php:131` (clase de la barra de navegación)
- Test: `tests/Feature/HomeTest.php`

**Interfaces:**
- Consumes: la estructura de cabecera existente (no cambia ids ni clases que otros tests comprueban).

- [ ] **Step 1: Write the failing test** `test_site_header_is_sticky` en `HomeTest`: `get(route('home'))` contiene `<nav class="navbar navbar-expand-md bg-body border-bottom mb-4 sticky-top`. Y otra aserción en una página del panel (con usuario autenticado) para decidir si hereda: debe seguir el mismo layout; si el panel usa un layout distinto, el test cubre solo la portada.
- [ ] **Step 2: Run** `--filter=test_site_header_is_sticky` — Expected: FAIL.
- [ ] **Step 3: Implement.** Añadir `sticky-top` a la clase de la `<nav>` (tiene `bg-body`, así que no es transparente). Comprobar que no tapa anclas ni la cabecera de impresión (`no-print` ya existe en el layout; añadir `d-print-none` solo si la `<nav>` hoy sí se imprime).
- [ ] **Step 4: Run** la suite completa — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: la cabecera del sitio se queda fija al hacer scroll"`; subir versión, tag y push.
