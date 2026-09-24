# Environment

Branching Paths reads four environment variables. Everything else — SMTP, registration rules, limits, maintenance — is configured by an administrator in `/master/settings` and stored in the database.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `APP_ENV` | yes | `development` | Set to `production` on a live site. |
| `APP_URL` | yes | `http://localhost:8000` | Public base address, no trailing slash (e.g. `https://stories.example.org`). Every emailed link (verification, password reset, invitations, digests) is built from it. |
| `DATABASE_PATH` | recommended | `private/data/branching-paths.sqlite` | SQLite file. Use an absolute path outside the web root. The directory must be writable (SQLite adds `-wal`/`-shm` files beside it). |
| `WRITE_LOCK_PATH` | recommended | `private/locks/write.lock` | Lock file used to serialise writes. Absolute path, writable directory, same machine as the database. |

Relative paths are resolved against the project root. Variables are read from the process environment (`getenv`), `$_ENV`, or `$_SERVER`, so Apache `SetEnv`, Nginx `fastcgi_param`, PHP-FPM pool `env[...]`, and shell exports all work. There is no `.env` loader: `.env.example` is a template to copy into whichever of those your host uses. CLI scripts and cron jobs must see the same values as the web server (see `docs/deploy/crontab.example`).

## Files that are not environment variables

| Path | Created by | Notes |
|---|---|---|
| `private/keys/app.key` | first run | Encrypts the stored SMTP password. Back it up separately from the database. |
| `private/keys/session.key` | first run | Signs legacy administrator sessions. Back it up with `app.key`. |
| `private/logs/php-error.log` | runtime | Errors are logged here; `display_errors` is always off. |
| `private/backups/` | `scripts/backup.php` | Backups and `.sha256` checksums. |

## Not used

Earlier templates listed `SMTP_*`, `SESSION_NAME`, and `SESSION_SECURE`. They are ignored: SMTP is configured in the master console, and the session cookie is `Secure` automatically whenever the request arrives over HTTPS (directly or via `X-Forwarded-Proto: https`).

## Runtime requirements

PHP 8.2+ with `pdo`, `pdo_sqlite`, `json`, `mbstring`, `openssl`; SQLite 3.27+. Node.js is needed only on the machine that builds the frontend, never on the server. No external database, cache, or hosted backend is used.
