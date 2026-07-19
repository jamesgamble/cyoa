-- 0004_authentication_and_sessions.sql
--
-- Version 0.13.0 — verification, login, password reset, and
-- database-backed sessions.
--
-- All bearer tokens (verification links, password reset links, and
-- session cookies) are stored ONLY as SHA-256 hashes. The raw token
-- is generated with random_bytes(32), handed to the user (email or
-- cookie), and never persisted. A database dump reveals no way to
-- impersonate anyone.
--
-- Every token/session row carries `expires_at` (bounded lifetime)
-- plus a `used_at` / `revoked_at` timestamp so single-use tokens and
-- explicit revocation are enforced with plain WHERE clauses.

-- users — record the moment the caller last rotated their password
-- and the moment they last authenticated. Both are nullable because
-- rows that predate v0.13.0 have no such history.
ALTER TABLE users ADD COLUMN password_changed_at TEXT;
ALTER TABLE users ADD COLUMN last_login_at       TEXT;

-- ---------------------------------------------------------------------------
-- email_verification_tokens — one row per outstanding verify link.
-- ---------------------------------------------------------------------------
CREATE TABLE email_verification_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  TEXT    NOT NULL UNIQUE,
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
                DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_verify_tokens_user ON email_verification_tokens(user_id);

-- ---------------------------------------------------------------------------
-- password_reset_tokens — one row per outstanding reset link.
-- ---------------------------------------------------------------------------
CREATE TABLE password_reset_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  TEXT    NOT NULL UNIQUE,
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
                DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_reset_tokens_user ON password_reset_tokens(user_id);

-- ---------------------------------------------------------------------------
-- sessions — active login sessions.
--
-- The session cookie transmitted to the browser is:
--     base32( id )  '.'  base64url( 32 random bytes )
-- The server stores only the SHA-256 of the random half. Revocation
-- writes a `revoked_at` timestamp; the auth code refuses any session
-- with a non-null revoked_at, an expired expires_at, or a mismatched
-- token hash.
-- ---------------------------------------------------------------------------
CREATE TABLE sessions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash     TEXT    NOT NULL UNIQUE,
    created_at     TEXT    NOT NULL
                   DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    last_active_at TEXT    NOT NULL
                   DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    expires_at     TEXT    NOT NULL,
    revoked_at     TEXT
);
CREATE INDEX idx_sessions_user   ON sessions(user_id);
CREATE INDEX idx_sessions_active ON sessions(user_id, revoked_at, expires_at);

-- ---------------------------------------------------------------------------
-- Additional email templates for password recovery + change.
-- ---------------------------------------------------------------------------
INSERT INTO email_templates (template_key, subject, text_body) VALUES
    ('password_reset',
     'Reset your Branching Paths password',
     'Hello {display_name},' || char(10) || char(10) ||
     'A password reset was requested for this account. Open the link' || char(10) ||
     'below within one hour to choose a new password:' || char(10) ||
     '{reset_url}' || char(10) || char(10) ||
     'If you did not request this you can ignore the message; your' || char(10) ||
     'existing password remains valid.'),
    ('password_changed',
     'Your Branching Paths password was changed',
     'Hello {display_name},' || char(10) || char(10) ||
     'Your Branching Paths password was changed. If this was not you,' || char(10) ||
     'reset the password immediately and contact an administrator.');

-- ---------------------------------------------------------------------------
-- Canonical HTTPS URL — used to build verification and reset links.
-- Administrators set this to their real domain (e.g. https://example.org).
-- ---------------------------------------------------------------------------
INSERT INTO settings (key, value) VALUES ('canonical_url', 'http://localhost:8000');
