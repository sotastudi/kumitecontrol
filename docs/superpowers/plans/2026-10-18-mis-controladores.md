# «Mis controladores» (gestión global de controladores de tatami) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El organizador gestiona desde un apartado propio «Mis controladores» las cuentas de controlador que ha creado, las asigna a varios de sus eventos y, dentro de cada evento, elige a qué tatamis y a qué categorías tiene acceso cada una.

**Architecture:** Las cuentas siguen siendo `User` con `role='tatami'` y `created_by_user_id` = organizador; la asignación a un evento sigue siendo `tatami_operators (event_id, user_id, tatami)`. Se añade una restricción opcional por categoría: `tatami_operators.categories_restricted` (bool, por defecto false = todas las categorías del tatami) más una tabla pivote `tatami_operator_category`. Un controlador restringido solo ve y puntúa los combates de esas categorías (falla cerrado: restringido sin categorías = ninguna). La sección «Controladores de tatami» de la página del evento sigue funcionando sin cambios (sus filas quedan sin restricción).

**Tech Stack:** Laravel (PHP; `/usr/local/bin/php`, suite `-d memory_limit=1G vendor/bin/phpunit`), Blade/Bootstrap, PHPUnit.

**Spec:** petición del usuario (sin documento): «apartado `Mis Controladores` para asignar y ver qué controladores tiene creados y poder asignar a varios eventos. Una vez asignado al evento, podrá asignar a qué categoría y tatami tendrá acceso». Referencia de comportamiento actual: `app/Http/Controllers/Panel/TatamiOperatorController.php`, `app/Policies/EventPolicy.php::operate`, `tests/Feature/TatamiOperatorTest.php`.

## Global Constraints

- Solo organizadores (ni `isClubAccount()`, ni `isTatamiAccount()`, ni `isSuperAdmin()`) ven y usan «Mis controladores»; el resto recibe 403.
- Un organizador solo asigna a **sus** eventos (`Event::user_id = auth id`) y solo edita credenciales de cuentas que creó (`created_by_user_id`), como hoy (`own`).
- Reglas de cuenta idénticas a las actuales: usuario `min:3 max:30 regex:/^[a-z0-9._-]+$/` en minúsculas, contraseña `min:6 max:100` obligatoria al crear, correo opcional y único, nombre opcional (por defecto el usuario). Mensaje de regex: «El usuario solo puede llevar letras minúsculas, números, punto, guion y guion bajo.»
- Sin restricción de categorías = comportamiento actual (todas las del tatami). La política `operate` (acceso al tatami) no cambia; la restricción se aplica a qué combates se ven y se puntúan.
- Textos en español, comentarios cortos en español. Sin dependencias nuevas, sin CSS/JS nuevos (no `npm build`).
- Tras la tarea final: versión 0.0.26, tag `v0.0.26`, `git push --follow-tags` (regla del usuario). Commits solo de mis cambios, por tema. Tests que escriban en disco usan `Storage::fake('local')`.

## Review Focus

- Controlador **restringido** cuyas categorías se borran o cambian de tatami: no debe pasar a ver todo (falla cerrado → ninguna).
- Un controlador no puntúa un combate de una categoría no permitida aunque conozca la URL/ID (`score` → 404).
- Borrar una cuenta que tiene asignaciones en eventos de **otro** organizador (la página del evento permite añadir cuentas existentes por usuario): debe rechazarse con mensaje, no dejar a ese otro evento sin controlador en silencio.
- Marcar un tatami y desmarcar todas las categorías de ese tatami: se interpreta como «todas» (no como «ninguna»), y desmarcar el evento retira todas sus filas (y sus restricciones por cascada).
- Un organizador no puede asignar a un evento ajeno ni usar categorías de otro evento (`categories` validadas contra el evento y contra los tatamis marcados) → 403/422.

## File Structure

- Create `web/database/migrations/2026_10_31_100000_add_category_scope_to_tatami_operators.php` — `categories_restricted` + pivote.
- Modify `web/app/Models/TatamiOperator.php`, `web/app/Models/Event.php` — relación y `allowedCategoryIds`.
- Modify `web/app/Tournament/TatamiBoard.php`, `web/app/Http/Controllers/Panel/TatamiController.php` — aplicar la restricción.
- Create `web/app/Http/Controllers/Panel/MyOperatorsController.php` + `web/resources/views/panel/operators/index.blade.php` — apartado «Mis controladores».
- Modify `web/routes/web.php`, `web/resources/views/layouts/app.blade.php` (enlace de menú), `web/resources/views/panel/events/show.blade.php` (enlace), `web/app/Support/HelpContent.php`, `web/config/app.php` (versión).
- Tests: `web/tests/Feature/OperatorCategoryScopeTest.php`, `web/tests/Feature/MyOperatorsTest.php`.

