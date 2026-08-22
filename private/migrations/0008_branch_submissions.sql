-- 0008_branch_submissions.sql
--
-- Version 0.18.0 — branch submissions.
--
-- A branch is one choice attached to a published source scene plus the
-- scene that choice leads to. This migration stores the submission
-- itself (so approval mode has something to queue and every
-- contributor has a history), the per-scene lock that closes a scene
-- to new branches, the contributor block list, and the rate-limit
-- ledger.

-- ---------------------------------------------------------------------------
-- scenes — a scene can be locked against new branches without hiding it.
-- ---------------------------------------------------------------------------
ALTER TABLE scenes ADD COLUMN is_locked INTEGER NOT NULL DEFAULT 0
      CHECK (is_locked IN (0, 1));

-- ---------------------------------------------------------------------------
-- branch_submissions
--
-- state:       published | pending | declined | changes_requested
-- attribution: username | display_name | anonymous
--
-- `attribution` is a PUBLIC display preference only. `user_id` and
-- `submitted_ip` are the internal attribution and are always recorded,
-- including for `anonymous` — choosing anonymity hides a name from
-- readers, never from moderators.
--
-- `private_note` is a message to the moderators. It is never part of
-- any public payload.
--
-- `choice_text_key` is the normalised choice text (lowercased, spaces
-- collapsed, punctuation stripped) used for duplicate detection.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branch_submissions (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id     INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    source_scene_id  INTEGER NOT NULL REFERENCES scenes(id) ON DELETE CASCADE,
    user_id          INTEGER REFERENCES users(id) ON DELETE SET NULL,
    submitted_ip     TEXT    NOT NULL DEFAULT '',
    attribution      TEXT    NOT NULL DEFAULT 'username'
                     CHECK (attribution IN ('username','display_name','anonymous')),
    choice_text      TEXT    NOT NULL,
    choice_text_key  TEXT    NOT NULL,
    scene_title      TEXT    NOT NULL,
    scene_body       TEXT    NOT NULL,
    scene_body_plain TEXT    NOT NULL DEFAULT '',
    scene_type       TEXT    NOT NULL DEFAULT 'story'
                     CHECK (scene_type IN ('story','ending')),
    private_note     TEXT,
    state            TEXT    NOT NULL DEFAULT 'pending'
                     CHECK (state IN ('published','pending','declined','changes_requested')),
    created_scene_id INTEGER REFERENCES scenes(id) ON DELETE SET NULL,
    created_choice_id INTEGER,
    decided_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    decided_at       TEXT,
    moderator_note   TEXT,
    created_at       TEXT    NOT NULL
                     DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_submissions_adventure
    ON branch_submissions(adventure_id, state, created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_scene
    ON branch_submissions(source_scene_id, state);
CREATE INDEX IF NOT EXISTS idx_submissions_user
    ON branch_submissions(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_duplicate
    ON branch_submissions(source_scene_id, choice_text_key, state);

-- ---------------------------------------------------------------------------
-- contribution_blocks — contributors barred from one adventure.
-- Either a user id or an IP; a row with both is matched on either.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contribution_blocks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    user_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
    ip           TEXT,
    reason       TEXT,
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_blocks_adventure
    ON contribution_blocks(adventure_id, user_id);
CREATE INDEX IF NOT EXISTS idx_blocks_ip
    ON contribution_blocks(adventure_id, ip);

-- ---------------------------------------------------------------------------
-- contribution_attempts — rate-limit ledger. One row per accepted
-- submission, counted inside a rolling window before the next one is
-- allowed to start a transaction.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contribution_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER REFERENCES adventures(id) ON DELETE CASCADE,
    user_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
    ip           TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_contribution_attempts_user
    ON contribution_attempts(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_contribution_attempts_ip
    ON contribution_attempts(ip, created_at);

-- ---------------------------------------------------------------------------
-- Configurable limits
-- ---------------------------------------------------------------------------
INSERT INTO settings (key, value, updated_at)
VALUES ('contributions_per_user_per_hour', '10', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
ON CONFLICT(key) DO NOTHING;

INSERT INTO settings (key, value, updated_at)
VALUES ('contributions_per_ip_per_hour', '5', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
ON CONFLICT(key) DO NOTHING;
