# Generador de evento Test con datos Manga Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El generador de datos de un evento «Test» crea 8 clubs con logo y 20 inscritos por categoría con nombre (del listado Manga Kyokushin) y avatar, y pone el cartel al evento.

**Architecture:** Los recursos de `demo/` (ignorado por git, no llega a producción) se copian a `web/database/demo/` junto con un `catalog.json` generado del `.md`. Un `DemoCatalog` los lee y `TestDataGenerator` los usa en lugar de las listas aleatorias; lo que no cubre el catálogo (más de 20 inscritos, más de 8 clubs) cae al generador aleatorio actual. Los ficheros se copian al disco `local` con las mismas rutas que usan las subidas reales.

**Tech Stack:** Laravel (PHP 8.5), PHPUnit, Storage disk `local`.

**Spec:** petición del usuario + `demo/listado_de_clubes_y_competidores_manga_kyokushin.md` (fuente de nombres y clubs) + `demo/assets/{club,avatar,cartel}`.

## Global Constraints

- Clubs por defecto: 8. Inscripciones por defecto por categoría: 20.
- Los inscritos siguen llevando `notes = 'DEMO'`; los clubs ficticios siguen con `@demo.kumitecontrol.invalid`.
- `demo/` está en `.gitignore` (`/demo/*`): nada de producción puede depender de esa carpeta.
- Logos/fotos/cartel se guardan en disco `local` en `club-logos`, `competitor-photos`, `posters` (rutas ya existentes, ver `User::setLogo`, `CompetitorController::update`, `EventController`).
- Mapa de avatares: Infantil mixta → `infantil`; Junior masculina → `juvenil-masculino`; Junior femenina → `juvenil-femenino`; Senior masculina/femenina → `senior-masculino`/`senior-femenino`. Las categorías por peso usan el de su grupo de edad y sexo.
- `juvenil-masculino` tiene 14 fotos y `juvenil-femenino` 18 (<20): se reutilizan cíclicamente.
- Tras la tarea: subir versión (0.0.22), tag `v0.0.22`, push (memoria del usuario); commits solo de mis cambios.

## Review Focus

- Pedir más de 20 inscritos (hasta `MAX_PARTICIPANTS`=200): continúan con nombres aleatorios y avatares cíclicos, sin romper ni repetir los 20 del catálogo.
- Generar dos veces en la misma categoría: no duplica los nombres del catálogo; continúa donde se quedó (lista y fotos).
- Pedir menos de 8 clubs: los inscritos de un club descartado se reasignan a los clubs elegidos, ninguno queda sin club.
- Borrar pruebas: no deja ficheros huérfanos (fotos de inscritos, logos de clubs ficticios) en el disco.
- Nombres de club de la tabla del `.md` distintos del listado oficial («Dojo Red Ribbon» vs «Dojo Red Ribbon Martial Arts»): deben resolver al mismo club, no crear un noveno.

## File Structure

- Create `web/database/demo/catalog.json`, `web/database/demo/club/01..08.jpg`, `web/database/demo/avatar/<grupo>/NN.jpg`, `web/database/demo/cartel.jpg` — recursos versionados.
- Create `web/app/Registrations/DemoCatalog.php` — lectura del catálogo y de los ficheros.
- Modify `web/app/Registrations/TestDataGenerator.php` — usa el catálogo; limpia ficheros.
- Modify `web/resources/views/panel/events/_test-data.blade.php` — valores por defecto 8 / 20.
- Create `web/tests/Unit/DemoCatalogTest.php`; modify `web/tests/Feature/TestDataTest.php`.

### Task 1: Recursos y DemoCatalog

**Files:**
- Create: `web/database/demo/**` (copia de `demo/assets/*`, sin `.DS_Store`), `web/database/demo/catalog.json`, `web/app/Registrations/DemoCatalog.php`
- Test: `web/tests/Unit/DemoCatalogTest.php`

**Interfaces:**
- Produces: `final class DemoCatalog` con
  - `clubs(): list<string>` — los 8 nombres oficiales en orden del listado
  - `clubLogoPath(string $club): ?string` — ruta absoluta de `club/NN.jpg` (NN = posición 1..8) o null
  - `canonicalClub(string $name): ?string` — resuelve alias de la tabla («Dojo Red Ribbon», «Escuela Nichirin», «Asociación Akatsuki», «Club Marítimo Marineford») por `Competitor::normalizeName` al nombre oficial
  - `competitors(string $categoryKey): list<array{first_name:string,last_name:string,club:string}>` — 20 por clave (`infantil`, `junior-masculina`, `junior-femenina`, `senior-masculina`, `senior-femenina`); `club` ya canónico
  - `avatarPath(string $group, int $index): string` — ruta absoluta, índice cíclico sobre las fotos del grupo (`infantil`, `juvenil-masculino`, `juvenil-femenino`, `senior-masculino`, `senior-femenino`)
  - `posterPath(): string`

