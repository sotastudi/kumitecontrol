# Cola de sincronización de puntuaciones: «faltan cambios por guardar» que nunca se guardan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el indicador de nube nunca quede en «pendiente» sin explicación: los elementos que el servidor no acepta, los fallos 5xx y la sesión caducada deben tratarse y mostrarse correctamente, y un elemento atascado no debe bloquear a los demás.

**Architecture:** Se extrae la lógica pura de la cola (clasificación de respuestas y bucle de reenvío) a `resources/js/sync-queue.js`, testable con `node --test` (sin dependencias nuevas). `offline-scores.js` pasa a usarla y muestra en el indicador/panel el motivo real por el que algo sigue pendiente.

**Tech Stack:** Laravel (PHP 8.5), Vite 8, JS ESM sin framework de test (se usa `node --test` nativo), Bootstrap 5.

**Spec:** No hay spec; la descripción del problema es la petición del usuario («en producción con conexión indica que faltan cambios por guardar, pero no acaba guardando»). Código afectado: `web/resources/js/offline-scores.js`; rutas `PUT panel/eventos/{event}/tatamis/{tatami}/combates/{bout}/puntuacion` y `PUT panel/eventos/{event}/categorias/{category}/combates/{bout}/puntuacion`.

## Diagnóstico (causas encontradas en `offline-scores.js`)

1. **Rechazados eternos (`flush`, líneas ~71-80):** si el servidor responde 4xx (p. ej. 422 «Solo se puede puntuar el combate actual…», 403, 404), el elemento se queda en la cola con `error` para siempre. `render()` cuenta `queue.length` como «pendientes», así que la nube muestra «N pendientes» con conexión y sin posibilidad de que se guarden solos. «Reintentar ahora» solo borra el error y vuelve a recibir el mismo rechazo.
2. **Un 5xx/429 bloquea toda la cola:** `send` devuelve `network` para `>=500`/`429` y `flush` hace `break`; un solo elemento que da 500 impide sincronizar los posteriores indefinidamente (la cola se ordena por `createdAt`).
3. **Sesión caducada silenciosa:** `auth` también hace `break` y no se informa de nada; la etiqueta solo dice «(sin conexión)» si `!navigator.onLine`, así que con conexión el usuario ve «pendiente» sin motivo y sin saber que debe volver a iniciar sesión.
4. **Carrera en `dequeue(url)`:** se borra por URL; si el usuario reenvía el mismo combate mientras el reenvío anterior está en vuelo, al terminar este se borra la puntuación nueva (pérdida de datos).
5. **Reintento inútil cada 15 s:** los elementos con `error` se vuelven a enviar en cada ciclo (`flush` no los salta).

No se puede reproducir producción desde aquí; la Tarea 2 añade el motivo visible por elemento para confirmar cuál de las causas 1-3 es la que ocurre.

## Global Constraints

- PHP 8.5; commits por tema; hay WIP del usuario sin confirmar (`slides/` sin seguir): **no añadirlo** (`git add` solo de los ficheros de la tarea).
- Textos de UI en español, igual que el resto de `offline-scores.js`.
- Comentarios en español y con la densidad del fichero actual (un comentario por bloque, no por línea).
- Tras cada tarea: subir versión (`chore: versión X.Y.Z`, siguiente a 0.0.20), tag `vX.Y.Z` y push.
- Recompilar assets de Vite: ver memoria `node-npm-path` (node/npm no están en el PATH de Claude).

## Review Focus

- Elemento en cola rechazado (422) con conexión: la nube no debe decir «pendiente de sincronizar»; debe decir que hay un resultado rechazado y ofrecer Descartar (Tarea 2).
- Sesión caducada con conexión: el indicador debe pedir iniciar sesión de nuevo, con enlace a `/acceso` (Tarea 2).
- Dos elementos en cola, el primero da 500: el segundo debe sincronizarse igualmente (Tarea 1).
- Reenvío del mismo combate durante un flush en vuelo: la puntuación nueva no se pierde (Tarea 2).
- `localStorage` con JSON corrupto o sin espacio: no debe romper la página (ya cubierto por `try/catch`; no regresionar).

---

### Task 1: Lógica pura de la cola, con tests

**Files:**
- Create: `web/resources/js/sync-queue.js`
- Create: `web/resources/js/sync-queue.test.js`
- Modify: `web/package.json` (script `test:js`)

