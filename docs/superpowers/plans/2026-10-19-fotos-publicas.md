# Foto del inscrito visible públicamente (opt-in por evento y por ficha) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una foto de inscrito se muestra en las vistas del controlador de tatami y en las públicas solo si el evento permite mostrar fotos **y** la ficha del inscrito permite mostrar la suya **y** la foto existe; la ficha manda: si dice «no mostrar», no se muestra aunque el evento lo permita.

**Architecture:** Dos banderas: `events.show_photos_publicly` (por defecto false: hay que activarlo) y `competitors.photo_public` (por defecto true: el inscrito/organizador puede desactivarla). Una única regla en `Competitor::publicPhotoUrl(Event): ?string` decide si hay URL; una ruta nueva tras el código de acceso (`EnsureResultsAccess`) sirve el fichero y aplica la misma regla; un componente Blade `<x-competitor-photo>` pinta la miniatura (o nada) en todas las vistas. La ruta del panel (`panel.competitors.photo`, siempre visible para el organizador) no cambia.

**Tech Stack:** Laravel (PHP; `/usr/local/bin/php`, suite `-d memory_limit=1G vendor/bin/phpunit`), Blade/Bootstrap, PHPUnit.

**Spec:** petición del usuario (sin documento): «En la inscripción marcar la opción de mostrar o ocultar foto públicamente. En el evento, marcar la opción de mostrar o ocultar imágenes de inscritos públicamente (prioriza la ficha: si la ficha tiene no mostrar, aunque el evento permita mostrarla, no se mostrará). Revisar las vistas de la parte pública para poder mostrar la imagen si se permite y está disponible. En la vista de controlador de tatami y en la vista de público.» Contexto de privacidad actual: `docs/menores.md` (la foto hoy es privada, también la de menores).

## Global Constraints

- Regla única de visibilidad: `foto presente (photo_path relleno) ∧ competitor.photo_public ∧ event.show_photos_publicly`. La ficha (`photo_public = false`) gana siempre.
- Valores por defecto: `events.show_photos_publicly = false`; `competitors.photo_public = true`. Los inscritos existentes quedan con `true`; como los eventos existentes quedan en `false`, **nada se publica hasta que el organizador active el evento**.
- Las fotos solo se sirven tras el código de acceso del evento (o a su propietario / controladores de tatami), igual que cuadros y resultados (`EnsureResultsAccess`, `ResultsAccess`); nunca a un visitante sin código ni en eventos no listados sin sesión autorizada.
- El organizador sigue viendo siempre la foto en su ficha (`panel.competitors.photo`) con independencia de las banderas.
- Etiquetas de formulario (español): evento «Mostrar públicamente las fotos de los inscritos»; ficha/inscripción «Mostrar mi foto públicamente (si el organizador lo permite)». Textos que dicen que la foto «no se publica» se corrigen (`panel/competitors/_fields`, `public/registration.blade.php`, `docs/menores.md`, `docs/README.md`, `docs/decisiones-pendientes.md`, `HelpContent`).
- No se copian fotos entre eventos ni hay foto en el plantel del club (`club_athletes`); sin cambios ahí.
- Sin dependencias nuevas. CSS mínimo y reutilizable (una clase para la miniatura); recompilar con `npm run build` (ver memoria de node) y avisar del build en producción. Tests que escriban en disco usan `Storage::fake('local')`.
- Tras la tarea final: versión 0.0.29, tag `v0.0.29`, `git push --follow-tags` (regla del usuario). Commits solo de mis cambios, por tema.

## Review Focus

- Menor de edad con foto: la regla pedida no distingue edad ni consentimiento del representante (`guardian_consent_at`); con el evento activado, la foto de un menor se publicaría si la ficha lo permite (por defecto `true`). **Decisión abierta para el usuario** (ver mensaje de entrega); el plan implementa la regla literal y deja el punto señalado.
- Acceso directo a la URL de la foto sin código de acceso, con la ficha en «no mostrar», con el evento en «no mostrar» o con un competidor de **otro** evento: 302 a la página del evento / 404, nunca el fichero.
- Foto referenciada en BD pero fichero borrado (`photo_path` sin fichero): la vista no debe romperse (la ruta da 404 y la miniatura no aparece; sin excepción).
- Cuadros grandes (decenas de combates): el componente no hace consultas ni accesos a disco por inscrito (solo mira columnas ya cargadas); `with('slotA.competitor')` ya existe en las vistas.
- Quitar la foto o desmarcar «mostrar» desde la ficha deja de servirla de inmediato (cache `private`, no `public`).

## File Structure

