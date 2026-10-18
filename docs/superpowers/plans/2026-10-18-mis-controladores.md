# «Mis controladores» (gestión global de controladores de tatami) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El organizador gestiona desde un apartado propio «Mis controladores» las cuentas de controlador que ha creado, las asigna a varios de sus eventos y elige, en cada evento, a qué tatamis tiene acceso cada una.

**Architecture:** Sin cambios de modelo: las cuentas siguen siendo `User` con `role='tatami'` y `created_by_user_id` = organizador, y la asignación sigue siendo `tatami_operators (event_id, user_id, tatami)` (`Event::operators()`, política `operate`). Se añade un controlador nuevo (`MyOperatorsController`) con una vista global que reutiliza las reglas de cuenta de `TatamiOperatorController`. No se restringe por categoría: el acceso es por tatami, como hoy. La sección «Controladores de tatami» de la página del evento sigue funcionando.

**Tech Stack:** Laravel (PHP; `/usr/local/bin/php`, suite `-d memory_limit=1G vendor/bin/phpunit`), Blade/Bootstrap, PHPUnit.

**Spec:** petición del usuario (sin documento): «apartado `Mis Controladores` para asignar y ver qué controladores tiene creados y poder asignar a varios eventos», con acceso por tatami (corrección posterior: **no** se restringe por categoría). Comportamiento actual de referencia: `app/Http/Controllers/Panel/TatamiOperatorController.php`, `app/Policies/EventPolicy.php::operate`, `tests/Feature/TatamiOperatorTest.php`.

## Global Constraints

- Solo organizadores (ni `isClubAccount()`, ni `isTatamiAccount()`, ni `isSuperAdmin()`) ven y usan «Mis controladores»; el resto recibe 403.
- Un organizador solo asigna a **sus** eventos (`Event::user_id` = su id) y solo edita credenciales de cuentas que creó (`created_by_user_id`), como hoy (`own`).
- Reglas de cuenta idénticas a las actuales: usuario en minúsculas `min:3 max:30 regex:/^[a-z0-9._-]+$/`, contraseña `min:6 max:100` obligatoria al crear, correo opcional y único, nombre opcional (por defecto el usuario). Mensaje de regex: «El usuario solo puede llevar letras minúsculas, números, punto, guion y guion bajo.»
- Tatamis válidos: enteros de 1 a `$event->tatamiCount()`.
- Textos en español, comentarios cortos en español. Sin dependencias, CSS ni JS nuevos (no `npm build`). Sin migraciones.
- Tras la tarea final: versión 0.0.26, tag `v0.0.26`, `git push --follow-tags` (regla del usuario). Commits solo de mis cambios, por tema.

## Review Focus

- Borrar una cuenta que tiene asignaciones en eventos de **otro** organizador (la página del evento permite añadir cuentas existentes por usuario): debe rechazarse con mensaje, no dejar a ese otro evento sin controlador en silencio.
- Un organizador no puede asignar a un evento ajeno, ni editar/borrar cuentas que no creó, ni ver las cuentas de otro organizador → 403 / no aparecen.
- Desmarcar el evento (sin tatamis) retira todas las filas del controlador en ese evento; desmarcar un tatami solo retira ese; marcar uno ya asignado no duplica (único `event_id,user_id,tatami`).
- Evento sin tatamis configurados (`tatamiCount() = 0`) no aparece en el formulario de asignación ni acepta POST.
- Cambiar usuario/correo a uno ya existente da error de validación (ignorando la propia cuenta), y dejar la contraseña vacía no la cambia.

## File Structure

- Create `web/app/Http/Controllers/Panel/MyOperatorsController.php` — lista, alta, edición, baja y asignación.
- Create `web/resources/views/panel/operators/index.blade.php` — vista «Mis controladores».
- Modify `web/routes/web.php`, `web/resources/views/layouts/app.blade.php` (enlace de menú), `web/resources/views/panel/events/show.blade.php` (enlace), `web/app/Support/HelpContent.php`, `web/config/app.php` (versión).
- Tests: `web/tests/Feature/MyOperatorsTest.php`.

### Task 1: Apartado «Mis controladores» (lista, alta, edición y baja de cuentas)

**Files:** Create `MyOperatorsController.php`, `resources/views/panel/operators/index.blade.php`; Modify `routes/web.php`, `layouts/app.blade.php`; Test `tests/Feature/MyOperatorsTest.php`

