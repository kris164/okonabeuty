import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const [major, minor] = process.versions.node.split('.').map(Number);
if (major < 24 || (major === 24 && minor < 11)) {
  throw new Error('Node.js LTS 24.11 or newer is required.');
}
if (!existsSync('.env')) {
  const template = readFileSync(new URL('.env.example', import.meta.url), 'utf8');
  writeFileSync('.env', template.replace('ADMIN_TOKEN=', `ADMIN_TOKEN=${randomBytes(32).toString('hex')}`), {
    flag: 'wx', mode: 0o600,
  });
  console.log('Created private .env. Owner panel key: ADMIN_TOKEN inside .env.');
}
console.log('Website: http://127.0.0.1:3000/#rezerwacja');
console.log('Owner panel: http://127.0.0.1:3000/admin');
