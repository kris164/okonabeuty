import { DatabaseSync } from 'node:sqlite';
import { randomUUID } from 'node:crypto';
import { existsSync, openSync, writeFileSync, fsyncSync, closeSync, linkSync, unlinkSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';

const source = process.argv[2] ? resolve(process.argv[2]) : resolve('data', 'bookings.sqlite');
const target = process.argv[3] ? resolve(process.argv[3]) : resolve('data', 'bookings.json');
if (!existsSync(source)) throw new Error('Legacy SQLite source does not exist.');
if (existsSync(target)) throw new Error('JSON destination already exists. Migration will not overwrite it.');
mkdirSync(dirname(target), { recursive: true, mode: 0o700 });
const database = new DatabaseSync(source, { readOnly: true });
let bookings;
try {
  bookings = database.prepare(`SELECT id, request_id, date, time, service, starts, ends,
    name, phone, whatsapp_consent, consent_at, status, notification, message_id, created_at
    FROM bookings ORDER BY created_at, id`).all().map(row => ({
    ...row,
    whatsapp_consent: Boolean(row.whatsapp_consent),
    notification: row.notification === 'sending' ? 'unknown' : row.notification,
    notification_started_at: null,
  }));
} finally {
  database.close();
}
const temporary = `${target}.${randomUUID()}.tmp`;
try {
  const descriptor = openSync(temporary, 'wx', 0o600);
  try {
    writeFileSync(descriptor, `${JSON.stringify({ version: 1, bookings }, null, 2)}\n`);
    fsyncSync(descriptor);
  } finally {
    closeSync(descriptor);
  }
  // An exclusive hard link publishes a complete file without replacing existing data.
  linkSync(temporary, target);
} finally {
  if (existsSync(temporary)) unlinkSync(temporary);
}
console.log(`Migrated ${bookings.length} bookings to JSON. Original SQLite retained unchanged.`);
