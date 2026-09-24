# Hosting, deployment, and maintenance

Branching Paths runs on plain Apache or Nginx with PHP 8.2+ and SQLite. No Node.js is needed in production.

## Requirements

- PHP 8.2 or newer (CLI and web SAPI)
- Extensions: `pdo`, `pdo_sqlite`, `json`, `mbstring`, `openssl`
- SQLite 3.27+ (for `VACUUM INTO` backups)
- Cron

## Recommended layout (storage outside the web root)

```text
/var/www/branching-paths/
  public/        <- document root (built frontend + api/index.php)
  app/ scripts/ private/migrations/ VERSION
/var/lib/branching-paths/
  data/          <- SQLite database (DATABASE_PATH)
  locks/         <- write lock (WRITE_LOCK_PATH)
```

Set `DATABASE_PATH` and `WRITE_LOCK_PATH` to absolute paths (see `docs/deploy/*.example`). `private/logs`, `private/backups` and `private/keys` stay under the project, which is outside `public/`.

## Safe fallback layout (shared hosting)

If the host forces the whole project under the document root, keep the tree as-is and rely on the bundled `.htaccess` files: `private/`, `app/`, `scripts/`, `tests/` and `docs/` each deny all web access, and `public/.htaccess` refuses dotfiles, `.sqlite`, `.lock`, `.log`, `.sql` and `.key` requests. Verify by requesting `/private/data/` — it must return 403. On Nginx there is no `.htaccess`; use the recommended layout.

## Writable directories

The web server user needs write access to:

- the database directory (`private/data/` or `DATABASE_PATH`'s directory) — SQLite creates `-wal`/`-shm` next to the file
- the lock directory (`private/locks/` or `WRITE_LOCK_PATH`'s directory)
- `private/logs/`
- `private/backups/`
- `private/keys/` (first run only, to create `app.key`)

Everything else should be read-only to the web server.

## Server configuration

- Apache: `public/.htaccess` (rewrites) + `docs/deploy/apache.conf.example`
- Nginx: `docs/deploy/nginx.conf.example`
- Security headers: both examples send Content-Security-Policy (same-origin only), Permissions-Policy, X-Frame-Options, Referrer-Policy and nosniff. Apache needs `mod_headers`. HSTS is provided commented out: enable it only after HTTPS works for the whole domain, because browsers remember it for a year.
- Cron: `docs/deploy/crontab.example` (email queue, digests, nightly backup, retention, weekly integrity check)

## First deployment

1. Upload the project; build the frontend (`cd frontend && npm ci && npm run build`) on a build machine and copy `frontend/dist/*` into `public/`.
2. Set the environment variables described in `docs/ENVIRONMENT.md` (template: `.env.example`). `APP_URL` must be the public address — every emailed link is built from it.
3. `php scripts/initialize.php`
4. `php scripts/migrate.php`
5. `php scripts/bootstrap-admin.php` to create the first administrator.
6. `php scripts/system-check.php` — must report `failures: 0`.
7. Install the cron entries.
8. Configure SMTP in `/master/settings`.

## Upgrade

1. Turn on **Read-only mode** in `/master/settings` (reading and administrator sign-in keep working).
2. `php scripts/backup.php pre-upgrade`
3. Replace code and `public/` assets with the new release (keep `private/` and your database).
4. `php scripts/migrate.php`
5. `php scripts/integrity-check.php` and `php scripts/system-check.php`
6. Turn read-only mode off.

## Rollback

1. Turn on read-only mode.
2. Redeploy the previous release's code and `public/` assets.
3. `php scripts/restore.php list`, then `php scripts/restore.php <pre-upgrade backup> --yes`. The current database is saved as a `pre-restore` backup first.
4. `php scripts/integrity-check.php`, then turn read-only mode off.

Migrations only move forward, so rolling back code always pairs with restoring the backup taken before the upgrade.

## Backups

- `scripts/backup.php` uses SQLite's `VACUUM INTO` while holding the write lock, so the live file is never copied directly. Each backup is integrity-checked and gets a `.sha256` file.
- `scripts/restore.php` verifies checksum and integrity, saves the current database, checkpoints the WAL, removes stale `-wal`/`-shm` files and swaps the file in under the lock.
- `scripts/prune-backups.php [keep] [days]` keeps the newest `keep` backups plus one per day for `days` days.
- Copy `private/backups/` off the server regularly, **and keep a separate copy of `private/keys/`**. `app.key` decrypts the stored SMTP password and `session.key` signs legacy administrator sessions; a database restored without them loses the SMTP password (re-enter it in `/master/settings`).

## Restoring onto a new server

1. Deploy the same release, set the environment (`docs/ENVIRONMENT.md`), run `php scripts/initialize.php`.
2. Copy `private/keys/` from the old server, and the backup file plus its `.sha256` into `private/backups/`.
3. `php scripts/restore.php <backup> --yes`, then `php scripts/migrate.php` and `php scripts/integrity-check.php`.
4. `php scripts/system-check.php` must report `failures: 0`.

Accounts, passwords, content, and roles come back exactly as backed up. Each account keeps one session at a time, so signing in anywhere ends that account's older session.

## Release verification

`php tests/e2e/release_e2e.php` performs a clean install in a temporary directory (npm ci, build, stage, initialize, migrate, first administrator), then drives every core workflow over HTTP against PHP's built-in server with a local SMTP sink: registration and verification, sign-in, creation, publishing, branch submission, change requests, approval, reading, reporting, invitations, ownership transfer, exports, backup, restore into a second location, and read-only mode. Nothing leaves `127.0.0.1`. Add `--skip-frontend` to reuse an existing `frontend/dist`.

## Maintenance controls (`/master/settings` → Maintenance)

- Read-only mode — blocks every change except administrator sign-in and administrator maintenance; public reading keeps working.
- Custom maintenance notice — returned by `GET /api/status` and with every change refused by read-only mode (no site-wide banner yet).
- Disable registrations, disable new adventures, pause all contributions.
