// Puntuaciones sin conexión: si el envío falla por falta de internet, la puntuación se guarda en el navegador
// (localStorage) y se reenvía sola al volver la conexión. El indicador de la barra muestra una nube con check cuando
// todo está guardado en el servidor y un aviso + nube cuando hay resultados pendientes.
import { classify, flushQueue } from './sync-queue';

const KEY = 'kumitecontrol.pendingScores';
const indicator = document.getElementById('sync-indicator');
const panel = document.getElementById('sync-panel');

const readQueue = () => {
    try {
        const list = JSON.parse(localStorage.getItem(KEY) || '[]');
        return Array.isArray(list) ? list : [];
    } catch (e) {
        return [];
    }
};

const writeQueue = (list) => {
    try {
        localStorage.setItem(KEY, JSON.stringify(list));
    } catch (e) { /* sin almacenamiento: no se puede guardar en local */ }
    render();
};

const enqueue = (item) => {
    // Un combate solo conserva su última puntuación pendiente.
    writeQueue([...readQueue().filter((i) => i.url !== item.url), item]);
};

// Con createdAt solo se quita esa versión: un reenvío posterior del mismo combate no se pierde si el anterior termina después.
const dequeue = (url, createdAt) => writeQueue(readQueue().filter((i) => !(i.url === url && (createdAt === undefined || i.createdAt === createdAt))));

const csrf = async () => {
    // La app puede colgar de un prefijo (p. ej. /kumite): la URL viene de la ruta de Laravel, no se fija aquí.
    const res = await fetch(indicator?.dataset.csrfUrl || '/panel/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('auth');
    return (await res.json()).token;
};

// 'ok' (guardado), 'network' (sin conexión), 'auth' (sesión caducada), 'retry' (fallo del servidor: se reintenta) o 'rejected' (el servidor lo rechaza).
const send = async (item) => {
    let response;
    try {
        const token = await csrf();
        const body = new URLSearchParams(item.fields);
        body.set('_token', token);
        response = await fetch(item.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
            body,
        });
    } catch (e) {
        return { state: e.message === 'auth' ? 'auth' : 'network' };
    }

    const state = classify(response.status);
    if (state === 'ok') return { state, json: await response.json().catch(() => ({})) };
    if (state === 'auth') return { state };
    if (state === 'retry') return { state, message: `Error del servidor (${response.status}); se reintentará.` };

    const json = await response.json().catch(() => ({}));
    const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;

    return { state: 'rejected', message: json.message || firstError || `El servidor no ha aceptado la puntuación (${response.status}).` };
};

let flushing = false;
let sessionExpired = false;
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

const statusFor = (form) => form.querySelector('[data-score-status]');

const showFormStatus = (form, state, text) => {
    const el = statusFor(form);
    if (!el) return;
    el.dataset.state = state;
    el.className = `small ${state === 'pending' ? 'text-warning-emphasis' : state === 'error' ? 'text-danger' : 'text-success'}`;
    el.textContent = '';
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('width', '18');
    icon.setAttribute('height', '18');
    icon.setAttribute('aria-hidden', 'true');
    icon.classList.add('me-1');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', state === 'pending' ? '#warn' : state === 'error' ? '#warn' : '#cloud-check');
    icon.appendChild(use);
    el.appendChild(icon);
    if (state === 'pending') {
        const cloud = icon.cloneNode(true);
        cloud.querySelector('use').setAttribute('href', '#cloud-pending');
        el.appendChild(cloud);
    }
    el.appendChild(document.createTextNode(` ${text}`));
};

