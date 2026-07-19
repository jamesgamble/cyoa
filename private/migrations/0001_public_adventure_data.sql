-- 0001_public_adventure_data.sql
--
-- Version 0.10.0 — public adventure data model.
--
-- Introduces the core content tables (users, adventures, scenes,
-- choices, content_warnings) plus the indexes the discovery and
-- traversal endpoints depend on. All state / visibility / type
-- columns are constrained by CHECK so a stray write can never sneak
-- an invalid enum value past the schema.

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    created_at   TEXT NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- ---------------------------------------------------------------------------
-- adventures
--
-- state:      draft | published | on-hold | complete | archived | suspended
-- visibility: public | unlisted
-- contribution_state: immediate | approval | closed
-- ---------------------------------------------------------------------------
CREATE TABLE adventures (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    slug               TEXT    NOT NULL UNIQUE,
    title              TEXT    NOT NULL,
    author_id          INTEGER NOT NULL REFERENCES users(id),
    synopsis           TEXT,
    description        TEXT,
    genre              TEXT,
    content_rating     TEXT    NOT NULL DEFAULT 'everyone'
                       CHECK (content_rating IN ('everyone','teen','mature')),
    state              TEXT    NOT NULL DEFAULT 'draft'
                       CHECK (state IN (
                         'draft','published','on-hold',
                         'complete','archived','suspended'
                       )),
    visibility         TEXT    NOT NULL DEFAULT 'public'
                       CHECK (visibility IN ('public','unlisted')),
    contribution_state TEXT    NOT NULL DEFAULT 'closed'
                       CHECK (contribution_state IN (
                         'immediate','approval','closed'
                       )),
    writing_guidelines TEXT,
    created_at         TEXT    NOT NULL
                       DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at         TEXT    NOT NULL
                       DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- ---------------------------------------------------------------------------
-- scenes
--
-- scene_type: story | ending
-- state:      draft | published | hidden
--
-- `slug` is URL-safe and unique within an adventure. `is_start` marks
-- the canonical opening scene; at most one row per adventure should
-- have it set to 1 (enforced by the seed data and future authoring
-- code, not by SQL).
-- ---------------------------------------------------------------------------
CREATE TABLE scenes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id  INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    slug          TEXT    NOT NULL,
    scene_number  INTEGER NOT NULL,
    chapter       TEXT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL,
    scene_type    TEXT    NOT NULL DEFAULT 'story'
                  CHECK (scene_type IN ('story','ending')),
    state         TEXT    NOT NULL DEFAULT 'draft'
                  CHECK (state IN ('draft','published','hidden')),
    ending_title  TEXT,
    ending_kind   TEXT,
    ending_body   TEXT,
    is_start      INTEGER NOT NULL DEFAULT 0
                  CHECK (is_start IN (0, 1)),
    created_at    TEXT    NOT NULL
                  DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at    TEXT    NOT NULL
                  DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (adventure_id, slug)
);

-- ---------------------------------------------------------------------------
-- choices
--
-- A choice always points to another scene inside the same adventure.
-- Cross-adventure targets are prevented by the visibility rules in
-- the read layer, not by the schema, so a future authoring flow can
-- validate them explicitly.
-- ---------------------------------------------------------------------------
CREATE TABLE choices (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    scene_id        INTEGER NOT NULL REFERENCES scenes(id) ON DELETE CASCADE,
    target_scene_id INTEGER NOT NULL REFERENCES scenes(id) ON DELETE CASCADE,
    label           TEXT    NOT NULL,
    position        INTEGER NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------------
-- content_warnings
-- ---------------------------------------------------------------------------
CREATE TABLE content_warnings (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    label        TEXT    NOT NULL,
    position     INTEGER NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------------
-- Indexes for discovery and traversal
-- ---------------------------------------------------------------------------
CREATE INDEX idx_adventures_state_visibility ON adventures(state, visibility);
CREATE INDEX idx_adventures_updated_at       ON adventures(updated_at);
CREATE INDEX idx_adventures_genre            ON adventures(genre);
CREATE INDEX idx_adventures_content_rating   ON adventures(content_rating);
CREATE INDEX idx_adventures_contribution     ON adventures(contribution_state);

CREATE INDEX idx_scenes_adventure_state      ON scenes(adventure_id, state);
CREATE INDEX idx_scenes_adventure_number     ON scenes(adventure_id, scene_number);
CREATE INDEX idx_scenes_start                ON scenes(adventure_id, is_start);

CREATE INDEX idx_choices_scene               ON choices(scene_id, position);
CREATE INDEX idx_choices_target              ON choices(target_scene_id);

CREATE INDEX idx_warnings_adventure          ON content_warnings(adventure_id, position);
