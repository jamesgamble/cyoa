-- 0007_publication_workflow.sql
--
-- Version 0.17.0 — drafts, preview, and publishing.
--
-- Adds the collaborator roster that authorises draft access, preview,
-- and status changes, plus an append-only activity log written inside
-- the same transaction as every status change.

-- ---------------------------------------------------------------------------
-- adventure_collaborators
--
-- role: owner | editor
--
-- The adventure author is always treated as the owner even without a
-- row here; rows exist so additional editors can be granted access.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS adventure_collaborators (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role         TEXT    NOT NULL DEFAULT 'editor'
                 CHECK (role IN ('owner','editor')),
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (adventure_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_collaborators_user
    ON adventure_collaborators(user_id, adventure_id);

-- ---------------------------------------------------------------------------
-- adventure_activity — append-only record of status changes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS adventure_activity (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    user_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action       TEXT    NOT NULL,
    from_state   TEXT,
    to_state     TEXT,
    note         TEXT,
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_activity_adventure
    ON adventure_activity(adventure_id, created_at);

-- ---------------------------------------------------------------------------
-- Backfill an owner row for every existing adventure.
-- ---------------------------------------------------------------------------
INSERT OR IGNORE INTO adventure_collaborators (adventure_id, user_id, role)
SELECT id, author_id, 'owner' FROM adventures;
