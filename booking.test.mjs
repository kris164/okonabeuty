import test from 'node:test';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
  openStore, instant, localDate, lastDate, publicBooking, whatsappConfig, notify,
} from './booking.mjs';
import { createApp } from './server.mjs';

const now = new Date('2026-10-29T06:00:00Z');
const input = (overrides = {}) => ({
  requestId: randomUUID(), date: '2026-10-30', time: '10:00', service: 'smp',
  name: 'Test', phone: '+48123456789', consent: true, whatsappConsent: false,
  ...overrides,
});
const wa = { token: 'test-token', sender: '123', version: 'v99.0', template: 'reservation', language: 'pl' };

test('Warsaw clock, daylight saving changes and 90-day boundary', () => {
  assert.equal(instant('2026-10-23', '08:00'), Date.parse('2026-10-23T06:00:00Z'));
  assert.equal(instant('2026-10-30', '08:00'), Date.parse('2026-10-30T07:00:00Z'));
  assert.equal(instant('2027-03-29', '08:00'), Date.parse('2027-03-29T06:00:00Z'));
  assert.equal(localDate(new Date('2026-10-29T23:30:00Z')), '2026-10-30');
  assert.equal(lastDate(now), '2027-01-27');
});

test('working hours, Sundays, past dates and invalid dates', () => {
  const store = openStore(':memory:');
  try {
    const weekday = store.availability('2026-10-30', 'smp', now);
    assert.equal(weekday.length, 24);
    assert.equal(weekday[0], '08:00');
    assert.equal(weekday.at(-1), '19:30');
    const saturday = store.availability('2026-10-31', 'brwi', now);
    assert.equal(saturday.length, 20);
    assert.equal(saturday[0], '10:00');
    assert.deepEqual(store.availability('2026-11-01', 'smp', now), []);
    assert.throws(() => store.availability('2026-10-28', 'smp', now), { status: 400 });
    assert.throws(() => store.availability('2027-01-28', 'smp', now), { status: 400 });
    assert.throws(() => store.availability('2026-02-30', 'smp', now), { status: 400 });
    assert.throws(() => store.availability('2026-10-30', 'missing', now), { status: 400 });
    assert(!store.availability('2026-10-29', 'smp', new Date('2026-10-29T07:00:00Z')).includes('08:00'));
  } finally { store.close(); }
});

test('reservation blocks all services; retry is idempotent; cancellation releases slot', () => {
  const store = openStore(':memory:');
  try {
    const request = input();
    const saved = store.reserve(request, false, now);
    assert.equal(saved.created, true);
    assert.equal(saved.booking.notification, 'not_requested');
    assert(!store.availability(request.date, 'brwi', now).includes(request.time));
    assert.throws(() => store.reserve(input({ service: 'brwi' }), false, now), { status: 409 });
    const retry = store.reserve(request, false, now);
    assert.equal(retry.created, false);
    assert.equal(retry.booking.id, saved.booking.id);
    assert.throws(() => store.reserve({ ...request, time: '11:00' }, false, now), { status: 409 });
    store.cancel(saved.booking.id);
    assert(store.availability(request.date, 'brwi', now).includes(request.time));
    assert.throws(() => store.reserve(request, false, now), { status: 409 });
    assert.equal(store.reserve(input({ service: 'brwi' }), false, now).created, true);
    assert.throws(() => store.cancel(randomUUID()), { status: 404 });
  } finally { store.close(); }
});

test('rejects invalid input, off-grid times, closing time and Sunday', () => {
  const store = openStore(':memory:');
  try {
    for (const change of [
      { name: '' }, { phone: '123' }, { consent: false },
      { whatsappConsent: 'yes' }, { requestId: 'x' }, { date: 'nonsense' },
      { time: '25:30' }, { time: '10:15' }, { time: '20:00' }, { time: '07:30' },
      { date: '2026-11-01' }, { date: '2026-10-31', time: '08:00' },
    ]) {
      assert.throws(() => store.reserve(input(change), false, now), error => error.status === 400 || error.status === 409);
    }
    assert.equal(store.db.prepare('SELECT count(*) AS n FROM bookings').get().n, 0);
  } finally { store.close(); }
});

