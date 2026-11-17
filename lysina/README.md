# MissEye — lysina.info

Independent MissEye booking site forked from the Okona Beauty project. It includes
MissEye services, portfolio, booking calendar, and manager panel only. No
PitSTOP content, bookings, or database configuration was copied.

## Deployment

- Point the `lysina.info` document root at this project's `www` directory.
- Create a **separate, empty database** for this installation. The existing
  Okona Beauty database must not be reused.
- Run `php setup.php --origin=https://lysina.info` from this project directory.
  Complete the generated private `config.php` with the new database password
  and integration settings. Keep `config.php` outside the public `www` folder.
- After verifying the new database is empty, run
  `php scripts/migrate-mysql.php --empty --dry-run`, then initialize it with
  `php scripts/migrate-mysql.php --empty --apply`. The setup command only
  checks the database; it does not initialize or copy booking data.
- Configure DNS and TLS for `lysina.info` at the domain and hosting providers.

The site uses `LYSINA_CONFIG` for an optional private configuration path; if
unset, it reads `config.php` from this project directory.

Run the focused fork checks with `php tests/run.php`.
