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