**Interfaces:**
- Produces rutas (grupo `auth`, prefijo `panel`): `GET controladores` → `panel.my-operators.index`; `POST controladores` → `.store`; `PUT controladores/{operator}` → `.update`; `DELETE controladores/{operator}` → `.destroy` (`{operator}` = `User`).
- `index(Request): View` — cuentas `role='tatami'` con `created_by_user_id` = organizador, más las cuentas que operan en eventos del organizador aunque no las creara (sin edición de credenciales); cada una con sus asignaciones agrupadas por evento (tatamis). Todas las acciones: 403 si el usuario es club/tatami/superadmin; `update`/`destroy` sobre cuentas no creadas por el organizador → 403.
- `store(Request): RedirectResponse`: crea la cuenta (Global Constraints) sin asignarla a ningún evento; si el usuario ya existe → error de validación «Ese usuario ya existe». `update`: usuario/nombre/correo/contraseña (la contraseña solo si se rellena; único usuario/correo ignorando la propia cuenta). `destroy`: rechaza con mensaje si la cuenta tiene asignaciones en eventos de **otro** organizador; si no, borra la cuenta (cascada de sus filas).

- [ ] **Step 1: Failing tests** (`MyOperatorsTest`): organizador ve solo sus cuentas (no las de otro organizador) y las que operan en sus eventos; crea cuenta (usuario en mayúsculas se normaliza; regex y contraseña corta fallan; usuario repetido falla); edita sin cambiar la contraseña si va vacía y con duplicado de correo da error; no puede editar ni borrar una cuenta ajena (403); borrar una cuenta con asignación en un evento de otro organizador → error y la cuenta sigue; club/tatami/superadmin → 403 en `index`.
- [ ] **Step 2: Run** `cd web && /usr/local/bin/php artisan test --filter=MyOperatorsTest` → FAIL.
- [ ] **Step 3: Implement** controlador (extraer las reglas de usuario compartidas con `TatamiOperatorController` solo si evita duplicar más de 10 líneas), vista (tarjeta por controlador con «Editar» colapsable y «Nuevo controlador» colapsable; reutiliza los botones `data-generate-password` / `data-copy-password` de `panel/events/show`) y enlace «Mis controladores» en el menú de organizadores junto a «Eventos» (`layouts/app.blade.php` ~línea 195).
- [ ] **Step 4: Run** mismo filtro → PASS.
- [ ] **Step 5: Commit** `feat: apartado Mis controladores (cuentas del organizador)`

### Task 2: Asignar controladores a varios eventos y tatamis

**Files:** Modify `MyOperatorsController.php`, `index.blade.php`, `routes/web.php`; Test `MyOperatorsTest.php`

**Interfaces:**
- Consumes: rutas y vista de Task 1.
- Produces: `PUT controladores/{operator}/eventos/{event}` → `panel.my-operators.assign` (`MyOperatorsController::assign(Request, User $operator, Event $event): RedirectResponse`). Entrada: `tatamis[]` (ints 1..`$event->tatamiCount()`, opcional). El evento debe ser del organizador (si no, 403); `$operator` debe ser una cuenta visible para él (Task 1). Sin `tatamis` ⇒ retira al controlador del evento (borra sus filas); con tatamis ⇒ sincroniza filas con `$event->operators()->firstOrCreate(...)` y borra las no marcadas. Tatami fuera de rango ⇒ 422.
- Vista: dentro de cada tarjeta, una fila por cada evento propio con tatamis > 0 (ordenado por fecha), con casilla por tatami (marcados los actuales) y botón «Guardar»; desmarcar todos ⇒ no asignado.

- [ ] **Step 1: Failing tests**: asignar a dos eventos con tatamis distintos (aparece en ambos y en `Event::operators()`); reasignar quita los desmarcados y no duplica; sin tatamis retira del evento; evento ajeno → 403; tatami fuera de rango → 422; evento con `tatami_count = 0` no aparece en la vista; tras asignar, la cuenta ve el evento en su panel (`panel.events.index`) y puede abrir `panel.tatamis.show` de sus tatamis; la sección «Controladores de tatami» de la página del evento lista a la cuenta.
- [ ] **Step 2: Run** `--filter=MyOperatorsTest` → FAIL.
- [ ] **Step 3: Implement** `assign` y el formulario por evento.
- [ ] **Step 4: Run** `--filter='MyOperatorsTest|TatamiOperatorTest'` → PASS.
- [ ] **Step 5: Commit** `feat: asignar controladores a varios eventos y tatamis`

### Task 3: Enlaces, ayuda, versión y despliegue

**Files:** Modify `resources/views/panel/events/show.blade.php` (enlace «Gestionar todos tus controladores» en la sección de controladores), `app/Support/HelpContent.php` (guía del organizador y una pregunta frecuente sobre «Mis controladores»), `config/app.php`; Test `MyOperatorsTest.php`.

- [ ] **Step 1: Failing test**: la página del evento y la ayuda mencionan «Mis controladores» y el enlace apunta a `panel.my-operators.index`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** enlaces y textos. **Step 4:** suite completa `-d memory_limit=1G vendor/bin/phpunit` → verde.
- [ ] **Step 5: Commit** `docs: enlaces y ayuda de Mis controladores`; luego `chore: versión 0.0.26`, tag `v0.0.26`, `git push --follow-tags`. No hay migraciones.
