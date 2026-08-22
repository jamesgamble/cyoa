-- 0010_collaborators_and_ownership.sql
--
-- Version 0.20.0 — collaborators, invitations, and ownership transfer.
--
-- Adds:
--   • adventure_invitations — random, hashed, expiring, single-use,
--     revocable invitation tokens addressed to a registered account;
--   • notifications        — the in-app inbox that carries invitations,
--     role changes, and ownership transfers to the recipient;
--   • sessions.reauthenticated_at — the timestamp used to require a
--     recent password re-entry before an ownership transfer;
--   • two email templates used by the existing queue worker.
--
-- Exactly one owner per adventure is an invariant of the data model:
-- adventures.author_id IS the owner, and a matching 'owner' row in
-- adventure_collaborators mirrors it. Transfers rewrite both inside a
-- single transaction.

-- ---------------------------------------------------------------------------
-- adventure_invitations
--
-- state: pending | accepted | declined | revoked | expired (derived)
--
-- Only the SHA-256 of the token is stored; the raw token travels once,
-- by email and in the recipient's inbox link. `accepted_at`,
-- `declined_at`, and `revoked_at` make the token single-use and
-- revocable: any non-null terminal column disqualifies it.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS adventure_invitations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    email        TEXT    NOT NULL,
    invitee_id   INTEGER REFERENCES users(id) ON DELETE CASCADE,
    role         TEXT    NOT NULL DEFAULT 'editor'
                 CHECK (role IN ('editor','reviewer')),
    token_hash   TEXT    NOT NULL UNIQUE,
    invited_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    message      TEXT,
    expires_at   TEXT    NOT NULL,
    accepted_at  TEXT,
    declined_at  TEXT,
    revoked_at   TEXT,
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_invitations_adventure
    ON adventure_invitations(adventure_id, created_at);
CREATE INDEX IF NOT EXISTS idx_invitations_invitee
    ON adventure_invitations(invitee_id, accepted_at, declined_at, revoked_at);

-- ---------------------------------------------------------------------------
-- notifications — the recipient's in-app inbox.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    url          TEXT,
    adventure_id INTEGER REFERENCES adventures(id) ON DELETE CASCADE,
    read_at      TEXT,
    created_at   TEXT    NOT NULL
                 DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

CREATE INDEX IF NOT EXISTS idx_notifications_user
    ON notifications(user_id, read_at, created_at);

-- ---------------------------------------------------------------------------
-- sessions.reauthenticated_at — proof of a recent password re-entry.
-- Backfilled from created_at: a session created by a successful login
-- was, at that moment, freshly authenticated.
-- ---------------------------------------------------------------------------
ALTER TABLE sessions ADD COLUMN reauthenticated_at TEXT;
UPDATE sessions SET reauthenticated_at = created_at WHERE reauthenticated_at IS NULL;

-- ---------------------------------------------------------------------------
-- Email templates for the existing queue worker.
-- ---------------------------------------------------------------------------
INSERT OR IGNORE INTO email_templates (template_key, subject, text_body) VALUES
    ('adventure_invitation',
     'You have been invited to help with {adventure_title}',
     'Hello {display_name},' || char(10) || char(10) ||
     '{inviter_name} invited you to join "{adventure_title}" as {role}.' || char(10) ||
     'Accept or decline here:' || char(10) ||
     '{invite_url}' || char(10) || char(10) ||
     'This invitation expires on {expires_at} and can only be used once.'),
    ('ownership_transferred',
     'You are now the owner of {adventure_title}',
     'Hello {display_name},' || char(10) || char(10) ||
     '{previous_owner} transferred ownership of "{adventure_title}" to you.' || char(10) ||
     'Open it here:' || char(10) ||
     '{adventure_url}');
