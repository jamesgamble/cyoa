-- 0009_moderation_and_owner_controls.sql
--
-- Version 0.19.0 — moderation and owner controls.
--
-- Adds the review lifecycle for branch submissions, reviewer notes and
-- recommendations, per-adventure contributor permissions, reader
-- reports, and the owner-controlled contribution switches.
--
-- Two existing tables are rebuilt because SQLite cannot widen a CHECK
-- constraint in place: `branch_submissions` (new state machine) and
-- `adventure_collaborators` (new `reviewer` role).

-- ---------------------------------------------------------------------------
-- adventures — owner controls.
--
-- `contributions_paused` is a temporary stop that leaves the
-- contribution mode untouched, so unpausing restores the previous
-- setting exactly. `allow_branching` is the permanent switch.
-- ---------------------------------------------------------------------------
ALTER TABLE adventures ADD COLUMN contributions_paused INTEGER NOT NULL DEFAULT 0
      CHECK (contributions_paused IN (0, 1));
ALTER TABLE adventures ADD COLUMN allow_branching INTEGER NOT NULL DEFAULT 1
      CHECK (allow_branching IN (0, 1));
ALTER TABLE adventures ADD COLUMN notify_on_submission INTEGER NOT NULL DEFAULT 1
      CHECK (notify_on_submission IN (0, 1));
ALTER TABLE adventures ADD COLUMN notify_on_report INTEGER NOT NULL DEFAULT 1
      CHECK (notify_on_report IN (0, 1));

-- ---------------------------------------------------------------------------
-- adventure_collaborators — add the reviewer role.
--
-- owner    — everything, including settings and collaborators
-- editor   — story and submission decisions, no ownership transfer
-- reviewer — private notes and recommendations only; never decides
-- ---------------------------------------------------------------------------
CREATE TABLE adventure_collaborators_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role         TEXT    NOT NULL DEFAULT 'editor'
                 CHECK (role IN ('owner','editor','reviewer')),
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (adventure_id, user_id)
);

INSERT INTO adventure_collaborators_new (id, adventure_id, user_id, role, created_at)
SELECT id, adventure_id, user_id, role, created_at FROM adventure_collaborators;

DROP TABLE adventure_collaborators;
ALTER TABLE adventure_collaborators_new RENAME TO adventure_collaborators;

CREATE INDEX IF NOT EXISTS idx_collaborators_user
    ON adventure_collaborators(user_id, adventure_id);

-- ---------------------------------------------------------------------------
-- branch_submissions — the review state machine.
--
--   pending           awaiting a decision (holds a branch slot)
--   changes_requested returned to the contributor with feedback
--   approved          live in the story (created_scene_id is set)
--   rejected          declined with feedback; terminal
--   withdrawn         retracted by the contributor; terminal
--
-- Legacy states map: published -> approved, declined -> rejected.
--
-- `feedback` is the message shown to the contributor. `private_note`
-- (contributor -> moderators) and reviewer notes are never public.
-- `revision` counts contributor resubmissions.
-- ---------------------------------------------------------------------------
CREATE TABLE branch_submissions_new (
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
                     CHECK (state IN ('pending','changes_requested','approved',
                                      'rejected','withdrawn')),
    created_scene_id INTEGER REFERENCES scenes(id) ON DELETE SET NULL,
    created_choice_id INTEGER,
    decided_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    decided_at       TEXT,
    moderator_note   TEXT,
    feedback         TEXT,
    edited_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
    revision         INTEGER NOT NULL DEFAULT 0,
    updated_at       TEXT,
    created_at       TEXT    NOT NULL
                     DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

INSERT INTO branch_submissions_new
    (id, adventure_id, source_scene_id, user_id, submitted_ip, attribution,
     choice_text, choice_text_key, scene_title, scene_body, scene_body_plain,
     scene_type, private_note, state, created_scene_id, created_choice_id,
     decided_by, decided_at, moderator_note, created_at)
SELECT id, adventure_id, source_scene_id, user_id, submitted_ip, attribution,
       choice_text, choice_text_key, scene_title, scene_body, scene_body_plain,
       scene_type, private_note,
       CASE state
         WHEN 'published' THEN 'approved'
         WHEN 'declined'  THEN 'rejected'
         ELSE state
       END,
       created_scene_id, created_choice_id, decided_by, decided_at,
       moderator_note, created_at
  FROM branch_submissions;

DROP TABLE branch_submissions;
ALTER TABLE branch_submissions_new RENAME TO branch_submissions;

CREATE INDEX IF NOT EXISTS idx_submissions_adventure
    ON branch_submissions(adventure_id, state, created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_scene
    ON branch_submissions(source_scene_id, state);
CREATE INDEX IF NOT EXISTS idx_submissions_user
    ON branch_submissions(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_submissions_duplicate
    ON branch_submissions(source_scene_id, choice_text_key, state);

-- ---------------------------------------------------------------------------
-- submission_reviews — reviewer notes and recommendations.
--
-- A recommendation is advice only: nothing about it changes the
-- submission state. Only owners, editors, and administrators decide.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS submission_reviews (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id  INTEGER NOT NULL REFERENCES branch_submissions(id) ON DELETE CASCADE,
    adventure_id   INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    reviewer_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    note           TEXT    NOT NULL DEFAULT '',
    recommendation TEXT
                   CHECK (recommendation IS NULL OR recommendation IN ('approve','reject')),
    created_at     TEXT    NOT NULL
                   DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_reviews_submission
    ON submission_reviews(submission_id, created_at);

-- ---------------------------------------------------------------------------
-- adventure_permissions — per-user, per-adventure contributor standing.
--
--   trusted           bypasses the approval queue on this adventure
--   approval_required always queued, even in immediate mode
--   blocked           cannot contribute at all
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS adventure_permissions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    level        TEXT    NOT NULL
                 CHECK (level IN ('trusted','approval_required','blocked')),
    note         TEXT,
    set_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (adventure_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_permissions_adventure
    ON adventure_permissions(adventure_id, level);

-- ---------------------------------------------------------------------------
-- content_reports — reader reports about a scene or a submission.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_reports (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id   INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    scene_id       INTEGER REFERENCES scenes(id) ON DELETE SET NULL,
    submission_id  INTEGER REFERENCES branch_submissions(id) ON DELETE SET NULL,
    reporter_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reporter_ip    TEXT    NOT NULL DEFAULT '',
    reason         TEXT    NOT NULL
                   CHECK (reason IN ('rating','warning','spam','harassment',
                                     'illegal','broken','other')),
    details        TEXT,
    state          TEXT    NOT NULL DEFAULT 'open'
                   CHECK (state IN ('open','resolved','dismissed')),
    resolved_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    resolved_at    TEXT,
    resolution_note TEXT,
    created_at     TEXT    NOT NULL
                   DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_reports_adventure
    ON content_reports(adventure_id, state, created_at);
