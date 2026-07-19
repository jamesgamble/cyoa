# Hosting

Branching Paths is designed to run on plain Apache or Nginx with PHP 8.2+.

## Web root

- Point the web server document root at `public/`.
- The built frontend assets from `frontend/dist/` are copied into `public/` on deploy (details land in `0.27.0`).
- `public/api/` holds PHP API entry points.

## Private runtime data

- `private/` must sit **outside** the web root or be explicitly denied by server config.
- Contains the SQLite database, write lock file, logs, and backups.

## PHP

- PHP 8.2 or newer
- Extensions: `pdo_sqlite`, `mbstring`, `openssl`

## Email

- Outbound email uses SMTP2GO, configured via environment variables (see `.env.example`).