### Task 1: Modelo de restricción por categoría

**Files:** Create migración; Modify `TatamiOperator.php`, `Event.php`; Test `tests/Feature/OperatorCategoryScopeTest.php`

**Interfaces:**
- Produces: `TatamiOperator::categories(): BelongsToMany` (pivote `tatami_operator_category`, columnas `tatami_operator_id`, `category_id`, único el par, cascade delete); atributo `categories_restricted` (bool, cast, en `#[Fillable]`).
- Produces: `Event::allowedCategoryIds(User $user, int $tatami): ?Collection<int,int>` — `null` = sin restricción (propietario del evento, o fila de operador no restringida); si restringido, ids de sus categorías **que siguen en ese tatami y en este evento** (puede ser vacío). Un usuario sin fila para ese tatami (y no propietario) → colección vacía.

- [ ] **Step 1: Failing tests** (`OperatorCategoryScopeTest`): propietario → `null`; operador sin restricción → `null`; operador restringido a {A} → `[A]`; tras mover A a otro tatami o borrarla → colección vacía (no `null`); usuario sin fila → vacía.
- [ ] **Step 2: Run** `cd web && /usr/local/bin/php artisan test --filter=OperatorCategoryScopeTest` → FAIL.
- [ ] **Step 3: Implement** migración (`boolean('categories_restricted')->default(false)`; pivote con FKs `cascadeOnDelete`), relación y `allowedCategoryIds`.
- [ ] **Step 4: Run** mismo filtro + `php artisan migrate` local → PASS.
- [ ] **Step 5: Commit** `feat: restricción de controladores por categoría (modelo)`

### Task 2: Aplicar la restricción en la vista y puntuación del tatami

**Files:** Modify `TatamiBoard.php`, `TatamiController.php`; Test `OperatorCategoryScopeTest.php`

**Interfaces:**
- Consumes: `Event::allowedCategoryIds`.
- Produces: `TatamiBoard::for(Event $event, bool $publishedOnly = false, ?Collection $categoryIds = null): array` y `TatamiBoard::sequence(Event $event, int $tatami, ?Collection $categoryIds = null): Collection` — con `$categoryIds` no nulo solo consideran esas categorías (`now`, `next`, `pending` y la secuencia salen de ellas). `TatamiController::show/score` pasan `$event->allowedCategoryIds($request->user(), $tatami)`; `score` devuelve 404 si la categoría del combate no está permitida.

- [ ] **Step 1: Failing tests**: con 2 categorías en el tatami 1, operador restringido a la primera: su vista del tatami solo muestra combates de la primera, `now` es el de la primera; puntuar un combate de la segunda → 404 y sin cambios; operador sin restricción y propietario ven ambas; restringido con categorías borradas → vista vacía («sin combates»), no todo; la página pública del evento y el panel del propietario siguen mostrando ambas.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** filtrando por `whereIn('category_id', …)` cuando el parámetro no es null.
- [ ] **Step 4: Run** `--filter='OperatorCategoryScopeTest|TatamiOperatorTest|TatamiBoardTest|TatamiAndProgressTest'` → PASS.
- [ ] **Step 5: Commit** `feat: los controladores restringidos solo ven y puntúan sus categorías`

### Task 3: Apartado «Mis controladores» (lista, alta, edición y baja de cuentas)

**Files:** Create `MyOperatorsController.php`, `resources/views/panel/operators/index.blade.php`; Modify `routes/web.php`, `layouts/app.blade.php`; Test `MyOperatorsTest.php`

**Interfaces:**
- Produces rutas (grupo `auth`, prefijo `panel`): `GET controladores` → `panel.my-operators.index`; `POST controladores` → `.store`; `PUT controladores/{operator}` → `.update`; `DELETE controladores/{operator}` → `.destroy` (`{operator}` = `User`).
- `index(Request): View` — cuentas con `role='tatami'` y `created_by_user_id = auth id`, más cuentas que operan en eventos del organizador aunque no las creara (sin edición de credenciales), cada una con sus asignaciones agrupadas por evento (`tatamis` y categorías). Todas las acciones: 403 si el usuario es club/tatami/superadmin; `update`/`destroy` sobre cuentas no creadas por el organizador → 403 (la asignación es otra tarea).
- `store`: crea la cuenta con las reglas de Global Constraints (sin asignar a ningún evento); si el usuario ya existe → error de validación «Ese usuario ya existe». `update`: usuario/nombre/correo/contraseña (la contraseña solo si se rellena, único usuario/correo ignorando la propia cuenta). `destroy`: rechaza con mensaje si la cuenta tiene asignaciones en eventos de **otro** organizador; si no, borra la cuenta (cascada de sus filas).