function render() {
    const queue = readQueue();
    const failed = queue.filter((i) => i.error).length;
    const pending = queue.length - failed;
    const needsAttention = failed > 0 || sessionExpired;
    const active = queue.length > 0 || sessionExpired;

    if (indicator) {
        indicator.dataset.syncState = needsAttention ? 'attention' : pending ? 'pending' : 'synced';
        indicator.querySelector('.sync-cloud-ok')?.classList.toggle('d-none', active);
        const pend = indicator.querySelector('.sync-pending');
        pend?.classList.toggle('d-none', !active);
        pend?.classList.toggle('d-flex', active);
        const count = indicator.querySelector('.sync-count');
        if (count) count.textContent = queue.length ? String(queue.length) : '';
        const parts = [];
        if (sessionExpired) parts.push('la sesión ha caducado: vuelve a iniciar sesión');
        else if (pending) parts.push(`${pending} ${pending === 1 ? 'resultado pendiente' : 'resultados pendientes'} de sincronizar${navigator.onLine ? '' : ' (sin conexión)'}`);
        if (failed) parts.push(`${failed} rechazado(s) por el servidor: revísalos o descártalos`);
        const label = parts.length ? parts.join('; ') : 'Todo guardado en el servidor';
        indicator.title = label;
        const hidden = indicator.querySelector('.sync-label');
        if (hidden) hidden.textContent = label;
    }

    // Estado junto a cada formulario de puntuación.
    document.querySelectorAll('[data-score-form]').forEach((form) => {
        const item = queue.find((i) => i.url === form.getAttribute('action'));
        if (item?.error) showFormStatus(form, 'error', item.error);
        else if (item) showFormStatus(form, 'pending', item.issue || 'Guardado en este dispositivo; pendiente de sincronizar');
    });

    renderPanel(queue);
}

function renderPanel(queue) {
    if (!panel) return;
    panel.classList.toggle('d-none', panel.dataset.open !== '1' || queue.length === 0);
    panel.replaceChildren();
    if (queue.length === 0) return;

    const body = document.createElement('div');
    body.className = 'card-body small';
    const title = document.createElement('strong');
    title.textContent = 'Pendientes de sincronizar';
    body.appendChild(title);
    if (sessionExpired) {
        const login = document.createElement('a');
        login.href = indicator?.dataset.loginUrl || '/acceso';
        login.className = 'd-block my-1';
        login.textContent = 'Iniciar sesión de nuevo';
        body.appendChild(login);
    }
    const list = document.createElement('ul');
    list.className = 'list-unstyled mb-2 mt-1';
    queue.forEach((item) => {
        const li = document.createElement('li');
        li.textContent = `${item.label}${item.error ? ` — rechazado: ${item.error}` : item.issue ? ` — ${item.issue}` : ''} `;
        const drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'btn btn-link btn-sm p-0 text-danger';
        drop.textContent = 'Descartar';
        drop.addEventListener('click', () => dequeue(item.url, item.createdAt));
        li.appendChild(drop);
        list.appendChild(li);
    });
    body.appendChild(list);
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn btn-sm btn-outline-primary';
    retry.textContent = 'Reintentar ahora';
    retry.addEventListener('click', () => {
        writeQueue(readQueue().map(({ error, issue, ...rest }) => rest));
        flush();
    });
    body.appendChild(retry);
    panel.appendChild(body);
}

// Envío de las puntuaciones por fetch: guardado inmediato o, sin conexión, en local.
document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('[data-score-form]');
    if (!form) return;
    event.preventDefault();

    const fields = [...new FormData(form).entries()].filter(([, v]) => typeof v === 'string');
    const url = form.getAttribute('action');
    const label = form.closest('.bout-card')?.querySelector('.small.text-muted')?.textContent.trim().replace(/\s+/g, ' ') || 'Puntuación';
    const item = { url, fields, label, createdAt: Date.now() };

    showFormStatus(form, 'pending', 'Guardando…');
    const result = await send(item);

    if (result.state === 'ok') {
        dequeue(url);
        showFormStatus(form, 'ok', 'Guardado en el servidor');
        setTimeout(() => location.reload(), 500);
    } else if (result.state === 'rejected') {
        form.dataset.submitting = '';
        showFormStatus(form, 'error', result.message);
    } else {
        form.dataset.submitting = '';
        enqueue(item);
        showFormStatus(form, 'pending', 'Sin conexión: guardado en este dispositivo; pendiente de sincronizar');
    }
});

indicator?.addEventListener('click', () => {
    if (!panel) return;
    panel.dataset.open = panel.dataset.open === '1' ? '0' : '1';
    render();
    if (readQueue().length) flush();
});

window.addEventListener('online', flush);
window.addEventListener('offline', render);
setInterval(() => {
    if (readQueue().length) flush();
}, 15000);

render();
flush();
