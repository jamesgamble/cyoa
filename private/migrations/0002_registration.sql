-- 0002_registration.sql
--
-- Version 0.11.0 — self-service registration.
--
-- Extends the users table so a new visitor can register with email,
-- username, display name, and a hashed password. Adds a settings
-- table for administrator-configurable knobs, and a rate-limit table
-- for server-side registrations-per-IP enforcement.
--
-- Uniqueness of email and username is case-insensitive: the caller
-- writes a lower-cased `email_normalized` / `username_normalized`
-- alongside the display forms, and both are covered by unique
-- indexes.

-- ---------------------------------------------------------------------------
-- users — add authentication columns.
--
-- All new columns are nullable so this migration is safe against
-- existing rows created before authentication existed. The registration
-- service enforces "present and valid" at write time.
--
-- status: pending_verification | active | suspended | deleted
-- password_algo: argon2id | bcrypt
-- ---------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN email               TEXT;
ALTER TABLE users ADD COLUMN email_normalized    TEXT;
ALTER TABLE users ADD COLUMN username_normalized TEXT;
ALTER TABLE users ADD COLUMN password_hash       TEXT;
ALTER TABLE users ADD COLUMN password_algo       TEXT
    CHECK (password_algo IS NULL OR password_algo IN ('argon2id','bcrypt'));
ALTER TABLE users ADD COLUMN status              TEXT NOT NULL DEFAULT 'active'
    CHECK (status IN ('pending_verification','active','suspended','deleted'));
ALTER TABLE users ADD COLUMN terms_accepted_at   TEXT;
ALTER TABLE users ADD COLUMN email_verified_at   TEXT;
ALTER TABLE users ADD COLUMN approved_at         TEXT;
ALTER TABLE users ADD COLUMN updated_at          TEXT
    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'));

CREATE UNIQUE INDEX idx_users_email_normalized
    ON users(email_normalized) WHERE email_normalized IS NOT NULL;
CREATE UNIQUE INDEX idx_users_username_normalized
    ON users(username_normalized) WHERE username_normalized IS NOT NULL;
CREATE INDEX idx_users_status ON users(status);

-- ---------------------------------------------------------------------------
-- settings — key/value pairs administered by an operator.
--
-- Registration reads five keys; more will be added by future prompts.
-- Each key is stored as text so callers can convert to their preferred
-- shape (integer, boolean, JSON) without a per-key column.
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

INSERT INTO settings (key, value) VALUES
    ('registration_enabled',           '1'),
    ('minimum_password_length',        '12'),
    ('registrations_per_ip_per_hour',  '5'),
    ('require_email_verification',     '1'),
    ('require_admin_approval',         '0');

-- ---------------------------------------------------------------------------
-- registration_attempts — per-IP rate-limit ledger.
--
-- Every registration POST records a row keyed by the client IP,
-- whether or not the account is created. The service refuses further
-- attempts from an IP once the configured hourly ceiling is reached,
-- so a scripted attacker cannot enumerate emails by trying millions
-- of registrations.
-- ---------------------------------------------------------------------------
CREATE TABLE registration_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ip          TEXT    NOT NULL,
    outcome     TEXT    NOT NULL
                CHECK (outcome IN ('accepted','rejected')),
    occurred_at TEXT    NOT NULL
                DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX idx_registration_attempts_ip_time
    ON registration_attempts(ip, occurred_at);