test('SQLite persists bookings and interrupted messages after restart', () => {
  const directory = mkdtempSync(join(tmpdir(), 'misseye-test-'));
  const path = join(directory, 'bookings.sqlite');
  let store;
  try {
    store = openStore(path);
    const { booking } = store.reserve(input({ whatsappConsent: true }), true, now);
    store.db.prepare("UPDATE bookings SET notification = 'sending' WHERE id = ?").run(booking.id);
    store.close();
    store = openStore(path);
    assert.equal(store.get(booking.id).notification, 'unknown');
    assert(!store.availability(booking.date, 'smp', now).includes(booking.time));
  } finally {
    store?.close();
    rmSync(directory, { recursive: true, force: true });
  }
});

test('public response excludes contact details and uses owner WhatsApp', () => {
  const store = openStore(':memory:');
  try {
    const { booking } = store.reserve(input(), false, now);
    const output = publicBooking(booking);
    assert.equal(output.name, undefined);
    assert.equal(output.phone, undefined);
    assert(output.whatsappUrl.startsWith('https://wa.me/48696293848?text='));
    assert(decodeURIComponent(output.whatsappUrl).includes(booking.id));
  } finally { store.close(); }
});

test('independent database connections share blocked slots', () => {
  const directory = mkdtempSync(join(tmpdir(), 'misseye-lock-test-'));
  const path = join(directory, 'bookings.sqlite');
  const first = openStore(path);
  const second = openStore(path);
  try {
    first.reserve(input(), false, now);
    assert.throws(() => second.reserve(input({ service: 'brwi' }), false, now), { status: 409 });
    assert.equal(second.list('2026-10-30').length, 1);
  } finally {
    first.close();
    second.close();
    rmSync(directory, { recursive: true, force: true });
  }
});

test('WhatsApp configuration is explicit and requires all credentials', () => {
  assert.equal(whatsappConfig({}), null);
  assert.throws(() => whatsappConfig({ WA_ACCESS_TOKEN: 'partial' }), /incomplete/);
  assert.throws(() => whatsappConfig({
    WA_ACCESS_TOKEN: 'test', WA_PHONE_NUMBER_ID: '../bad',
    WA_GRAPH_VERSION: 'v99.0', WA_TEMPLATE_NAME: 'reservation',
  }), /Invalid/);
});

test('WhatsApp sends approved template only with opt-in and does not send twice', async () => {
  const store = openStore(':memory:');
  try {
    const { booking } = store.reserve(input({ whatsappConsent: true }), true, now);
    let calls = 0;
    const fetcher = async (url, options) => {
      calls++;
      assert.equal(url, 'https://graph.facebook.com/v99.0/123/messages');
      const payload = JSON.parse(options.body);
      assert.equal(payload.to, '48123456789');
      assert.equal(payload.type, 'template');
      assert.equal(payload.template.components[0].parameters.length, 4);
      return Response.json({ messages: [{ id: 'wamid.test' }] });
    };
    await notify(store, booking.id, wa, fetcher);
    await notify(store, booking.id, wa, fetcher);
    assert.equal(calls, 1);
    assert.equal(store.get(booking.id).notification, 'accepted');
    const other = store.reserve(input({ time: '11:00' }), true, now).booking;
    await notify(store, other.id, wa, fetcher);
    assert.equal(calls, 1);
    const unconfigured = store.reserve(input({ time: '12:00', whatsappConsent: true }), false, now).booking;
    assert.equal(unconfigured.notification, 'unconfigured');
  } finally { store.close(); }
});

