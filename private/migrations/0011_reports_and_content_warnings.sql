-- 0011_reports_and_content_warnings.sql
--
-- Version 0.21.0 — reports and content warnings.
--
-- Reports
--   content_reports is rebuilt so a report can address any of the four
--   reportable things — the adventure itself, a scene, a single choice,
--   or an accessible contribution (a branch submission) — with the
--   eight published reasons. Two columns are added for handling:
--
--     • state gains 'escalated' so an owner can pass a report to the
--       platform without resolving it, and action_taken records which
--       owner action closed it (dismiss, hide scene, lock scene);
--     • platform_private marks a report administrators keep off the
--       adventure team's queue.
--
--   reporter_key is the duplicate/rate-limit fingerprint: the account
--   id when signed in, otherwise a hash of the request IP. It is never
--   shown to an adventure team.
--
--   Report counts NEVER remove content: nothing in this schema or in
--   ReportService acts on a threshold. Every removal is a recorded
--   human decision.
--
-- Content warnings
--   content_warnings gains a canonical `code` (violence, language,
--   horror, sexual, substances, self_harm, other) plus optional
--   free-text detail, so the reader-facing gate can be shown before a
--   story is read and remembered per adventure by the reader.

-- ---------------------------------------------------------------------------
-- content_reports — rebuilt
-- ---------------------------------------------------------------------------
CREATE TABLE content_reports_new (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    adventure_id    INTEGER NOT NULL REFERENCES adventures(id) ON DELETE CASCADE,
    target_type     TEXT    NOT NULL DEFAULT 'adventure'
                    CHECK (target_type IN ('adventure','scene','choice','submission')),
    scene_id        INTEGER REFERENCES scenes(id) ON DELETE SET NULL,
    choice_id       INTEGER REFERENCES choices(id) ON DELETE SET NULL,
    submission_id   INTEGER REFERENCES branch_submissions(id) ON DELETE SET NULL,
    reporter_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reporter_ip     TEXT    NOT NULL DEFAULT '',
    reporter_key    TEXT    NOT NULL DEFAULT '',
    reason          TEXT    NOT NULL
                    CHECK (reason IN ('spam','harassment','hate','explicit',
                                      'personal_information','broken','copyright','other',
                                      'rating','warning','illegal')),
    details         TEXT,
    state           TEXT    NOT NULL DEFAULT 'open'
                    CHECK (state IN ('open','resolved','dismissed','escalated')),
    platform_private INTEGER NOT NULL DEFAULT 0,
    action_taken    TEXT,
    resolved_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    resolved_at     TEXT,
    resolution_note TEXT,
    created_at      TEXT    NOT NULL
                    DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

INSERT INTO content_reports_new
    (id, adventure_id, target_type, scene_id, submission_id, reporter_id,
     reporter_ip, reporter_key, reason, details, state, resolved_by,
     resolved_at, resolution_note, created_at)
SELECT
    id, adventure_id,
    CASE WHEN scene_id IS NOT NULL THEN 'scene'
         WHEN submission_id IS NOT NULL THEN 'submission'
         ELSE 'adventure' END,
    scene_id, submission_id, reporter_id, reporter_ip,
    COALESCE(CAST(reporter_id AS TEXT), ''),
    reason, details, state, resolved_by, resolved_at, resolution_note, created_at
FROM content_reports;

DROP TABLE content_reports;
ALTER TABLE content_reports_new RENAME TO content_reports;

CREATE INDEX IF NOT EXISTS idx_reports_adventure
    ON content_reports(adventure_id, state, created_at);
CREATE INDEX IF NOT EXISTS idx_reports_target
    ON content_reports(adventure_id, target_type, scene_id, choice_id, submission_id);
CREATE INDEX IF NOT EXISTS idx_reports_reporter_key
    ON content_reports(reporter_key, created_at);

-- ---------------------------------------------------------------------------
-- content_warnings — canonical codes and optional detail
-- ---------------------------------------------------------------------------
ALTER TABLE content_warnings ADD COLUMN code TEXT NOT NULL DEFAULT 'other';
ALTER TABLE content_warnings ADD COLUMN details TEXT;

UPDATE content_warnings SET code = CASE
    WHEN lower(label) LIKE '%violen%'  THEN 'violence'
    WHEN lower(label) LIKE '%languag%' THEN 'language'
    WHEN lower(label) LIKE '%horror%'  THEN 'horror'
    WHEN lower(label) LIKE '%peril%'   THEN 'horror'
    WHEN lower(label) LIKE '%sexual%'  THEN 'sexual'
    WHEN lower(label) LIKE '%substanc%' THEN 'substances'
    WHEN lower(label) LIKE '%alcohol%' THEN 'substances'
    WHEN lower(label) LIKE '%drug%'    THEN 'substances'
    WHEN lower(label) LIKE '%self-harm%' THEN 'self_harm'
    WHEN lower(label) LIKE '%self harm%' THEN 'self_harm'
    ELSE 'other'
END;

CREATE INDEX IF NOT EXISTS idx_warnings_code ON content_warnings(adventure_id, code);