**Interfaces:**
- Produces:
  - `classify(status: number): 'ok' | 'auth' | 'retry' | 'rejected'`
  - `flushQueue(items: Array<{url: string, createdAt: number, error?: string}>, send: (item) => Promise<{state: 'ok'|'network'|'auth'|'retry'|'rejected', message?: string}>): Promise<{synced: item[], rejected: {item, message}[], retry: {item, message}[], stopped: null|'network'|'auth'}>`

- [ ] **Step 1: Escribir el test que falla**

`web/resources/js/sync-queue.test.js`:

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { classify, flushQueue } from './sync-queue.js';

test('classify separa éxito, sesión, reintentable y rechazo', () => {
    assert.equal(classify(200), 'ok');
    assert.equal(classify(401), 'auth');
    assert.equal(classify(419), 'auth');
    assert.equal(classify(429), 'retry');
    assert.equal(classify(500), 'retry');
    assert.equal(classify(503), 'retry');
    assert.equal(classify(422), 'rejected');
    assert.equal(classify(403), 'rejected');
    assert.equal(classify(404), 'rejected');
});

test('un 500 en un elemento no bloquea los siguientes', async () => {
    const items = [{ url: '/a', createdAt: 1 }, { url: '/b', createdAt: 2 }];
    const send = async (item) => (item.url === '/a' ? { state: 'retry', message: 'Error del servidor (500)' } : { state: 'ok' });
    const out = await flushQueue(items, send);
    assert.deepEqual(out.synced.map((i) => i.url), ['/b']);
    assert.equal(out.retry.length, 1);
    assert.equal(out.stopped, null);
});

test('sin conexión o sesión caducada se detiene el envío', async () => {
    const items = [{ url: '/a', createdAt: 1 }, { url: '/b', createdAt: 2 }];
    const calls = [];
    const send = async (item) => { calls.push(item.url); return { state: 'auth' }; };
    const out = await flushQueue(items, send);
    assert.deepEqual(calls, ['/a']);
    assert.equal(out.stopped, 'auth');
});

test('los elementos ya rechazados no se reenvían solos y se respeta el orden por antigüedad', async () => {
    const items = [{ url: '/new', createdAt: 5 }, { url: '/old', createdAt: 1 }, { url: '/bad', createdAt: 0, error: 'x' }];
    const calls = [];
    const send = async (item) => { calls.push(item.url); return { state: 'ok' }; };
    await flushQueue(items, send);
    assert.deepEqual(calls, ['/old', '/new']);
});
```

- [ ] **Step 2: Ejecutar y comprobar que falla**

Run: `cd web && node --test resources/js/sync-queue.test.js` (si `node` no está en PATH, ver memoria `node-npm-path`)
Expected: FAIL, `Cannot find module './sync-queue.js'`.

- [ ] **Step 3: Implementación mínima**

`web/resources/js/sync-queue.js`:

```js
// Lógica pura de la cola de puntuaciones pendientes (sin DOM ni red), para poder probarla con `node --test`.

// Traduce el código HTTP de la respuesta al estado que interesa a la cola.
export const classify = (status) => {
    if (status >= 200 && status < 300) return 'ok';
    if (status === 401 || status === 419) return 'auth';
    if (status >= 500 || status === 429) return 'retry';
    return 'rejected';
};

// Envía los elementos por antigüedad. Un fallo del servidor o un rechazo de un elemento no frena a los demás;
// solo la falta de conexión o la sesión caducada detienen el envío, porque afectan a todos por igual.
export const flushQueue = async (items, send) => {
    const out = { synced: [], rejected: [], retry: [], stopped: null };
    const sendable = items.filter((i) => !i.error).sort((a, b) => a.createdAt - b.createdAt);
    for (const item of sendable) {
        const result = await send(item);
        if (result.state === 'ok') out.synced.push(item);
        else if (result.state === 'rejected') out.rejected.push({ item, message: result.message });
        else if (result.state === 'retry') out.retry.push({ item, message: result.message });
        else {
            out.stopped = result.state;
            break;
        }
    }
    return out;
};
```

- [ ] **Step 4: Script npm y verificar que pasa**

En `web/package.json`, dentro de `"scripts"`, añadir `"test:js": "node --test resources/js/"` (con coma tras `"dev": "vite"`).

Run: `cd web && node --test resources/js/`
Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add web/resources/js/sync-queue.js web/resources/js/sync-queue.test.js web/package.json
git commit -m "feat: lógica pura de la cola de sincronización con tests"
```

