-- 0013_revision_history.sql
--
-- Version 0.24.0 — revision history for published story text.
--
-- A revision holds the content of one field exactly as it was just
-- before a change (or before a restore). Only story text is stored:
-- no credentials, sessions, SMTP data, emails, or IP addresses.

CREATE TABLE IF NOT EXISTS content_revisions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    target_type  TEXT    NOT NULL CHECK (target_type IN ('adventure','scene','choice')),
    target_id    INTEGER NOT NULL,
    field        TEXT    NOT NULL CHECK (field IN ('description','writing_guidelines','title','body','label')),
    content      TEXT    NOT NULL,
    editor_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reason       TEXT    NOT NULL DEFAULT 'edit' CHECK (reason IN ('edit','restore')),
    created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

CREATE INDEX IF NOT EXISTS idx_revisions_target
    ON content_revisions (target_type, target_id, field, id);
CREATE INDEX IF NOT EXISTS idx_revisions_adventure
    ON content_revisions (adventure_id, id);