- Create `web/database/migrations/2026_11_01_100000_add_photo_visibility_flags.php` — `events.show_photos_publicly`, `competitors.photo_public`.
- Modify `web/app/Models/Event.php`, `web/app/Models/Competitor.php` — fillable, casts, `FIELDS`, `publicPhotoUrl`.
- Modify `web/app/Http/Requests/EventRequest.php`, `CompetitorRequest.php`, `RegistrationRequest.php`; `web/app/Registrations/RegistrationService.php`.
- Modify `web/app/Http/Controllers/PublicEventController.php` + `web/routes/web.php` — ruta pública de la foto.
- Create `web/resources/views/components/competitor-photo.blade.php`; modify `web/resources/css/app.css`.
- Modify views: `panel/events/_form`, `panel/competitors/_fields`, `public/registration`, `partials/tatami-bout`, `panel/tatamis/_upcoming-bout`, `partials/bout-card`, `public/events/show`, `public/categories/show`, `partials/podium`.
- Modify docs: `docs/menores.md`, `docs/README.md`, `docs/decisiones-pendientes.md`, `web/app/Support/HelpContent.php`, `web/config/app.php`.
- Tests: `web/tests/Feature/PublicPhotoTest.php` (nuevo), ajustes en `EventTest`, `CompetitorPhotoTest`, `PublicRegistrationTest` si algún texto cambia.

### Task 1: Banderas y regla de visibilidad

**Files:** Create migración; Modify `Event.php`, `Competitor.php`; Test `tests/Feature/PublicPhotoTest.php`

**Interfaces:**
- Produces: columnas `events.show_photos_publicly` (boolean, default false) y `competitors.photo_public` (boolean, default true), ambas con cast `boolean` y en `#[Fillable]`; `Competitor::FIELDS` incluye `photo_public`.
- Produces: `Competitor::publicPhotoUrl(Event $event): ?string` — `null` salvo que `filled($this->photo_path) && $this->photo_public && $event->show_photos_publicly`; entonces `route('public.competitors.photo', [$event, $this]).'?v='.$this->updated_at?->timestamp` (la ruta se crea en Task 3; hasta entonces el test de URL puede usar la misma `route()` tras Task 3 — implementar la ruta mínima o ordenar commits en consecuencia). No toca disco.

- [ ] **Step 1: Failing tests** (matriz de la regla): con foto, evento `true`, ficha `true` ⇒ URL; evento `false` ⇒ null; ficha `false` ⇒ null aunque el evento sea `true`; sin `photo_path` ⇒ null; valores por defecto (`show_photos_publicly=false`, `photo_public=true`) tras `Event::factory()` / `Competitor::factory()`.
- [ ] **Step 2: Run** `cd web && /usr/local/bin/php artisan test --filter=PublicPhotoTest` → FAIL.
- [ ] **Step 3: Implement** migración (con `down()`), casts, fillable, `FIELDS`, `publicPhotoUrl`.
- [ ] **Step 4: Run** mismo filtro + `php artisan migrate` local → PASS.
- [ ] **Step 5: Commit** `feat: banderas de visibilidad pública de fotos (evento y ficha)`

### Task 2: Casillas en el evento, la ficha y los formularios de inscripción

**Files:** Modify `panel/events/_form.blade.php`, `EventRequest.php`, `panel/competitors/_fields.blade.php`, `CompetitorRequest.php`, `RegistrationRequest.php`, `RegistrationService.php`, `public/registration.blade.php`; Test `PublicPhotoTest.php`

**Interfaces:**
- Consumes: columnas y `FIELDS` de Task 1.
- Produces: el formulario del evento guarda `show_photos_publicly` (`x-check`, regla `['boolean']` en `EventRequest`, ausente ⇒ false); la ficha (edición del organizador) guarda `photo_public` (`x-check` con valor actual, por defecto marcado para fichas nuevas); `RegistrationRequest` acepta `'photo_public' => ['nullable', 'boolean']` y `RegistrationService::findOrCreateCompetitor` lo almacena al crear (un competidor existente no se sobrescribe, como el resto de datos). Los textos «Es un dato privado: no se publica.» y «la foto no se publica» pasan a: «Solo se muestra públicamente si el organizador lo permite en el evento y tú lo marcas aquí.» (adaptado a cada contexto).

- [ ] **Step 1: Failing tests**: organizador marca/desmarca la casilla en crear y editar evento (y queda en BD, ausente ⇒ false); en la ficha, `photo_public=0` y `=1` se guardan; inscripción pública (individual y como club) con `photo_public=0` crea al competidor con `photo_public=false`, sin el campo ⇒ `true`; la página de la ficha y la de inscripción muestran la casilla y ya no contienen «no se publica».
- [ ] **Step 2: Run** `--filter='PublicPhotoTest|EventTest|PublicRegistrationTest|CompetitorPhotoTest|ClubRegistrationTest'` → FAIL.
- [ ] **Step 3: Implement** campos, reglas y textos.
- [ ] **Step 4: Run** mismos filtros → PASS.
- [ ] **Step 5: Commit** `feat: casillas para mostrar u ocultar la foto públicamente`

### Task 3: Ruta pública de la foto y componente de miniatura