- [ ] **Step 1: Copiar recursos** — `mkdir -p web/database/demo && cp -R demo/assets/{club,avatar} web/database/demo/ && cp demo/assets/cartel/01.jpg web/database/demo/cartel.jpg && find web/database/demo -name .DS_Store -delete`. Comprobar con `git status` que ya NO están ignorados.
- [ ] **Step 2: Generar `catalog.json`** desde las tablas del `.md` con un script puntual en el scratchpad (no se versiona): `{"clubs": [...8], "aliases": {"Dojo Red Ribbon": "Dojo Red Ribbon Martial Arts", ...}, "categories": {"infantil": [{"first_name","last_name","club"}...]}}`. Primer token del «Nombre y Apellidos» = `first_name`, resto = `last_name` (p. ej. «Monkey D. Luffy» → «Monkey» / «D. Luffy»; «Rock Lee» → «Rock» / «Lee»). Verificar: 5 categorías × 20 entradas.
- [ ] **Step 3: Write the failing test** `DemoCatalogTest`:
```php
public function test_catalog_has_eight_clubs_and_twenty_competitors_per_category(): void
{
    $c = new DemoCatalog;
    $this->assertCount(8, $c->clubs());
    foreach (['infantil','junior-masculina','junior-femenina','senior-masculina','senior-femenina'] as $k) {
        $this->assertCount(20, $c->competitors($k));
        foreach ($c->competitors($k) as $p) { $this->assertContains($p['club'], $c->clubs()); }
    }
}
public function test_aliases_resolve_and_logos_and_avatars_exist_with_cyclic_index(): void
{
    $c = new DemoCatalog;
    $this->assertSame('Dojo Red Ribbon Martial Arts', $c->canonicalClub('dojo red ribbon'));
    $this->assertNull($c->canonicalClub('Club Inventado'));
    foreach ($c->clubs() as $club) { $this->assertFileExists($c->clubLogoPath($club)); }
    $this->assertSame($c->avatarPath('juvenil-masculino', 0), $c->avatarPath('juvenil-masculino', 14));
    $this->assertFileExists($c->posterPath());
}
```
- [ ] **Step 4: Run** `cd web && php artisan test --filter=DemoCatalogTest` → FAIL (class not found).
- [ ] **Step 5: Implement `DemoCatalog`** (decodifica `catalog.json` una vez; avatares = `glob` ordenado de `database/demo/avatar/<grupo>/*.jpg`; `base_path('database/demo')`).
- [ ] **Step 6: Run** the same filter → PASS.
- [ ] **Step 7: Commit** `git add web/database/demo web/app/Registrations/DemoCatalog.php web/tests/Unit/DemoCatalogTest.php && git commit -m "feat: catálogo de datos demo Manga (clubs, nombres, avatares, cartel)"`

### Task 2: Clubs y cartel del evento Test

**Files:**
- Modify: `web/app/Registrations/TestDataGenerator.php` (`ensureClubs`, `clubNames`, `categories`, `pruneClubs`)
- Test: `web/tests/Feature/TestDataTest.php`

**Interfaces:**
- Consumes: `DemoCatalog::clubs()`, `clubLogoPath()`, `posterPath()`
- Produces: `TestDataGenerator::ensureClubs(Event $event, array $names): void` mantiene la firma pero, para nombres del catálogo, copia el logo a `club-logos/` (`Storage::disk('local')->putFileAs`) y fija `logo_path` si el usuario no tiene; `pruneClubs` borra el logo (`User::deleteLogo()`) de las cuentas ficticias que elimina. `clubNames(int $count)` usa `DemoCatalog::clubs()` antes de `'Club N'`; `CLUBS` (lista antigua) se elimina de `TestDataGenerator`. `categories()` fija `poster_path` (copia a `posters/`) si el evento no tiene cartel.

