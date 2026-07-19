-- 0005_account_dashboard.sql
--
-- Version 0.14.0 — account dashboard.
--
-- Adds profile / notification columns to users, a pending email
-- change table (verified via a hashed single-use token so the new
-- address is only committed after the user proves ownership), and a
-- bookmarks table so signed-in readers can import their local
-- reading history and bookmarks. Sessions gain a user-agent column
-- so the security page can render a recognisable list of active
-- devices.

-- users --------------------------------------------------------------------
ALTER TABLE users ADD COLUMN bio               TEXT;
ALTER TABLE users ADD COLUMN public_profile    INTEGER NOT NULL DEFAULT 1
    CHECK (public_profile IN (0, 1));
ALTER TABLE users ADD COLUMN notify_replies    INTEGER NOT NULL DEFAULT 1
    CHECK (notify_replies IN (0, 1));
ALTER TABLE users ADD COLUMN notify_moderation INTEGER NOT NULL DEFAULT 1
    CHECK (notify_moderation IN (0, 1));
ALTER TABLE users ADD COLUMN notify_updates    INTEGER NOT NULL DEFAULT 0
    CHECK (notify_updates IN (0, 1));

-- sessions: annotate active-session list on the security page --------------
ALTER TABLE sessions ADD COLUMN user_agent TEXT;

-- pending_email_changes ----------------------------------------------------
-- One row per outstanding email-change request. `token_hash` stores
-- SHA-256 of a 32-byte random token emitted to the new address. The
-- row is single-use (used_at) and expiring (24h).
CREATE TABLE pending_email_changes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    new_email      TEXT    NOT NULL,
    new_email_norm TEXT    NOT NULL,
    token_hash     TEXT    NOT NULL UNIQUE,
    expires_at     TEXT    NOT NULL,
    used_at        TEXT,
    created_at     TEXT    NOT NULL
                   DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_pending_email_user ON pending_email_changes(user_id);

-- bookmarks / reading history import target --------------------------------
-- `kind` = bookmark | history. Import from localStorage writes both
-- kinds under the caller's user_id after they confirm.
CREATE TABLE user_bookmarks (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    adventure_id  INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    scene_id      INTEGER          REFERENCES scenes(id) ON DELETE CASCADE,
    kind          TEXT    NOT NULL CHECK (kind IN ('bookmark','history')),
    created_at    TEXT    NOT NULL
                  DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (user_id, adventure_id, scene_id, kind)
);
CREATE INDEX idx_user_bookmarks_user ON user_bookmarks(user_id, kind);

-- Email template for the email-change confirmation flow.
INSERT INTO email_templates (template_key, subject, text_body) VALUES
    ('email_change_verify',
     'Confirm your new Branching Paths email',
     'Hello {display_name},' || char(10) || char(10) ||
     'A request was made to change the email on your account to this' || char(10) ||
     'address. Open the link below within 24 hours to confirm:' || char(10) ||
     '{verify_url}' || char(10) || char(10) ||
     'If you did not request this you can ignore the message.');
