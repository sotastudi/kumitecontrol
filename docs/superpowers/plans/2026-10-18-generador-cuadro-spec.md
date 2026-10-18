# Generador de cuadro según spec (ronda previa + cuadro de potencia de 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El cuadro de eliminatoria directa pasa de «todos combaten en la 1.ª ronda» a la estructura de `docs/spec/spec-generar-cuadro.md`: una **Ronda previa** con los combates justos para llegar a la mayor potencia de 2 ≤ n, y el resto de competidores **exentos** entrando directamente en la ronda siguiente.

**Architecture:** Para n competidores, `m` = mayor potencia de 2 ≤ n y `P = n − m` combates de ronda previa (2P luchadores, n − 2P exentos). Se sortea una lista de `m` huecos en orden de cuadro sembrado (los exentos son las mejores semillas; el hueco de cada ganador de previa es una de las peores semillas). Los exentos se guardan **directamente** en los huecos `slot_a_id/slot_b_id` de los combates de la ronda 2 (sin combates «Pase»); los combates de previa llevan `is_prelim = true` y apuntan con `next_bout_id/next_slot` a su combate de la ronda 2. Si n es potencia de 2 no hay previa y la ronda 1 es la primera real (como ahora). Los cuadros ya generados con el modelo antiguo (con pases) siguen funcionando.

**Tech Stack:** Laravel (PHP; ejecutar con `/usr/local/bin/php`, suite con `-d memory_limit=1G vendor/bin/phpunit`), Blade, PHPUnit.

**Spec:** `docs/spec/spec-generar-cuadro.md`

## Global Constraints

- Eliminatoria directa; 20 competidores ⇒ «Ronda Previa de 4 combates» (8 luchadores) + 12 exentos ⇒ Octavos (16) ⇒ Cuartos ⇒ Semifinales ⇒ Final. 4 cuadrantes de 5 competidores.
- Carga: los que juegan la previa hacen 5 combates hasta la final; los exentos, 4.
- Selección «por méritos» (criterios con nivel/peso): los **mejores/más veteranos** quedan exentos y los **de menor rango** disputan la previa entre sí. «Sorteo puro» (`DrawCriteria::Random`): los 2P primeros del sorteo luchan la previa, el resto exentos.
- Los combates de la Ronda previa se numeran y disputan **primero**; antes de la fase siguiente se recomienda **20–30 minutos de descanso** (aviso en el panel del cuadro).
- Bronce: sigue la regla del evento/categoría (`BronzeRule`): combate por el 3.er puesto o dos bronces. No cambia.
- Etiquetas de ronda en español: «Ronda previa», «Octavos de final», «Cuartos de final», «Semifinales», «Final».
- Compatibilidad: cuadros ya guardados (rondas completas con pases) no se migran; regenerar solo mientras no haya resultados (regla actual).

## Review Focus

- n = potencia de 2 (2, 4, 8, 16): sin ronda previa, `is_prelim` falso en todos los combates y primera ronda con todos los emparejamientos.
- n = 3, 5, 6, 7: ronda previa más pequeña que un cuadrante; n = 3 deja bronce de un solo participante (pase) y n = 3 con `BronzeMatch` no rompe.
- n = 17 (1 combate de previa, 15 exentos) y n = 31 (15 combates de previa, 1 exento): todos colocados exactamente una vez; ningún hueco vacío en ronda 2.
- Reiniciar resultados / ajustar (`swap`) un exento con un luchador de previa: los exentos no se borran al resetear y el intercambio funciona en ambos sentidos.
- Mismo club: dos exentos del mismo club que se cruzan en el primer combate real se separan si hay alternativa; si no, aviso en `draw_warnings`.

## File Structure

- Create `web/database/migrations/2026_10_30_100000_add_is_prelim_to_bouts_table.php` — columna `is_prelim` (boolean, false).
- Create `web/app/Tournament/BracketSeeding.php` — orden de semillas de un cuadro de tamaño potencia de 2 (hoy está dentro de `DrawOrderer::spread`).
- Modify `web/app/Tournament/DrawOrderer.php` — devuelve huecos (exentos / parejas de previa) en vez de parejas de 1.ª ronda.
- Modify `web/app/Tournament/BracketGenerator.php` — construye previa + ronda 2 con exentos; `shuffler` coherente.
- Modify `web/app/Tournament/BracketAdvancer.php` (`reset`), `BracketAdjuster.php`, `web/app/Models/Category.php` (`bracketIsStale`), `web/app/Models/Bout.php` (`is_prelim`).
- Modify `web/app/Tournament/RoundLabel.php`, `PaperBracket.php`, `web/resources/views/partials/bracket.blade.php`, `panel/brackets/show.blade.php`, vistas que llaman a `RoundLabel::for`.
- Tests: `web/tests/Feature/Tournament/{DrawOrdererTest,BracketGeneratorTest,BracketAdjusterTest,ResultRecorderTest}.php`, `BracketPanelTest.php`, `BracketPdfTest.php`, nuevo `web/tests/Unit/BracketSeedingTest.php`.

