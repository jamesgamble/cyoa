-- 0016_canonical_url_default.sql
--
-- Version 1.0.0 — release fix.
-- 0004 seeded canonical_url with the development address
-- http://localhost:8000, so every email link on a fresh production
-- install pointed at localhost. Removing the untouched default lets the
-- application fall back to APP_URL. A value an operator changed is kept.

DELETE FROM settings WHERE key = 'canonical_url' AND value = 'http://localhost:8000';