- [ ] **Step 1: Failing tests**: organizador ve solo sus cuentas (no las de otro organizador) y las que operan en sus eventos; crea cuenta (usuario en mayúsculas se normaliza; regex y contraseña corta fallan); edita sin cambiar la contraseña si va vacía; no puede editar ni borrar una cuenta ajena (403); borrar una cuenta con asignación en un evento de otro organizador → error y la cuenta sigue; club/tatami/superadmin → 403 en `index`.
- [ ] **Step 2: Run** `--filter=MyOperatorsTest` → FAIL.
- [ ] **Step 3: Implement** controlador (extraer las reglas de usuario compartidas con `TatamiOperatorController` a un método privado/`FormRequest` solo si evita duplicar >10 líneas), vista (tarjetas por controlador con «Editar» colapsable, «Nuevo controlador» colapsable; reutiliza los botones de generar/copiar contraseña `data-generate-password`/`data-copy-password` de `panel/events/show`), enlace «Mis controladores» en el menú de organizadores junto a «Eventos» (`layouts/app.blade.php` ~línea 195).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: apartado Mis controladores (cuentas del organizador)`

### Task 4: Asignar controladores a eventos, tatamis y categorías

**Files:** Modify `MyOperatorsController.php`, `index.blade.php`, `routes/web.php`; Test `MyOperatorsTest.php`

**Interfaces:**
- Produces: `PUT controladores/{operator}/eventos/{event}` → `panel.my-operators.assign` (`MyOperatorsController::assign(Request, User $operator, Event $event): RedirectResponse`). Entrada: `tatamis[]` (ints 1..`$event->tatamiCount()`), `categories[]` (ids). Reglas: el evento debe ser del organizador (si no, 403); `$operator` debe ser una cuenta de tatami visible para él (creada por él o ya asignada en sus eventos). Sin `tatamis` ⇒ quita al controlador del evento (borra sus filas). Con tatamis ⇒ sincroniza filas (`firstOrCreate`/`delete` de las no marcadas). Por cada tatami marcado: sea `C` el conjunto de ids de categorías del evento en ese tatami y `S = categories ∩ C`; si `S` es vacío o igual a `C` ⇒ `categories_restricted=false` y sin pivote; si no ⇒ `categories_restricted=true` y pivote = `S`. Ids de categorías que no sean del evento o no pertenezcan a un tatami marcado ⇒ 422.
- Vista: dentro de la tarjeta de cada controlador, una fila por cada evento propio (ordenado por fecha, solo eventos con tatamis > 0) con casilla «Asignado», casillas de tatami y, bajo cada tatami marcado, la lista de sus categorías (todas marcadas por defecto = sin restricción); un formulario por evento con «Guardar».

- [ ] **Step 1: Failing tests**: asignar a dos eventos distintos con tatamis distintos (aparece en ambos); restringir a una categoría del tatami 1 deja `categories_restricted=true` y el pivote correcto; marcar todas o ninguna ⇒ sin restricción; desmarcar el evento (sin tatamis) borra sus filas y la restricción; evento ajeno → 403; categoría de otro evento o de tatami no marcado → 422; tras asignar, el controlador ve el evento en su listado de tatamis (`panel.events.index`) y, restringido, solo sus categorías (reutilizando Task 2); la sección «Controladores de tatami» de la página del evento lista a la cuenta.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `--filter='MyOperatorsTest|TatamiOperatorTest'` → PASS.
- [ ] **Step 5: Commit** `feat: asignar controladores a eventos, tatamis y categorías`

### Task 5: Enlaces, ayuda, versión y despliegue

**Files:** Modify `resources/views/panel/events/show.blade.php` (nota con enlace «Gestionar todos tus controladores» en la sección de controladores), `app/Support/HelpContent.php` (guía del organizador y pregunta frecuente sobre «Mis controladores»), `config/app.php`.

- [ ] **Step 1:** Test: la página del evento y la ayuda mencionan «Mis controladores» (`HelpTest` existente verde).
- [ ] **Step 2:** Implementar enlaces/textos; suite completa `-d memory_limit=1G vendor/bin/phpunit` → verde.
- [ ] **Step 3:** Commit `docs: enlaces y ayuda de Mis controladores`; `chore: versión 0.0.26`, tag `v0.0.26`, `git push --follow-tags`. En producción: `php artisan migrate` (hay migración nueva).