**Files:** Modify `PublicEventController.php`, `routes/web.php`, `resources/css/app.css`; Create `resources/views/components/competitor-photo.blade.php`; Test `PublicPhotoTest.php`

**Interfaces:**
- Consumes: `Competitor::publicPhotoUrl`.
- Produces: `GET eventos/{event:slug}/competidores/{competitor}/foto` → `public.competitors.photo` (`PublicEventController::competitorPhoto(Event $event, Competitor $competitor): StreamedResponse`), middleware `EnsureResultsAccess`, `scopeBindings()`; responde 404 si `publicPhotoUrl($event)` es null o el fichero no existe; cabeceras `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=600`.
- Produces: componente `<x-competitor-photo :competitor="$c" :event="$event" class="…" />` (props `competitor`, `event`, `size` opcional `sm|md|lg`, por defecto `sm`): si `publicPhotoUrl($event)` es null no pinta nada; si no, `<img src alt="Foto de {nombre}" loading="lazy" class="competitor-photo competitor-photo-{size}">`. CSS: miniatura redonda `object-fit: cover`, `sm` 2rem, `md` 3rem, `lg` 5rem, `vertical-align: middle`.

- [ ] **Step 1: Failing tests**: con código de acceso en sesión (usar el helper de los tests públicos existentes, p. ej. `PublicEventTest`/`PublicResultsTest`) y flags activos ⇒ 200 con `Content-Type` imagen y cabecera `private`; sin código ⇒ redirección a la página del evento; evento en «no mostrar» ⇒ 404; ficha en «no mostrar» ⇒ 404 aunque el evento lo permita; competidor de otro evento ⇒ 404; `photo_path` sin fichero ⇒ 404; propietario y controlador de tatami (sin código) ⇒ 200 con flags activos; el componente no pinta nada cuando la regla no se cumple y pinta `<img` con la URL cuando sí (render con `Blade::render`).
- [ ] **Step 2: Run** `--filter=PublicPhotoTest` → FAIL.
- [ ] **Step 3: Implement** acción, ruta, componente y CSS; `PATH=… npm run build`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: ruta pública de la foto y componente de miniatura`

### Task 4: Foto en las vistas de tatami y públicas

**Files:** Modify `partials/tatami-bout.blade.php`, `panel/tatamis/_upcoming-bout.blade.php`, `partials/bout-card.blade.php`, `public/events/show.blade.php`, `public/categories/show.blade.php`, `partials/podium.blade.php`; Test `PublicPhotoTest.php`

**Interfaces:**
- Consumes: `<x-competitor-photo>` (Task 3). Produces: la miniatura aparece junto al nombre del competidor en: vista del tatami (combate actual, tamaño `lg` en la tarjeta de cada competidor), combates siguientes del tatami (`sm`), tarjetas del cuadro `bout-card` (`sm`, tanto panel como público), bloque «Tatamis ahora» de la página pública (`sm`), tabla de resultados y podio de la página pública de categoría (`sm`). En todas, solo si se cumple la regla.

- [ ] **Step 1: Failing tests** (con fotos y flags, páginas con código de acceso o como propietario/controlador): la vista del tatami (`panel.tatamis.show`) contiene la URL de la foto de cada contendiente cuando evento y ficha lo permiten, y no la contiene si la ficha de uno de ellos está en «no mostrar» (el otro sí aparece); la página pública del evento («Tatamis ahora»), la de categoría (cuadro, resultados, podio) y las tarjetas del cuadro muestran la foto solo bajo la regla; con el evento en «no mostrar» ninguna vista contiene `competidores/…/foto`; el panel del organizador de la ficha sigue mostrando su foto con todo desactivado.
- [ ] **Step 2: Run** `--filter='PublicPhotoTest|TatamiOperatorTest|PublicResultsTest|BracketPanelTest'` → FAIL.
- [ ] **Step 3: Implement** inserciones del componente en cada vista (sin duplicar la regla en Blade).
- [ ] **Step 4: Run** mismos filtros → PASS.
- [ ] **Step 5: Commit** `feat: fotos de inscritos en las vistas de tatami y públicas`

### Task 5: Documentación, ayuda, versión y despliegue

**Files:** Modify `docs/menores.md`, `docs/README.md`, `docs/decisiones-pendientes.md`, `web/app/Support/HelpContent.php`, `web/config/app.php`; Test `HelpTest` existente.

- [ ] **Step 1:** Actualizar docs y ayuda (la foto deja de ser siempre privada: es opt-in por evento y por ficha; la ficha manda; ver la nota sobre menores) y una pregunta frecuente «¿Cómo muestro u oculto las fotos de los inscritos?».
- [ ] **Step 2:** Suite completa `-d memory_limit=1G vendor/bin/phpunit` → verde; commit `docs: fotos públicas opt-in en docs y ayuda`.
- [ ] **Step 3:** `chore: versión 0.0.29`, tag `v0.0.29`, `git push --follow-tags`. Producción: `php artisan migrate` y recompilar assets (`npm run build`).
