-- 0014_master_administration.sql
--
-- Version 0.25.0 — master administration.
--
--   • platform_activity — an append-only log of platform-level actions
--     (role changes, suspensions, settings changes, escalations).
--     It never stores credentials, tokens, SMTP data, or IP addresses.
--   • adventures.suspended_from_state — remembers the state an adventure
--     held before a platform suspension so restore puts it back.
--   • New settings keys for anonymous-use policy, global limits, and
--     maintenance mode.

CREATE TABLE platform_activity (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action      TEXT    NOT NULL,
    target_type TEXT    NOT NULL DEFAULT '',
    target_id   INTEGER,
    note        TEXT,
    security    INTEGER NOT NULL DEFAULT 0,
    state       TEXT    NOT NULL DEFAULT 'logged'
                CHECK (state IN ('logged','open','closed')),
    created_at  TEXT    NOT NULL
                DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);
CREATE INDEX idx_platform_activity_time ON platform_activity(created_at);
CREATE INDEX idx_platform_activity_security ON platform_activity(security, created_at);

ALTER TABLE adventures ADD COLUMN suspended_from_state TEXT;

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('anonymous_reading_allowed',        '1'),
    ('anonymous_reports_allowed',        '1'),
    ('anonymous_contributions_allowed',  '1'),
    ('max_adventures_per_user',          '10'),
    ('adventures_per_user_per_hour',     '3'),
    ('contributions_per_user_per_hour',  '20'),
    ('contributions_per_ip_per_hour',    '30'),
    ('maintenance_mode',                 '0'),
    ('maintenance_message',              '');