test('WhatsApp failures do not undo reservation or claim successful delivery', async () => {
  const store = openStore(':memory:');
  try {
    const rejected = store.reserve(input({ whatsappConsent: true }), true, now).booking;
    await notify(store, rejected.id, wa, async () => Response.json({ error: {} }, { status: 400 }));
    assert.equal(store.get(rejected.id).notification, 'failed');
    const uncertain = store.reserve(input({ time: '11:00', whatsappConsent: true }), true, now).booking;
    await notify(store, uncertain.id, wa, async () => { throw new Error('timeout'); });
    assert.equal(store.get(uncertain.id).notification, 'unknown');
    assert.equal(store.get(uncertain.id).status, 'confirmed');
    assert(!store.availability(uncertain.date, 'brwi', now).includes('11:00'));
  } finally { store.close(); }
});

test('HTTP integration: atomic double booking, authentication, cancellation, private files', async () => {
  const app = createApp({ dbPath: ':memory:', adminToken: 'a'.repeat(48) });
  await new Promise(resolve => app.server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${app.server.address().port}`;
  const headers = { 'Content-Type': 'application/json' };
  const auth = { Authorization: `Bearer ${'a'.repeat(48)}` };
  try {
    const config = await (await fetch(`${base}/api/config`)).json();
    const day = new Date(`${config.today}T12:00:00Z`);
    day.setUTCDate(day.getUTCDate() + 1);
    if (day.getUTCDay() === 0) day.setUTCDate(day.getUTCDate() + 1);
    const date = day.toISOString().slice(0, 10);
    const first = input({ date });
    const second = input({ date, service: 'brwi' });
    const results = await Promise.all([first, second].map(data => fetch(`${base}/api/bookings`, {
      method: 'POST', headers, body: JSON.stringify(data),
    })));
    assert.deepEqual(results.map(r => r.status).sort(), [201, 409]);
    const winning = results.findIndex(r => r.status === 201);
    const saved = await results[winning].json();
    const publicResponse = await fetch(`${base}/api/bookings/${saved.booking.id}`);
    const publicData = (await publicResponse.json()).booking;
    assert.equal(publicResponse.status, 200);
    assert.equal(publicData.phone, undefined);
    assert.equal(publicData.name, undefined);
    const retry = await fetch(`${base}/api/bookings`, {
      method: 'POST', headers, body: JSON.stringify([first, second][winning]),
    });
    assert.equal(retry.status, 200);
    assert.equal((await retry.json()).booking.id, saved.booking.id);
    assert.equal((await fetch(`${base}/api/admin/bookings?date=${date}`)).status, 401);
    const admin = await fetch(`${base}/api/admin/bookings?date=${date}`, { headers: auth });
    assert.equal(admin.status, 200);
    assert.equal((await admin.json()).bookings.length, 1);
    const cancelled = await fetch(`${base}/api/admin/bookings/${saved.booking.id}/cancel`, {
      method: 'POST', headers: auth,
    });
    assert.equal(cancelled.status, 200);
    assert((await (await fetch(`${base}/api/availability?date=${date}&service=smp`)).json()).slots.includes('10:00'));
    assert.equal((await fetch(`${base}/.env`)).status, 404);
    assert.equal((await fetch(`${base}/data/bookings.sqlite`)).status, 404);
    assert.equal((await fetch(`${base}/server.mjs`)).status, 404);
    assert.equal((await fetch(`${base}/api/bookings`, {
      method: 'POST', headers: { ...headers, Origin: 'https://other.invalid' }, body: JSON.stringify(input({ date })),
    })).status, 403);
    assert.equal((await fetch(`${base}/api/bookings`, {
      method: 'POST', headers, body: '{',
    })).status, 400);
    for (const path of ['/', '/booking.js', '/booking.css', '/admin', '/admin.js']) {
      assert.equal((await fetch(`${base}${path}`)).status, 200);
    }
  } finally { await app.close(); }
});
