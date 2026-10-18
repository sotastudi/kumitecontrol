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
