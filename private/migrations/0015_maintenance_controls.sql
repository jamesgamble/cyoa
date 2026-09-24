-- 0015_maintenance_controls.sql
--
-- Version 0.27.0 — maintenance controls.
--   • new_adventures_enabled        — when 0, nobody may create adventures.
--   • contributions_globally_paused — when 1, no branch submissions anywhere.
--   • maintenance_mode (existing)   — read-only mode.
--   • maintenance_message (existing)— custom public notice.

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('new_adventures_enabled',        '1'),
    ('contributions_globally_paused', '0');
