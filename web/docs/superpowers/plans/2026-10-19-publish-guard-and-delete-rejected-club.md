# Publicar solo con cuadro y eliminar clubs rechazados — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el botón «Publicar» de una categoría no sea clicable mientras no exista cuadro, y que el organizador pueda eliminar de la lista de un evento a un club rechazado.

**Architecture:** Tarea 1 es solo de vista: el backend ya lo impide (`PublicationController::toggle` devuelve el error «Genera el cuadro antes de publicar la categoría.») y la página del cuadro ya oculta el botón; falta la lista de categorías de `events/show`. Tarea 2 añade una ruta `DELETE` y una acción `destroy` en `ClubController` que solo borra el `EventClub` si está rechazado, más un botón en la lista de clubs.

**Tech Stack:** Laravel 13, Blade + Bootstrap, PHPUnit (`RefreshDatabase`).

**Spec:** petición del usuario (Tarea 1 y Tarea 2); no hay documento de diseño.

## Global Constraints

- Textos de interfaz en español, mismo estilo que el resto del panel.
- Botones de acción de tabla: clases `btn btn-sm ...`, como los existentes.
- No tocar `is_published` fuera de `PublicationController` (no está en `#[Fillable]` a propósito).
- Versión, tag y push al terminar cada tarea (ver memoria del proyecto); commits solo de mis cambios.
- Tests: `php artisan test` con PHP 8.5 (ver memoria «Sin pistas del dominio en el repo»).

## Review Focus

- Categoría ya publicada que se queda sin cuadro (p. ej. tras reiniciar): el botón «Ocultar» debe seguir activo para poder despublicarla.
- Petición manual `PATCH` de publicación sin cuadro: sigue rechazada (ya cubierto por `PublicResultsTest::test_owner_publishes_only_a_generated_category`).
- `DELETE` de un club aprobado, pendiente o invitado: debe dar 404/rechazo y no borrar nada.
- `DELETE` de un club rechazado de OTRO evento (ID cruzado) o de otro organizador: no debe borrarse.
- Tras eliminar un club rechazado, la cuenta de usuario del club y sus inscripciones previas siguen intactas, y puede volver a pedir registro o ser invitado.

---

### Task 1: Botón «Publicar» apagado si la categoría no tiene cuadro

**Files:**
- Modify: `app/Http/Controllers/Panel/EventController.php:65` (añadir `'bouts'` al `withCount` de `categories` en `show`)
- Modify: `resources/views/panel/events/show.blade.php:104-108`
- Test: `tests/Feature/PublicResultsTest.php`

**Interfaces:**
- Consumes: relación `Category::bouts()` (HasMany) existente.
- Produces: atributo `bouts_count` en cada categoría de `events.show`.

- [ ] **Step 1: Write the failing test** en `PublicResultsTest`: `test_event_page_disables_publish_until_the_category_has_a_bracket`. Con una categoría sin combates (`Category::factory()->for($event)`), `get(route('panel.events.show', $event))` debe contener `disabled` en el botón «Publicar» de esa categoría (assertSee del marcado `title="Genera el cuadro antes de publicar"`) y no el formulario con `panel.categories.publication`. Con `$this->generated(4)` (categoría con cuadro, dueño asignado como en el test vecino) la página sí contiene la acción `route('panel.categories.publication', ...)`. Tercer caso: categoría sin combates pero con `is_published = true` (vía `forceFill`) sigue mostrando el formulario «Ocultar».
- [ ] **Step 2: Run** `php artisan test --filter=test_event_page_disables_publish_until_the_category_has_a_bracket` — Expected: FAIL.
- [ ] **Step 3: Implement.** En `EventController::show`, cambiar `withCount([...])` para incluir también `'bouts'`. En la vista, antes del `<form>` de publicación: si `! $category->is_published && $category->bouts_count === 0`, pintar `<button type="button" class="btn btn-outline-success btn-sm btn-icon" disabled title="Genera el cuadro antes de publicar">` con el mismo icono `#share` y texto «Publicar»; en caso contrario, el formulario actual sin cambios. La página del cuadro (`brackets/show.blade.php:60`) ya cumple y no se toca.
- [ ] **Step 4: Run** `php artisan test --filter=PublicResultsTest` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: el botón Publicar de la lista de categorías se apaga si no hay cuadro"` (incluye subida de versión y tag).

### Task 2: Eliminar de la lista un club rechazado

**Files:**
- Modify: `routes/web.php:123` (junto a `clubs.update`)
- Modify: `app/Http/Controllers/Panel/ClubController.php` (nueva acción al final)
- Modify: `resources/views/panel/clubs/index.blade.php:87-94`
- Test: `tests/Feature/ClubInvitationTest.php` (ya tiene helper `clubFor` y usa estados de `ClubStatus`)

**Interfaces:**
- Consumes: `EventClub` (`status` casteado a `ClubStatus`), ruta con `{club}` enlazada como en `clubs.update`.
- Produces: ruta `panel.clubs.destroy` (`DELETE clubes/{club}`) y `ClubController::destroy(Event $event, EventClub $club): RedirectResponse`.

- [ ] **Step 1: Write the failing tests** en `ClubInvitationTest`:
  - `test_organizer_can_delete_a_rejected_club_from_the_event_list`: club rechazado de un evento del organizador → `delete(route('panel.clubs.destroy', [$event, $reg]))` redirige con `status`, `EventClub` ya no existe, el `User` del club sigue existiendo, y el club puede volver a registrarse (`public.clubs.store`) o ser invitado.
  - `test_only_rejected_clubs_can_be_deleted`: para `Approved`, `Pending` e `Invited`, el `DELETE` no borra la fila (comprobar `assertDatabaseHas` y que la respuesta no es 2xx/redirect con status de éxito; 404).
  - `test_cannot_delete_a_rejected_club_through_another_event_or_organizer`: `DELETE` con el ID de un club rechazado de otro evento propio (URL del evento equivocado) → 404; con otro organizador → 403/404; la fila permanece.
  - El botón: la página `panel.clubs.index` muestra «Eliminar» solo en la fila del club rechazado.
- [ ] **Step 2: Run** `php artisan test --filter=ClubInvitationTest` — Expected: FAIL (ruta no definida).
- [ ] **Step 3: Implement.** Ruta `Route::delete('clubes/{club}', [ClubController::class, 'destroy'])->name('clubs.destroy');`. En el controlador: `abort_unless($club->event_id === $event->id && $club->status === ClubStatus::Rejected, 404)`, `$club->delete()`, `back()->with('status', "Club «{$club->user->name}» eliminado de la lista.")`. En la vista, dentro de la celda de acciones y solo si `$club->status === ClubStatus::Rejected`, un formulario `DELETE` con `onsubmit="return confirm('¿Eliminar este club de la lista del evento?')"` y botón `btn btn-sm btn-outline-danger` «Eliminar», como el patrón de `events/show.blade.php:110`. No se borra la cuenta del usuario.
- [ ] **Step 4: Run** `php artisan test` (toda la suite) — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat: el organizador puede eliminar de la lista a un club rechazado"` (incluye subida de versión y tag).