- [ ] **Step 1: Failing tests** en `TestDataTest`: (a) `registrations(..., clubs: 8)` crea 8 clubs aprobados con `hasLogo()` y nombres == `DemoCatalog::clubs()`; (b) `clubs: 10` añade «Club 9», «Club 10» sin logo; (c) tras generar categorías `$event->fresh()->hasPoster()` es true y un cartel previo no se sobrescribe; (d) `clean` → `Storage::disk('local')->allFiles('club-logos')` vacío (usar `Storage::fake('local')`).
- [ ] **Step 2: Run** `php artisan test --filter=TestDataTest` → FAIL.
- [ ] **Step 3: Implement** los cambios de la sección Interfaces.
- [ ] **Step 4: Run** → PASS; correr también `--filter=DemoRegistrationsTest` (el comando usa `ensureClubs`/`pruneClubs`; su lista `CLUBS` propia no cambia).
- [ ] **Step 5: Commit** `feat: clubs demo con logo y cartel en el evento Test`

### Task 3: Inscritos con nombre y avatar del catálogo

**Files:**
- Modify: `web/app/Registrations/TestDataGenerator.php` (`registrations`, `competitorData`, nuevo `categoryKey`), `clean`
- Test: `web/tests/Feature/TestDataTest.php`

**Interfaces:**
- Consumes: `DemoCatalog::competitors()`, `canonicalClub()`, `avatarPath()`
- Produces: `registrations(Category $category, int $participants, int $clubs, bool $confirmed = true): int` — misma firma. Nuevo `private function categoryKey(Category $c): string` (por `sex` y `min_age`: `min_age < 14` o sin sexo y edad ≤13 → `infantil`; 14–17 → `junior-*`; resto → `senior-*`; sexo null en no-infantil → senior masculina). Posición `i` (continuando tras los DEMO ya existentes en la categoría = `$offset`): si `$offset + $i < 20` usa el catálogo, si no el aleatorio actual; el club del catálogo se mantiene si está entre los `clubNames` elegidos, si no `clubNames[$i % count]`. Cada inscrito recibe foto: copia de `avatarPath(grupo, $offset + $i)` a `competitor-photos/` y `photo_path`. `clean()` borra los ficheros `photo_path` de los competidores que elimina.

- [ ] **Step 1: Failing tests**:
  - `test_default_run_creates_twenty_named_registrations_with_photo_per_category`: categoría «Senior femenina» (sex Female, min_age 18) con 20/8 → nombres == los 20 de `competitors('senior-femenina')` (como `first_name.' '.last_name`), 8 clubs distintos, todos `hasPhoto()`.
  - `test_second_run_continues_the_list_without_repeating_names`: 12 + 12 → 24 inscritos, los 20 del catálogo sin duplicados y 4 aleatorios.
  - `test_fewer_clubs_reassigns_competitors_of_dropped_clubs`: clubs=3 → 3 clubs distintos, ninguno vacío.
  - `test_clean_deletes_photo_files`: `allFiles('competitor-photos')` vacío tras limpiar.
  - `test_infantil_has_no_forced_sex_and_junior_masculina_reuses_photos_past_fourteen`: 20 en junior masculina → 20 `photo_path` con ≥ 14 distintos pero ≤ 14 ficheros origen (dos inscritos comparten avatar).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** según Interfaces; la edad/sexo/nivel/peso siguen saliendo de `competitorData` (el catálogo solo aporta nombre, club y foto).
- [ ] **Step 4: Run** `php artisan test --filter=TestDataTest` → PASS.
- [ ] **Step 5: Commit** `feat: inscritos de prueba con nombres y avatares del listado Manga`

### Task 4: Valores por defecto en el panel, versión y despliegue

**Files:**
- Modify: `web/resources/views/panel/events/_test-data.blade.php` (`participants` 12→20, `clubs` 4→8; texto de ayuda: «Nombres y avatares del listado Manga Kyokushin; hasta 20 por categoría y 8 clubs, el resto aleatorio»)
- Modify: fichero de versión según el último `chore: versión` (commit d10e118)
- Test: `web/tests/Feature/TestDataTest.php`

- [ ] **Step 1: Failing test** `test_panel_defaults_are_eight_clubs_and_twenty_participants`: `get(panel.events.show)` con categoría existente → `assertSee('value="20"', false)` y `assertSee('value="8"', false)` en el bloque de pruebas.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** la vista. **Step 4: Run** `php artisan test` completo → todo en verde (estaba en 305+ tests).
- [ ] **Step 5: Versión y push:** commit `chore: versión 0.0.22`, `git tag v0.0.22`, `git push && git push --tags`. Producción: tras el deploy, el panel genera con los recursos de `database/demo/` (ya versionados); no hace falta `storage:link` porque se sirven por controlador.