### Task 1: `is_prelim`, `BracketSeeding` y `RoundLabel`

**Files:** Create migración, `BracketSeeding.php`, `tests/Unit/BracketSeedingTest.php`; Modify `Bout.php` (`#[Fillable]` + cast boolean), `RoundLabel.php`, sus 5 llamadas (`panel/brackets/show.blade.php:118`, `public/categories/show.blade.php:36`, `partials/bracket.blade.php:29`, `partials/tatami-bout.blade.php:12`, `PaperBracket.php:147`).

**Interfaces:**
- Produces: `BracketSeeding::order(int $size): list<int>` — semillas (1..size) en orden de hueco del cuadro; `order(4) = [1,4,3,2]`, `order(16)` cruza 1v16, 8v9, 5v12, 4v13, 3v14, 6v11, 7v10, 2v15 (mismo algoritmo que `DrawOrderer::spread`).
- Produces: `RoundLabel::for(int $round, int $totalRounds, bool $prelim = false): string` — `$round === 1 && $prelim` ⇒ «Ronda previa»; el resto igual que hoy.
- Produces: `Bout::$is_prelim` (bool).

- [ ] **Step 1: Tests** — `BracketSeedingTest`: `order(1)=[1]`, `order(2)=[1,2]`, `order(4)=[1,4,3,2]`, `order(8)=[1,8,5,4,3,6,7,2]`. En `BracketGeneratorTest` (o test nuevo `RoundLabelTest`): `RoundLabel::for(1,5,true)==='Ronda previa'`, `for(2,5)==='Octavos de final'`, `for(1,3,true)==='Ronda previa'`, `for(1,4)==='Octavos de final'` (sin previa se mantiene).
- [ ] **Step 2: Run** `/usr/local/bin/php artisan test --filter='BracketSeedingTest|RoundLabel'` → FAIL.
- [ ] **Step 3: Implement** migración, `BracketSeeding` (extraer el bucle de `spread`), `RoundLabel` y pasar `$bout->is_prelim` en las 5 llamadas (en la cabecera de columna de `bracket.blade.php`, el de la primera combate de la columna).
- [ ] **Step 4: Run** mismo filtro + `php artisan migrate` en local → PASS.
- [ ] **Step 5: Commit** `feat: bandera is_prelim, orden de semillas y etiqueta «Ronda previa»`

### Task 2: `DrawOrderer` devuelve huecos de cuadro

**Files:** Modify `DrawOrderer.php`, `tests/Feature/Tournament/DrawOrdererTest.php`

**Interfaces:**
- Consumes: `BracketSeeding::order`.
- Produces: `DrawOrderer::order(Collection $registrations, DrawCriteria $criteria = DrawCriteria::LevelWeightClub): array{slots: list<list<int>>, prelim: bool, warnings: list<string>}`. Con `n` potencia de 2: `prelim=false` y `slots` = n/2 parejas `[a,b]` sembradas (como las `pairs` actuales). Si no: `prelim=true`, `slots` = `m` entradas en orden de hueco; entrada de 1 id = exento, de 2 ids = pareja de previa que alimenta ese hueco. Hueco de semilla `s ≤ m−P` ⇒ exento `L[s−1]` (L = lista ordenada mejor→peor); semilla `m−P+k+1` ⇒ pareja de previa k (parejas formadas por vecinos de `L[n−2P..]`, las más fuertes primero). Elimina el concepto de «pase» y de `lone`. Expone `DrawOrderer::layout(list<int> $sortedIds, array $registrationsById = []): array{slots: list<list<int>>, prelim: bool}` (colocación en huecos de una lista ya ordenada mejor→peor), que `order()` y el `shuffler` de `BracketGenerator` (Task 3) comparten.
- Misma separación de club: dentro de las parejas de previa (`separateClubs` actual) y, entre exentos, en los pares de huecos consecutivos `(2j,2j+1)` (permutar con el exento más cercano en semilla que deje ambos pares sin mismo club; si no hay, aviso «Primer combate entre participantes del mismo club (…)»).

