-- 0012_notifications_and_follows.sql
--
-- Version 0.22.0 — in-site notifications, per-kind email preferences,
-- adventure follows, and aggregated follow digests.
--
-- Bookmarks (user_bookmarks, kind='bookmark') stay what they always
-- were: a reading position. Following is a separate subscription and
-- lives in its own table so the two never influence each other.
--
-- Follower counts are deliberately not materialised anywhere: the
-- product never exposes them, so no column tempts a future reader.

-- ---------------------------------------------------------------------------
-- notifications.routine — routine rows may be deleted by their owner.
-- Security, invitation, and ownership rows are kept as a record.
-- ---------------------------------------------------------------------------
ALTER TABLE notifications ADD COLUMN routine INTEGER NOT NULL DEFAULT 1;

UPDATE notifications
   SET routine = 0
 WHERE kind IN ('account_security','collaborator_invitation',
                'ownership_transfer','invitation','invitation_accepted',
                'invitation_declined','ownership_transferred','role_changed');

CREATE INDEX IF NOT EXISTS idx_notifications_user_kind
    ON notifications(user_id, kind, created_at);

-- ---------------------------------------------------------------------------
-- notification_preferences — one row per (user, kind) that the user has
-- explicitly changed. Missing rows fall back to the kind's default, so
-- new kinds ship with a sensible setting and no backfill.
--
-- Security and recovery mail is not represented here: it cannot be
-- disabled, and the service rejects attempts to write those kinds.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind          TEXT    NOT NULL,
    email_enabled INTEGER NOT NULL DEFAULT 1,
    updated_at    TEXT    NOT NULL
                  DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (user_id, kind)
);

-- ---------------------------------------------------------------------------
-- adventure_follows — an update subscription, not a reading position.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS adventure_follows (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (user_id, adventure_id)
);

CREATE INDEX IF NOT EXISTS idx_follows_adventure
    ON adventure_follows(adventure_id);

-- ---------------------------------------------------------------------------
-- notification_digests — pending rows for the aggregated followed-
-- adventure email. One email per user per worker pass, never one per
-- update. Rows are marked sent rather than deleted so a crashed worker
-- cannot double-send.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_digests (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    adventure_id    INTEGER REFERENCES adventures(id) ON DELETE CASCADE,
    notification_id INTEGER REFERENCES notifications(id) ON DELETE CASCADE,
    summary         TEXT    NOT NULL DEFAULT '',
    created_at      TEXT    NOT NULL
                    DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    sent_at         TEXT
);

CREATE INDEX IF NOT EXISTS idx_digests_pending
    ON notification_digests(sent_at, user_id, created_at);

-- ---------------------------------------------------------------------------
-- Email templates used by the queue worker.
-- ---------------------------------------------------------------------------
INSERT OR IGNORE INTO email_templates (template_key, subject, text_body) VALUES
    ('notification_alert',
     '{subject}',
     'Hello {display_name},' || char(10) || char(10) ||
     '{body}' || char(10) || char(10) ||
     '{url}' || char(10) || char(10) ||
     'You can change which notifications reach you by email in your ' ||
     'account settings.'),
    ('followed_updates_digest',
     'Updates from adventures you follow',
     'Hello {display_name},' || char(10) || char(10) ||
     'Here is what changed in the adventures you follow:' || char(10) || char(10) ||
     '{summary}' || char(10) || char(10) ||
     'Open your inbox for the full list.');