---

### Task 2: Usar la lógica en `offline-scores.js` y mostrar el motivo real

**Files:**
- Modify: `web/resources/js/offline-scores.js` (import; `dequeue`; `send`; `flush`; `render`; `renderPanel`)
- Modify: `web/tests/Feature/BoutScorePanelTest.php` (test PHP de regresión del contrato JSON, ver Step 1)

**Interfaces:**
- Consumes: `classify`, `flushQueue` de la Tarea 1.
- Produces: elementos de cola con `{ url, fields, label, createdAt, error?, issue? }` donde `issue` es un texto de «por qué sigue pendiente» (reintentable/sesión) y `error` el rechazo definitivo del servidor.

- [ ] **Step 1: Test PHP que fija el contrato que consume la cola**

El JS depende de que un rechazo de negocio sea `422` con `message` (y no 5xx). Añadir a `web/tests/Feature/BoutScorePanelTest.php`, junto al test de la línea ~218, un test que puntúe un combate que no es el actual del tatami y afirme `assertStatus(422)` con `->assertJsonPath('ok', false)` y `assertJsonStructure(['message'])`. Usar el mismo montaje (factory/helpers) que `test_scores_can_be_sent_as_json_for_the_offline_queue_and_errors_come_back_as_json` (copiar su `arrange` y cambiar el combate a uno futuro del tatami).

Run: `cd web && php artisan test --filter=BoutScorePanelTest`
Expected: PASS (documenta el comportamiento actual; si falla, el rechazo no llega como 422 y esa es la causa 1: corregir el controlador antes de seguir).

- [ ] **Step 2: Cambiar `send` para usar `classify` y mensajes legibles**

Sustituir el bloque desde `if (response.ok) return …` hasta el final de `send` por:

```js
    const state = classify(response.status);
    if (state === 'ok') return { state, json: await response.json().catch(() => ({})) };
    if (state === 'auth') return { state };
    if (state === 'retry') return { state, message: `Error del servidor (${response.status}); se reintentará.` };

    const json = await response.json().catch(() => ({}));
    const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;

    return { state: 'rejected', message: json.message || firstError || `El servidor no ha aceptado la puntuación (${response.status}).` };
```

y añadir arriba `import { classify, flushQueue } from './sync-queue';`.

- [ ] **Step 3: `dequeue` por url + createdAt (evita perder un reenvío posterior)**

```js
const dequeue = (url, createdAt) => writeQueue(readQueue().filter((i) => !(i.url === url && (createdAt === undefined || i.createdAt === createdAt))));
```

Actualizar las llamadas: en `flush` → `dequeue(item.url, item.createdAt)`; en el listener de `submit` (`result.state === 'ok'`) → `dequeue(url)` (sin `createdAt`: aquí se borra cualquier pendiente anterior de ese combate porque acaba de guardarse el más reciente); en el botón «Descartar» → `dequeue(item.url, item.createdAt)`.

- [ ] **Step 4: Reescribir `flush` con `flushQueue`**

```js
let flushing = false;
const flush = async () => {
    if (flushing) return;
    flushing = true;
    let synced = 0;
    try {
        const out = await flushQueue(readQueue(), send);
        out.synced.forEach((item) => { dequeue(item.url, item.createdAt); synced++; });
        const notes = new Map([
            ...out.rejected.map(({ item, message }) => [item.createdAt, { error: message }]),
            ...out.retry.map(({ item, message }) => [item.createdAt, { issue: message }]),
        ]);
        sessionExpired = out.stopped === 'auth';
        writeQueue(readQueue().map((i) => (notes.has(i.createdAt) ? { ...i, ...notes.get(i.createdAt) } : i)));
    } finally {
        flushing = false;
    }
    // Tras sincronizar, recargar para ver el siguiente combate y el ganador ya registrados.
    if (synced > 0 && document.querySelector('[data-score-form]') && readQueue().length === 0) {
        setTimeout(() => location.reload(), 600);
    }
};
```

Declarar `let sessionExpired = false;` junto a `flushing`. En el botón «Reintentar ahora» limpiar también `issue`: `({ error, issue, ...rest }) => rest`.

- [ ] **Step 5: `render` distingue pendientes, rechazados y sesión caducada**

En `render()`, sustituir el cálculo de `pending`/`label` por:

