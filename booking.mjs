import { DatabaseSync } from 'node:sqlite';
import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

export const services = [
  { id: 'smp', name: 'Konsultacja SMP', minutes: 30 },
  { id: 'brwi', name: 'Konsultacja makijażu permanentnego brwi', minutes: 30 },
];
export const whatsappNumber = '48696293848';
export const zone = 'Europe/Warsaw';
const DAY = 86_400_000;
const dateFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
});

export class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

export function localDate(now = new Date()) {
  const parts = Object.fromEntries(dateFormatter.formatToParts(now).map(p => [p.type, p.value]));
  return `${parts.year}-${parts.month}-${parts.day}`;
}

export function lastDate(now = new Date()) {
  return localDate(new Date(now.getTime() + 90 * DAY));
}

function parseDate(date) {
  if (typeof date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    throw new HttpError(400, 'Wybierz prawidłową datę.');
  }
  const value = new Date(`${date}T12:00:00Z`);
  if (!Number.isFinite(value.getTime()) || value.toISOString().slice(0, 10) !== date) {
    throw new HttpError(400, 'Nieprawidłowa data.');
  }
  return value;
}

export function instant(date, time) {
  const day = parseDate(date);
  const offsetText = new Intl.DateTimeFormat('en', {
    timeZone: zone, timeZoneName: 'longOffset',
  }).formatToParts(day).find(p => p.type === 'timeZoneName').value;
  const match = /^GMT([+-])(\d{2}):(\d{2})$/.exec(offsetText);
  if (!match) throw new Error('Unsupported Warsaw timezone offset');
  const offset = (Number(match[2]) * 60 + Number(match[3])) * (match[1] === '+' ? 1 : -1);
  return Date.parse(`${date}T${time}:00Z`) - offset * 60_000;
}

function serviceFor(id) {
  const service = services.find(item => item.id === id);
  if (!service) throw new HttpError(400, 'Wybierz rodzaj konsultacji.');
  return service;
}

function validateDate(date, now) {
  const day = parseDate(date);
  if (date < localDate(now) || date > lastDate(now)) {
    throw new HttpError(400, 'Rezerwacje są dostępne na najbliższe 90 dni.');
  }
  return day;
}

function schedule(date, service, now) {
  const weekday = validateDate(date, now).getUTCDay();
  if (weekday === 0) return [];
  const start = weekday === 6 ? 10 * 60 : 8 * 60;
  const slots = [];
  for (let minute = start; minute + service.minutes <= 20 * 60; minute += 30) {
    const time = `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
    const begin = instant(date, time);
    if (begin > now.getTime()) slots.push({ time, start: begin, end: begin + service.minutes * 60_000 });
  }
  return slots;
}

function normalize(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new HttpError(400, 'Nieprawidłowy formularz.');
  }
  const { date, time, service, requestId, consent, whatsappConsent } = input;
  const name = typeof input.name === 'string' ? input.name.trim() : '';
  const phone = typeof input.phone === 'string' ? input.phone.replace(/[\s()-]/g, '') : '';
  if (name.length < 2 || name.length > 80 || /[\u0000-\u001f\u007f]/.test(name)) {
    throw new HttpError(400, 'Podaj imię (2–80 znaków).');
  }
  if (!/^\+[1-9]\d{7,14}$/.test(phone)) {
    throw new HttpError(400, 'Podaj telefon z kodem kraju, np. +48 i 9 cyfr.');
  }
  if (consent !== true) throw new HttpError(400, 'Potwierdź zapoznanie się z informacją o danych.');
  if (typeof whatsappConsent !== 'boolean') throw new HttpError(400, 'Nieprawidłowa zgoda WhatsApp.');
  if (typeof requestId !== 'string' || !/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i.test(requestId)) {
    throw new HttpError(400, 'Nieprawidłowy identyfikator zgłoszenia. Odśwież stronę.');
  }
  if (typeof time !== 'string' || !/^\d{2}:\d{2}$/.test(time)) {
    throw new HttpError(400, 'Wybierz godzinę.');
  }
  serviceFor(service);
  parseDate(date);
  return { date, time, service, requestId, name, phone, whatsappConsent };
}

export function openStore(path) {
  if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true });
  const db = new DatabaseSync(path);
  db.exec(`
    PRAGMA journal_mode = WAL;
    PRAGMA busy_timeout = 5000;
    CREATE TABLE IF NOT EXISTS bookings (
      id TEXT PRIMARY KEY, request_id TEXT NOT NULL UNIQUE,
      date TEXT NOT NULL, time TEXT NOT NULL, service TEXT NOT NULL,
      starts INTEGER NOT NULL, ends INTEGER NOT NULL,
      name TEXT NOT NULL, phone TEXT NOT NULL,
      whatsapp_consent INTEGER NOT NULL, consent_at TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'confirmed',
      notification TEXT NOT NULL,
      message_id TEXT,
      created_at TEXT NOT NULL
    );
    CREATE UNIQUE INDEX IF NOT EXISTS confirmed_slot
      ON bookings(date, time) WHERE status = 'confirmed';
  `);
  // A crash during a provider request leaves its outcome unknown, not safe to retry.
  db.prepare("UPDATE bookings SET notification = 'unknown' WHERE notification = 'sending'").run();

  function availability(date, serviceId, now = new Date()) {
    const service = serviceFor(serviceId);
    const booked = db.prepare("SELECT starts, ends FROM bookings WHERE date = ? AND status = 'confirmed'").all(date);
    return schedule(date, service, now)
      .filter(slot => !booked.some(b => slot.start < b.ends && slot.end > b.starts))
      .map(slot => slot.time);
  }

  function reserve(input, automated, now = new Date()) {
    const data = normalize(input);
    db.exec('BEGIN IMMEDIATE');
    try {
      const previous = db.prepare('SELECT * FROM bookings WHERE request_id = ?').get(data.requestId);
      if (previous) {
        const same = ['date', 'time', 'service', 'name', 'phone'].every(key => previous[key] === data[key])
          && Boolean(previous.whatsapp_consent) === data.whatsappConsent;
        if (!same || previous.status !== 'confirmed') {
          throw new HttpError(409, 'To zgłoszenie zostało już użyte. Odśwież formularz.');
        }
        db.exec('COMMIT');
        return { booking: previous, created: false };
      }
      if (!availability(data.date, data.service, now).includes(data.time)) {
        throw new HttpError(409, 'Ten termin nie jest już dostępny. Wybierz inny.');
      }
      const start = instant(data.date, data.time);
      const id = randomUUID();
      const notification = data.whatsappConsent ? (automated ? 'pending' : 'unconfigured') : 'not_requested';
      db.prepare(`INSERT INTO bookings
        (id, request_id, date, time, service, starts, ends, name, phone,
          whatsapp_consent, consent_at, notification, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
        .run(id, data.requestId, data.date, data.time, data.service, start,
          start + serviceFor(data.service).minutes * 60_000, data.name, data.phone,
          Number(data.whatsappConsent), now.toISOString(), notification, now.toISOString());
      const booking = db.prepare('SELECT * FROM bookings WHERE id = ?').get(id);
      db.exec('COMMIT');
      return { booking, created: true };
    } catch (error) {
      db.exec('ROLLBACK');
      throw error;
    }
  }

  return {
    db, availability, reserve,
    get: id => db.prepare('SELECT * FROM bookings WHERE id = ?').get(id),
    list(date) {
      parseDate(date);
      return db.prepare('SELECT * FROM bookings WHERE date = ? ORDER BY time, created_at').all(date);
    },
    cancel(id) {
      const booking = this.get(id);
      if (!booking) throw new HttpError(404, 'Nie znaleziono rezerwacji.');
      db.prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?").run(id);
      return this.get(id);
    },
    close: () => db.close(),
  };
}

