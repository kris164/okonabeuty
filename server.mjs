import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
import { timingSafeEqual, createHash } from 'node:crypto';
import {
  openStore, HttpError, services, localDate, lastDate, zone,
  whatsappNumber, publicBooking, whatsappConfig, notify,
} from './booking.mjs';

const root = dirname(fileURLToPath(import.meta.url));
const files = new Map([
  ['/', ['index.html', 'text/html; charset=utf-8']],
  ['/index.html', ['index.html', 'text/html; charset=utf-8']],
  ['/booking.js', ['booking.js', 'text/javascript; charset=utf-8']],
  ['/booking.css', ['booking.css', 'text/css; charset=utf-8']],
  ['/admin', ['admin.html', 'text/html; charset=utf-8']],
  ['/admin.js', ['admin.js', 'text/javascript; charset=utf-8']],
]);
const digest = value => createHash('sha256').update(value).digest();

export function createApp({
  dbPath = resolve(root, 'data', 'bookings.sqlite'),
  adminToken, origin = 'http://127.0.0.1:3000',
  whatsapp = null, fetcher = fetch,
} = {}) {
  if (typeof adminToken !== 'string' || adminToken.length < 32) {
    throw new Error('ADMIN_TOKEN must contain at least 32 characters.');
  }
  const canonicalOrigin = new URL(origin).origin;
  const store = openStore(dbPath);
  const limits = new Map();
  const jobs = new Set();
  function enqueue(id) {
    const job = notify(store, id, whatsapp, fetcher);
    jobs.add(job);
    job.catch(() => console.error('Failed to persist WhatsApp notification status.'))
      .finally(() => jobs.delete(job));
  }
  if (whatsapp) {
    for (const booking of store.db.prepare("SELECT id FROM bookings WHERE status = 'confirmed' AND notification = 'pending'").all()) {
      enqueue(booking.id);
    }
  } else {
    store.db.prepare("UPDATE bookings SET notification = 'unconfigured' WHERE notification = 'pending'").run();
  }
  const server = createServer(async (req, res) => {
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    const json = (status, data) => {
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(data));
    };
    try {
      const url = new URL(req.url, canonicalOrigin);
      const path = url.pathname;
      if (req.method === 'POST' && req.headers.origin && req.headers.origin !== canonicalOrigin) {
        throw new HttpError(403, 'Niedozwolone źródło żądania.');
      }
      if (path.startsWith('/api/')) {
        const key = req.socket.remoteAddress;
        const now = Date.now();
        if (limits.size > 2000) {
          for (const [ip, value] of limits) if (value.reset < now) limits.delete(ip);
          if (limits.size > 2000 && !limits.has(key)) throw new HttpError(429, 'Zbyt wiele żądań. Spróbuj później.');
        }
        let entry = limits.get(key);
        if (!entry || entry.reset < now) {
          entry = { count: 0, reset: now + 60_000 };
          limits.set(key, entry);
        }
        if (++entry.count > 120) throw new HttpError(429, 'Zbyt wiele żądań. Spróbuj za minutę.');
      }
      if (path.startsWith('/api/admin/')) {
        const authorization = req.headers.authorization || '';
        if (!authorization.startsWith('Bearer ')
          || !timingSafeEqual(digest(authorization.slice(7)), digest(adminToken))) {
          throw new HttpError(401, 'Nieprawidłowy klucz panelu.');
        }
      }
      if (req.method === 'GET' && path === '/api/config') {
        json(200, {
          services, today: localDate(), maxDate: lastDate(), timezone: zone,
          whatsappNumber, automatedWhatsApp: Boolean(whatsapp),
        });
      } else if (req.method === 'GET' && path === '/api/availability') {
        json(200, { slots: store.availability(url.searchParams.get('date'), url.searchParams.get('service')) });
      } else if (req.method === 'GET' && /^\/api\/bookings\/[a-f0-9-]{36}$/.test(path)) {
        const booking = store.get(path.split('/')[3]);
        if (!booking) throw new HttpError(404, 'Nie znaleziono rezerwacji.');
        json(200, { booking: publicBooking(booking) });
      } else if (req.method === 'POST' && path === '/api/bookings') {
        if (!req.headers['content-type']?.startsWith('application/json')) {
          throw new HttpError(415, 'Wymagany format JSON.');
        }
        let size = 0;
        const chunks = [];
        for await (const chunk of req) {
          size += chunk.length;
          if (size > 4096) throw new HttpError(413, 'Formularz jest zbyt duży.');
          chunks.push(chunk);
        }
        let input;
        try { input = JSON.parse(Buffer.concat(chunks).toString('utf8')); }
        catch { throw new HttpError(400, 'Nieprawidłowy JSON.'); }
        const { booking, created } = store.reserve(input, Boolean(whatsapp));
        if (booking.notification === 'pending') enqueue(booking.id);
        json(created ? 201 : 200, { booking: publicBooking(store.get(booking.id)) });
      } else if (req.method === 'GET' && path === '/api/admin/bookings') {
        json(200, { bookings: store.list(url.searchParams.get('date')) });
      } else if (req.method === 'POST' && /^\/api\/admin\/bookings\/[a-f0-9-]{36}\/cancel$/.test(path)) {
        json(200, { booking: store.cancel(path.split('/')[4]) });
      } else if ((req.method === 'GET' || req.method === 'HEAD') && files.has(path)) {
        const [file, type] = files.get(path);
        const body = await readFile(resolve(root, file));
        res.writeHead(200, { 'Content-Type': type });
        res.end(req.method === 'HEAD' ? undefined : body);
      } else {
        throw new HttpError(404, 'Nie znaleziono strony.');
      }
    } catch (error) {
      if (error instanceof HttpError) json(error.status, { error: error.message });
      else {
        console.error('Booking request failed:', error.code || error.name);
        json(500, { error: 'Błąd serwera. Nie ponawiaj rezerwacji z nowymi danymi; skontaktuj się z salonem.' });
      }
    }
  });
  server.requestTimeout = 15_000;
  server.headersTimeout = 10_000;
  server.on('close', () => { limits.clear(); });
  return {
    server, store,
    async close() {
      if (server.listening) await new Promise((resolveClose, reject) => server.close(error => error ? reject(error) : resolveClose()));
      await Promise.allSettled([...jobs]);
      store.close();
    },
  };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const host = process.env.HOST || '127.0.0.1';
  const port = Number(process.env.PORT || 3000);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Invalid PORT');
  const app = createApp({
    adminToken: process.env.ADMIN_TOKEN,
    dbPath: process.env.DB_PATH ? resolve(process.env.DB_PATH) : undefined,
    origin: process.env.PUBLIC_ORIGIN || `http://127.0.0.1:${port}`,
    whatsapp: whatsappConfig(process.env),
  });
  app.server.listen(port, host, () => {
    console.log(`MISSEYE booking server: http://${host}:${port}`);
    if (!process.env.WA_ACCESS_TOKEN) console.warn('Automatic WhatsApp confirmations are OFF: configure Meta Cloud API.');
  });
  app.server.on('error', error => { console.error('Server startup failed:', error.code); process.exitCode = 1; });
  for (const signal of ['SIGINT', 'SIGTERM']) {
    process.once(signal, () => app.close().catch(error => {
      console.error('Server shutdown failed:', error.code || error.name);
      process.exitCode = 1;
    }));
  }
}
