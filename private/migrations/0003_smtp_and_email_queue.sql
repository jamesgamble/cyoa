-- 0003_smtp_and_email_queue.sql
--
-- Version 0.12.0 — administrator SMTP configuration and queued email
-- delivery.
--
-- Adds four artefacts:
--   1. user_roles — separate table so a stray write to `users` can
--      never grant admin. Roles are 'admin' (site operators) or
--      'moderator' (reserved for a later prompt).
--   2. smtp_settings — a single-row table (id = 1) holding the SMTP
--      credentials the operator configures. The password field
--      stores the ciphertext of the raw password encrypted with the
--      application key kept outside SQLite; the plaintext never lives
--      on disk in this table.
--   3. email_templates — server-rendered templates the queue uses.
--      Seeded with the templates version 0.12.0 sends: verification,
--      welcome, admin approval, and an operator test message.
--   4. email_queue — durable outbox for every message the worker
--      will try to deliver. `status` is constrained to five values;
--      `attempts` and `next_attempt_at` drive the retry backoff.
--
-- Only site operators should touch this data — grants are handled
-- separately in the future prompt that introduces PostgREST-style
-- roles. Under SQLite there is no role system at the storage layer.

-- ---------------------------------------------------------------------------
-- user_roles — a user may hold zero or more roles.
-- ---------------------------------------------------------------------------
CREATE TABLE user_roles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role       TEXT    NOT NULL
               CHECK (role IN ('admin','moderator')),
    granted_at TEXT    NOT NULL
               DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (user_id, role)
);
CREATE INDEX idx_user_roles_role ON user_roles(role);

-- ---------------------------------------------------------------------------
-- smtp_settings — one row, id fixed to 1.
--
-- password_ciphertext is the raw password, encrypted at rest with the
-- application key from `private/keys/app.key`. The API never reveals
-- the plaintext back to the browser; the response substitutes an
-- opaque placeholder when a password is on file.
-- ---------------------------------------------------------------------------
CREATE TABLE smtp_settings (
    id                    INTEGER PRIMARY KEY CHECK (id = 1),
    host                  TEXT    NOT NULL DEFAULT '',
    port                  INTEGER NOT NULL DEFAULT 587,
    encryption            TEXT    NOT NULL DEFAULT 'starttls'
                          CHECK (encryption IN ('none','starttls','tls')),
    username              TEXT    NOT NULL DEFAULT '',
    password_ciphertext   TEXT    NOT NULL DEFAULT '',
    from_email            TEXT    NOT NULL DEFAULT '',
    from_name             TEXT    NOT NULL DEFAULT '',
    reply_to              TEXT    NOT NULL DEFAULT '',
    enabled               INTEGER NOT NULL DEFAULT 0
                          CHECK (enabled IN (0,1)),
    retry_limit           INTEGER NOT NULL DEFAULT 5
                          CHECK (retry_limit BETWEEN 0 AND 20),
    batch_size            INTEGER NOT NULL DEFAULT 25
                          CHECK (batch_size BETWEEN 1 AND 500),
    updated_at            TEXT    NOT NULL
                          DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
INSERT INTO smtp_settings (id) VALUES (1);

-- ---------------------------------------------------------------------------
-- email_templates — subject + text/html body per template key.
--
-- Templates use `{name}` placeholder syntax that the queue substitutes
-- at send time from the message's data JSON. Placeholders are escaped
-- for HTML when rendered into the HTML body.
-- ---------------------------------------------------------------------------
CREATE TABLE email_templates (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    template_key TEXT    NOT NULL UNIQUE,
    subject      TEXT    NOT NULL,
    text_body    TEXT    NOT NULL,
    html_body    TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

INSERT INTO email_templates (template_key, subject, text_body) VALUES
    ('verify_email',
     'Verify your Branching Paths account',
     'Hello {display_name},' || char(10) || char(10) ||
     'Confirm your Branching Paths account by opening this link:' || char(10) ||
     '{verify_url}' || char(10) || char(10) ||
     'If you did not create an account you can ignore this message.'),
    ('welcome',
     'Welcome to Branching Paths',
     'Hello {display_name},' || char(10) || char(10) ||
     'Your Branching Paths account is now active. Happy reading.'),
    ('admin_approved',
     'Your Branching Paths account is approved',
     'Hello {display_name},' || char(10) || char(10) ||
     'An administrator approved your account. You can sign in now.'),
    ('operator_test',
     'Branching Paths test message',
     'This is a test message sent from the Branching Paths email settings page.' || char(10) ||
     'If you received it, outbound SMTP is configured correctly.');

-- ---------------------------------------------------------------------------
-- email_queue — durable outbox.
--
-- status:
--   pending   — waiting for the next worker run
--   sending   — a worker has claimed the row
--   sent      — delivered to the SMTP server
--   failed    — retry limit exhausted or a permanent error
--   cancelled — an operator cancelled the message before it left
--
-- `data_json` is a compact JSON object of placeholder values.
-- `last_error` is a short, redacted description — never the raw SMTP
-- reply, since replies may echo the recipient address or credentials.
-- ---------------------------------------------------------------------------
CREATE TABLE email_queue (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    template_key    TEXT    NOT NULL,
    to_email        TEXT    NOT NULL,
    to_name         TEXT    NOT NULL DEFAULT '',
    data_json       TEXT    NOT NULL DEFAULT '{}',
    status          TEXT    NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','sending','sent','failed','cancelled')),
    attempts        INTEGER NOT NULL DEFAULT 0,
    last_error      TEXT,
    next_attempt_at TEXT    NOT NULL
                    DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    claimed_at      TEXT,
    sent_at         TEXT,
    created_at      TEXT    NOT NULL
                    DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at      TEXT    NOT NULL
                    DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_email_queue_status ON email_queue(status);
CREATE INDEX idx_email_queue_ready
    ON email_queue(status, next_attempt_at);
CREATE INDEX idx_email_queue_created_at ON email_queue(created_at);