export function publicBooking(booking) {
  const service = serviceFor(booking.service);
  const text = `Dzień dobry, mam rezerwację MISSEYE: ${service.name}, ${booking.date}, godz. ${booking.time} (czas polski). Numer rezerwacji: ${booking.id}.`;
  return {
    id: booking.id, date: booking.date, time: booking.time,
    service: service.name, minutes: service.minutes, status: booking.status,
    notification: booking.notification,
    whatsappUrl: `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(text)}`,
  };
}

export function whatsappConfig(env) {
  const fields = ['WA_ACCESS_TOKEN', 'WA_PHONE_NUMBER_ID', 'WA_GRAPH_VERSION', 'WA_TEMPLATE_NAME'];
  const filled = fields.filter(key => Boolean(env[key]));
  if (filled.length && filled.length !== fields.length) {
    throw new Error('WhatsApp configuration incomplete: set all WA_* fields or leave them empty.');
  }
  if (!filled.length) return null;
  if (!/^v\d+\.\d+$/.test(env.WA_GRAPH_VERSION) || !/^\d+$/.test(env.WA_PHONE_NUMBER_ID)
    || !/^[a-z0-9_]+$/.test(env.WA_TEMPLATE_NAME)
    || !/^[a-z]{2}(?:_[A-Z]{2})?$/.test(env.WA_TEMPLATE_LANGUAGE || 'pl')) {
    throw new Error('Invalid WhatsApp version, sender ID, language or template name.');
  }
  return {
    token: env.WA_ACCESS_TOKEN, sender: env.WA_PHONE_NUMBER_ID,
    version: env.WA_GRAPH_VERSION, template: env.WA_TEMPLATE_NAME,
    language: env.WA_TEMPLATE_LANGUAGE || 'pl',
  };
}

export async function notify(store, id, config, fetcher = fetch) {
  const booking = store.get(id);
  if (!booking || booking.status !== 'confirmed' || !config || !booking.whatsapp_consent) return;
  const claimed = store.db.prepare("UPDATE bookings SET notification = 'sending' WHERE id = ? AND notification = 'pending'").run(id);
  if (!claimed.changes) return;
  try {
    const response = await fetcher(`https://graph.facebook.com/${config.version}/${config.sender}/messages`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${config.token}`, 'Content-Type': 'application/json' },
      signal: AbortSignal.timeout(10_000),
      body: JSON.stringify({
        messaging_product: 'whatsapp', to: booking.phone.slice(1), type: 'template',
        template: {
          name: config.template, language: { code: config.language },
          components: [{
            type: 'body', parameters: [
              serviceFor(booking.service).name, booking.date, booking.time, booking.id,
            ].map(text => ({ type: 'text', text })),
          }],
        },
      }),
    });
    if (!response.ok) {
      console.error(`WhatsApp provider rejected notification (HTTP ${response.status}).`);
      store.db.prepare("UPDATE bookings SET notification = 'failed' WHERE id = ?").run(id);
      return;
    }
    const result = await response.json();
    const messageId = result.messages?.[0]?.id;
    if (typeof messageId !== 'string' || !messageId) throw new Error('Missing WhatsApp message ID');
    // "accepted" means Meta accepted the request; it is not proof of delivery.
    store.db.prepare("UPDATE bookings SET notification = 'accepted', message_id = ? WHERE id = ?").run(messageId, id);
  } catch {
    console.error('WhatsApp notification outcome unknown; manual verification required.');
    store.db.prepare("UPDATE bookings SET notification = 'unknown' WHERE id = ?").run(id);
  }
}
