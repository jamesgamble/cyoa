-- 0006_adventure_creation.sql
--
-- Version 0.16.0 — adventure creation.
--
-- Extends `adventures` with the settings collected by the five-step
-- creation wizard, gives `scenes` a derived plain-text projection of
-- the sanitized rich-text body, and adds a rate-limit ledger plus the
-- configurable per-user cap.
--
-- Every new column has a default so existing rows stay valid.

-- ---------------------------------------------------------------------------
-- adventures — contribution settings collected during creation
-- ---------------------------------------------------------------------------
ALTER TABLE adventures ADD COLUMN anonymous_contributions INTEGER NOT NULL DEFAULT 0;
ALTER TABLE adventures ADD COLUMN max_branches_per_scene  INTEGER NOT NULL DEFAULT 4;
ALTER TABLE adventures ADD COLUMN contribution_passcode_hash TEXT;
ALTER TABLE adventures ADD COLUMN writing_guidelines_plain TEXT;
ALTER TABLE adventures ADD COLUMN template_key TEXT;

-- ---------------------------------------------------------------------------
-- scenes — plain-text projection derived from the sanitized body.
-- Used for character limits, search, snippets, duplicate detection,
-- and plain-text exports. Never rendered as HTML.
-- ---------------------------------------------------------------------------
ALTER TABLE scenes ADD COLUMN body_plain TEXT;

CREATE INDEX IF NOT EXISTS idx_adventures_author ON adventures(author_id, created_at);

-- ---------------------------------------------------------------------------
-- adventure_creation_attempts — rate-limit ledger.
-- One row per accepted creation; the service counts rows inside the
-- rolling window before starting a new transaction.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS adventure_creation_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip         TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
               DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_creation_attempts_user
    ON adventure_creation_attempts(user_id, created_at);

-- ---------------------------------------------------------------------------
-- Configurable limits
-- ---------------------------------------------------------------------------
INSERT INTO settings (key, value, updated_at)
VALUES ('max_adventures_per_user', '20', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
ON CONFLICT(key) DO NOTHING;

INSERT INTO settings (key, value, updated_at)
VALUES ('adventures_per_user_per_hour', '5', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
ON CONFLICT(key) DO NOTHING;