```js
    const queue = readQueue();
    const failed = queue.filter((i) => i.error).length;
    const pending = queue.length - failed;
    const needsAttention = failed > 0 || sessionExpired;
    // …
        indicator.dataset.syncState = needsAttention ? 'attention' : pending ? 'pending' : 'synced';
        indicator.querySelector('.sync-cloud-ok')?.classList.toggle('d-none', queue.length > 0 || sessionExpired);
        const pend = indicator.querySelector('.sync-pending');
        pend?.classList.toggle('d-none', queue.length === 0 && !sessionExpired);
        pend?.classList.toggle('d-flex', queue.length > 0 || sessionExpired);
        const count = indicator.querySelector('.sync-count');
        if (count) count.textContent = queue.length ? String(queue.length) : '';
        const parts = [];
        if (sessionExpired) parts.push('la sesión ha caducado: vuelve a iniciar sesión');
        else if (pending) parts.push(`${pending} ${pending === 1 ? 'resultado pendiente' : 'resultados pendientes'} de sincronizar${navigator.onLine ? '' : ' (sin conexión)'}`);
        if (failed) parts.push(`${failed} rechazado(s) por el servidor: revísalos o descártalos`);
        const label = parts.length ? parts.join('; ') : 'Todo guardado en el servidor';
```

(El resto de `render` —título, `.sync-label`, estado junto al formulario— queda igual; en el estado junto al formulario, si `item.issue` existe usar `item.issue` como texto del `pending` en lugar del genérico.)

- [ ] **Step 6: `renderPanel` muestra motivo y enlace de acceso**

En `renderPanel`, cambiar el `li.textContent` a `` `${item.label}${item.error ? ` — rechazado: ${item.error}` : item.issue ? ` — ${item.issue}` : ''} ` `` y, si `sessionExpired`, añadir antes de la lista un `<a href="/acceso" class="d-block mb-1">Iniciar sesión de nuevo</a>` (creado con `document.createElement('a')`). El panel debe renderizarse también cuando `sessionExpired` aunque `queue.length === 0` no ocurre (la cola no está vacía si hay sesión caducada con pendientes), así que no hace falta tocar la condición de salida temprana.

- [ ] **Step 7: Verificar tests y compilar**

Run: `cd web && node --test resources/js/ && php artisan test --filter=BoutScorePanelTest && npm run build` (npm vía memoria `node-npm-path`)
Expected: tests PASS y build de Vite sin errores.

- [ ] **Step 8: Verificación manual en navegador (local)**

1. Con sesión iniciada, en DevTools → Application → Local Storage añadir `kumitecontrol.pendingScores` = `[{"url":"/panel/eventos/1/categorias/1/combates/999/puntuacion","fields":[],"label":"Prueba","createdAt":1}]`. Recargar. Expected: la nube pasa a estado «atención», el panel muestra «rechazado: …» con «Descartar» y deja de reenviarse cada 15 s (pestaña Network).
2. Cerrar sesión en otra pestaña con un pendiente válido: Expected: texto «la sesión ha caducado: vuelve a iniciar sesión» y enlace a `/acceso`.

- [ ] **Step 9: Commit, versión, tag y push**

```bash
git add web/resources/js/offline-scores.js web/tests/Feature/BoutScorePanelTest.php web/public/build
git commit -m "fix: la cola de puntuaciones muestra rechazos y sesión caducada y no se atasca"
# subir versión a 0.0.21 donde se registre (ver commit ac319b7 «chore: versión 0.0.20»), commit y:
git tag v0.0.21 && git push && git push --tags
```

---

## Self-Review

- **Cobertura:** causa 1 (rechazados) → T2 pasos 4-6; causa 2 (5xx bloquea) → T1 + T2 paso 4; causa 3 (sesión) → T2 pasos 5-6; causa 4 (carrera) → T2 paso 3; causa 5 (reintentos inútiles) → T1 `flushQueue` salta `error`.
- **Placeholders:** el Step 1 de la Tarea 2 remite al test existente de la línea ~218 para el montaje en vez de repetirlo; el ejecutor debe leerlo antes. El paso de versión remite al commit `ac319b7` para localizar dónde se registra la versión.
- **Consistencia de tipos:** `classify`, `flushQueue`, campos `error`/`issue`/`createdAt` coinciden entre tareas.
- **Riesgo:** la causa real en producción no está confirmada; si el paso manual 1 de T2 no reproduce el problema, pedir al usuario el valor de `localStorage['kumitecontrol.pendingScores']` y la respuesta de la petición fallida en Network.