- [ ] **Step 1: Failing tests** (reescribir los de pases): 
  - `n=20, LevelWeightClub, 20 niveles distintos`: `prelim`, 16 huecos, 4 de 2 ids (8 luchadores = los 8 de menor nivel), 12 de 1 id (los 12 mayores), cada inscripción exactamente una vez.
  - Cuadrantes (spec): huecos agrupados de 4 en 4 ⇒ cada grupo contiene exactamente 1 pareja de previa y 3 exentos.
  - `n=16` ⇒ `prelim=false`, 8 parejas. `n=3` ⇒ 2 huecos: 1 exento y 1 pareja. `n=6`, `n=17`, `n=31`: todos colocados una vez y `count(slots)` = potencia de 2.
  - `DrawCriteria::Random` n=20: sigue habiendo 8 luchadores/12 exentos.
  - Mismo club: dos exentos del mismo club no quedan juntos si hay alternativa; si todos son del mismo club, aviso.
  - Sin nivel ni peso (nulos) no rompe.
- [ ] **Step 2: Run** `--filter=DrawOrdererTest` → FAIL.
- [ ] **Step 3: Implement** `order()` según Interfaces; borrar `spread` (usa `BracketSeeding`).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat: el sorteo reparte exentos y ronda previa según la spec`

### Task 3: `BracketGenerator` construye la ronda previa y el reinicio respeta los exentos

**Files:** Modify `BracketGenerator.php`, `BracketAdvancer.php` (`reset`), `tests/Feature/Tournament/BracketGeneratorTest.php`, `ResultRecorderTest.php`

**Interfaces:**
- Consumes: `DrawOrderer::order(...)` (Task 2); `Bout::is_prelim`.
- Produces: `BracketGenerator::generate(Category $category, ?Closure $shuffler = null): void` — misma firma. Con `shuffler` el orden recibido es mérito (mejor→peor): se deriva `slots/prelim` igual que `DrawOrderer` (usa `DrawOrderer::layout` (Task 2)).
  Estructura: sin previa ⇒ ronda 1 = `slots` como parejas (como hoy). Con previa ⇒ ronda 1 = las parejas de previa (`is_prelim=true`, `required_participants=2`, `position` por orden de hueco), ronda 2 = `m/2` combates cuyos huecos `a/b` son el exento (id) o el ganador de una previa (`next_bout_id/next_slot` del combate de previa; hueco impar ⇒ `a`, par ⇒ `b`); rondas siguientes y bronce como hoy (`required_participants` del bronce = nº de combates de la ronda `rounds−1`; `loser_next_slot` por posición). Los combates de la ronda previa se numeran primero; los de ronda 2 con ambos exentos ya tienen número y están listos.
- `BracketAdvancer::reset(Category)`: vacía `slot_a_id/slot_b_id` solo en los huecos de combates `round > 1` que tienen un combate de origen (`next_bout_id = bout.id` y `next_slot`); los exentos se conservan.

- [ ] **Step 1: Failing tests**:
  - `test_twenty_participants_make_a_four_bout_prelim_and_twelve_exempt`: 20 ⇒ 5 rondas; ronda 1: 4 combates `is_prelim`; ronda 2: 8 combates; exactamente 12 huecos de ronda 2 rellenos; los 4 combates de previa apuntan a 4 combates distintos de ronda 2, uno por cuadrante (posiciones 1–2, 3–4, 5–6, 7–8); numeración: previa = combates 1–4.
  - Cargas: simulando ganadores, un luchador de previa que llega a la final juega 5 combates y un exento 4.
  - `n=16`: 4 rondas, ninguna `is_prelim`. `n=2`: una final. `n=3`: previa de 1 combate + final con exento; con `BronzeMatch` el bronce tiene `required_participants=1`. `n=5`: previa 1 + semis 2 + final (+ bronce 2 participantes).
  - `n=17` y `n=31`: todos colocados una vez; el cuadro se puede jugar hasta la final (reutilizar `test_every_bracket_size_can_be_played_to_the_final...` de `DrawOrdererTest` para n=2..33).
  - `reset` tras resolver combates conserva los exentos de ronda 2 y vacía los ganadores propagados (ResultRecorderTest).
  - Reescribir/eliminar los tests de «un pase por ronda» (`test_five_participants_have_one_bye...`, `test_never_more_than_one_participant_left_loose...`, `test_three_participants_bronze...`) con las nuevas expectativas.
- [ ] **Step 2: Run** `--filter='BracketGeneratorTest|ResultRecorderTest'` → FAIL.
- [ ] **Step 3: Implement** `build()` y `reset()` según Interfaces; `BracketAdvancer::settle` sigue válido para cuadros antiguos.
- [ ] **Step 4: Run** `--filter=Tournament` → PASS.
- [ ] **Step 5: Commit** `feat: generar ronda previa y exentos directos a la ronda siguiente`

### Task 4: Ajuste de cuadro y utilidades que asumían «todos en la ronda 1»

**Files:** Modify `BracketAdjuster.php`, `Category.php` (`bracketIsStale`), `panel/brackets/show.blade.php` (variable `$participants`), `tests/Feature/Tournament/BracketAdjusterTest.php`, `RegistrationInBracketTest.php`

**Interfaces:**
- Consumes: modelo de Task 3. Produces: `BracketAdjuster::swap(Category, int $registrationA, int $registrationB): void` — localiza a cada participante en los huecos de combates de las rondas 1 y 2 (no bronce) y los intercambia; tras ello `BracketAdvancer::reset`. Lanza `TournamentException('El participante no está en la primera ronda del cuadro.')` si no aparece. `bracketIsStale` y `$participants` toman la unión de huecos de las rondas 1 y 2 (no bronce).

- [ ] **Step 1: Failing tests**: swap exento↔luchador de previa (n=20) en ambos sentidos deja a cada uno en el hueco del otro; swap entre dos exentos; `bracketIsStale` falso tras generar con n=20 y verdadero al confirmar una inscripción nueva; la página del cuadro lista los 20 en el desplegable de ajuste.
- [ ] **Step 2: Run** `--filter='BracketAdjusterTest|RegistrationInBracketTest|BracketPanelTest'` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `fix: ajuste y detección de cuadro obsoleto con exentos en la ronda 2`

### Task 5: Vista del cuadro, PDF y aviso de descanso

**Files:** Modify `partials/bracket.blade.php`, `panel/brackets/show.blade.php`, `PaperBracket.php`, `tests/Feature/BracketPanelTest.php`, `BracketPdfTest.php`

**Interfaces:**
- Consumes: `next_bout_id` como relación de origen para colocar la ronda previa. Produces: el cálculo de filas (`$span` en Blade y en `PaperBracket::page`) usa como combates de origen los de `next_bout_id = combate.id` (no `2*position`); un combate de previa ocupa las filas de su combate destino y se dibuja a su izquierda; un combate de ronda 2 sin origen (ambos exentos) ocupa una fila propia. `PaperBracket::card()`: un hueco de ronda ≥ 2 ya relleno (exento) muestra nombre y club como en 1.ª ronda, con la etiqueta «Exento». Cabecera del PDF: «Ronda previa».
- Aviso en el panel (visible solo con previa): «Ronda previa: {P} combates ({2P} competidores) · {n−2P} exentos. Los de la previa harán un combate más. Deja 20–30 minutos de descanso antes de la ronda siguiente.»

- [ ] **Step 1: Failing tests**: panel con 20 confirmados y cuadro generado: `assertSee('Ronda previa')`, `assertSee('Octavos de final')`, `assertSee('4 combates (8 competidores) · 12 exentos')`, `assertSee('20–30 minutos')`; con 16 confirmados no aparece el aviso; PDF de 20 inscritos se genera (200) y contiene «Ronda previa» y «Exento»; con 5, 6 y 33 inscritos la página y el PDF responden 200.
- [ ] **Step 2: Run** `--filter='BracketPanelTest|BracketPdfTest'` → FAIL.
- [ ] **Step 3: Implement** los dos cálculos de geometría y el aviso; verificar visualmente en el navegador con un evento Test de 20 inscritos (sin capturas en el plan).
- [ ] **Step 4: Run** suite completa `-d memory_limit=1G vendor/bin/phpunit` → todo verde.
- [ ] **Step 5: Commit** `feat: vista, PDF y aviso de descanso para la ronda previa`

### Task 6: Ayuda, versión y despliegue

**Files:** Modify `web/app/Support/HelpContent.php` (texto de «cuadro»: previa, exentos, orden de combates y descanso), `web/config/app.php`.

- [ ] **Step 1:** Actualizar la ayuda y `grep -n -i "pase" app/Support/HelpContent.php` para quitar referencias al pase de primera ronda; test de ayuda existente (`HelpTest`) verde.
- [ ] **Step 2:** Suite completa verde; commit `docs: ayuda del cuadro con ronda previa`.
- [ ] **Step 3:** `chore: versión 0.0.23`, tag `v0.0.23`, `git push --follow-tags`.
