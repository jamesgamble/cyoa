<?php
/**
 * ReportService — reports and content warnings (v0.21.0).
 *
 * Reports
 *   Any reader, signed in or not, can report an adventure, a scene, a
 *   single choice, or an accessible contribution. Three abuse controls
 *   guard the endpoint:
 *
 *     • a honeypot field — a filled hidden field is accepted silently
 *       and discarded, so a bot learns nothing;
 *     • rate limiting — per reporter fingerprint, per rolling hour;
 *     • duplicate detection — the same fingerprint reporting the same
 *       target for the same reason again is folded into the first one.
 *
 *   Nothing here removes content. A report count never hides, locks, or
 *   unpublishes anything: every action is taken by a named person and
 *   recorded in the adventure activity log.
 *
 * Privacy
 *   Adventure teams see the reason, the note, and the target. They
 *   never see the reporter's IP or fingerprint, and they never see a
 *   report an administrator has marked platform-private.
 *
 * Content warnings
 *   Seven canonical codes with optional free text, edited by owners and
 *   editors, published to readers so the reader-facing gate can be
 *   shown before the story is read.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class ReportService
{
    public const OK           = 'ok';
    public const INVALID      = 'invalid';
    public const NOT_FOUND    = 'not_found';
    public const FORBIDDEN    = 'forbidden';
    public const CONFLICT     = 'conflict';
    public const DUPLICATE    = 'duplicate';
    public const RATE_LIMITED = 'rate_limited';

    /** The reportable things. */
    public const TARGETS = ['adventure', 'scene', 'choice', 'submission'];

    /** The published reasons, in the order the form offers them. */
    public const REASONS = [
        'spam', 'harassment', 'hate', 'explicit',
        'personal_information', 'broken', 'copyright', 'other',
    ];

    /** Owner actions on a report. None of them is automatic. */
    public const ACTIONS = ['dismiss', 'hide_scene', 'lock_scene', 'escalate'];

    /** Report queue tabs. */
    public const STATES = ['open', 'resolved', 'dismissed', 'escalated'];

    /** Canonical content-warning codes. */
    public const WARNING_CODES = [
        'violence', 'language', 'horror', 'sexual',
        'substances', 'self_harm', 'other',
    ];

    /** Default reader-facing labels for the canonical codes. */
    public const WARNING_LABELS = [
        'violence'   => 'Violence',
        'language'   => 'Strong language',
        'horror'     => 'Horror',
        'sexual'     => 'Sexual themes',
        'substances' => 'Substance use',
        'self_harm'  => 'Self-harm',
        'other'      => 'Other',
    ];

    public const DETAILS_MAX = 2000;
    public const NOTE_MAX    = 2000;

    /** Reports accepted from one fingerprint per rolling hour. */
    public const RATE_PER_HOUR = 8;

    /** Window in which a repeat report of the same target is a duplicate. */
    public const DUPLICATE_HOURS = 24;

    private PDO $pdo;
    private ModerationService $moderation;

    public function __construct(PDO $pdo)
    {
        $this->pdo        = $pdo;
        $this->moderation = new ModerationService($pdo);
    }

    /* ─────────────────────── Fingerprint ───────────────────────── */

    /**
     * The duplicate / rate-limit fingerprint. An account id when signed
     * in, otherwise a truncated hash of the request IP — never the raw
     * address, and never shown to an adventure team.
     */
    public static function reporterKey(?int $userId, string $ip): string
    {
        if ($userId !== null) return 'u:' . $userId;
        if (trim($ip) === '')  return 'anon';
        return 'ip:' . substr(hash('sha256', trim($ip)), 0, 32);
    }

    /* ───────────────────────── Reporting ───────────────────────── */

    /**
     * File a report.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function create(string $slug, ?int $userId, string $ip, array $input): array
    {
        // Honeypot: accepted, discarded, never stored.
        $honeypot = trim((string) ($input['website'] ?? ''));
        if ($honeypot !== '') {
            return [self::OK, ['report_id' => null, 'discarded' => true]];
        }

        $adv = $this->moderation->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];

        $reason = (string) ($input['reason'] ?? '');
        if (!in_array($reason, self::REASONS, true)) {
            return [self::INVALID, ['reason' => 'invalid']];
        }

        $targetType = (string) ($input['target_type'] ?? 'adventure');
        if (!in_array($targetType, self::TARGETS, true)) {
            return [self::INVALID, ['target_type' => 'invalid']];
        }

        $details = trim(HtmlSanitizer::toPlainText((string) ($input['details'] ?? '')));
        if (mb_strlen($details) > self::DETAILS_MAX) {
            return [self::INVALID, ['details' => 'too_long']];
        }

        $sceneId      = $this->intOrNull($input['scene_id'] ?? null);
        $choiceId     = $this->intOrNull($input['choice_id'] ?? null);
        $submissionId = $this->intOrNull($input['submission_id'] ?? null);

        // Every target is re-scoped to this adventure, so an id from one
        // adventure can never be reported through another's slug.
        switch ($targetType) {
            case 'scene':
                if ($sceneId === null || !$this->sceneBelongs($adventureId, $sceneId)) {
                    return [self::NOT_FOUND, null];
                }
                $choiceId = $submissionId = null;
                break;
            case 'choice':
                if ($choiceId === null) return [self::NOT_FOUND, null];
                $owner = $this->choiceScene($adventureId, $choiceId);
                if ($owner === null) return [self::NOT_FOUND, null];
                $sceneId = $owner;
                $submissionId = null;
                break;
            case 'submission':
                if ($submissionId === null || !$this->submissionAccessible($adventureId, $submissionId)) {
                    return [self::NOT_FOUND, null];
                }
                $choiceId = null;
                break;
            default:
                $sceneId = $choiceId = $submissionId = null;
        }

        $key = self::reporterKey($userId, $ip);

        if ($this->isRateLimited($key)) {
            return [self::RATE_LIMITED, null];
        }

        $existing = $this->findDuplicate($adventureId, $key, $targetType, $reason, $sceneId, $choiceId, $submissionId);
        if ($existing !== null) {
            return [self::DUPLICATE, ['report_id' => $existing]];
        }

        $this->pdo->prepare(
            'INSERT INTO content_reports
                (adventure_id, target_type, scene_id, choice_id, submission_id,
                 reporter_id, reporter_ip, reporter_key, reason, details)
             VALUES (:a, :t, :s, :c, :b, :u, :ip, :k, :r, :d)'
        )->execute([
            ':a'  => $adventureId,
            ':t'  => $targetType,
            ':s'  => $sceneId,
            ':c'  => $choiceId,
            ':b'  => $submissionId,
            ':u'  => $userId,
            ':ip' => $ip,
            ':k'  => $key,
            ':r'  => $reason,
            ':d'  => $details !== '' ? $details : null,
        ]);

        return [self::OK, ['report_id' => (int) $this->pdo->lastInsertId()]];
    }

    public function isRateLimited(string $key): bool
    {
        $s = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM content_reports
              WHERE reporter_key = :k AND created_at >= :since'
        );
        $s->execute([':k' => $key, ':since' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]);
        return (int) ($s->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) >= self::RATE_PER_HOUR;
    }

    /** The id of an earlier, identical report from the same reporter, if any. */
    public function findDuplicate(
        int $adventureId,
        string $key,
        string $targetType,
        string $reason,
        ?int $sceneId,
        ?int $choiceId,
        ?int $submissionId
    ): ?int {
        $s = $this->pdo->prepare(
            "SELECT id FROM content_reports
              WHERE adventure_id = :a
                AND reporter_key = :k
                AND target_type  = :t
                AND reason       = :r
                AND IFNULL(scene_id, 0)      = CAST(:s AS INTEGER)
                AND IFNULL(choice_id, 0)     = CAST(:c AS INTEGER)
                AND IFNULL(submission_id, 0) = CAST(:b AS INTEGER)
                AND created_at >= :since
              ORDER BY id DESC LIMIT 1"
        );
        $s->execute([
            ':since' => gmdate('Y-m-d\TH:i:s\Z', time() - self::DUPLICATE_HOURS * 3600),
            ':a' => $adventureId, ':k' => $key, ':t' => $targetType, ':r' => $reason,
            ':s' => $sceneId ?? 0, ':c' => $choiceId ?? 0, ':b' => $submissionId ?? 0,
        ]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : (int) $r['id'];
    }

    /* ───────────────────────── The queue ───────────────────────── */

    /**
     * The adventure team's queue. Platform-private reports are excluded
     * for everyone except administrators, and the reporter's IP and
     * fingerprint are never part of the payload.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function queue(string $slug, ?int $userId, bool $isAdmin, string $state = 'open'): array
    {
        $adv = $this->moderation->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->moderation->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->moderation->canView($role)) return [self::FORBIDDEN, null];
        if (!in_array($state, self::STATES, true)) return [self::INVALID, null];

        $sql = 'SELECT r.*, s.title AS scene_title, s.state AS scene_state,
                       s.is_locked AS scene_locked, c.label AS choice_label,
                       u.username AS reporter_username
                  FROM content_reports r
             LEFT JOIN scenes  s ON s.id = r.scene_id
             LEFT JOIN choices c ON c.id = r.choice_id
             LEFT JOIN users   u ON u.id = r.reporter_id
                 WHERE r.adventure_id = :a AND r.state = :st';
        if (!$isAdmin) $sql .= ' AND r.platform_private = 0';
        $sql .= ' ORDER BY r.id DESC';

        $s = $this->pdo->prepare($sql);
        $s->execute([':a' => $adventureId, ':st' => $state]);

        $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $items[] = [
                'id'            => (int) $r['id'],
                'target_type'   => (string) $r['target_type'],
                'reason'        => (string) $r['reason'],
                'details'       => $r['details'],
                'state'         => (string) $r['state'],
                'scene_id'      => $r['scene_id'] === null ? null : (int) $r['scene_id'],
                'scene_title'   => $r['scene_title'],
                'scene_state'   => $r['scene_state'],
                'scene_locked'  => (int) ($r['scene_locked'] ?? 0) === 1,
                'choice_id'     => $r['choice_id'] === null ? null : (int) $r['choice_id'],
                'choice_label'  => $r['choice_label'],
                'submission_id' => $r['submission_id'] === null ? null : (int) $r['submission_id'],
                // Signed-in reporters are named to the team; anonymous
                // reporters stay anonymous. The IP is never exposed.
                'reporter'      => $r['reporter_username'],
                'action_taken'  => $r['action_taken'],
                'resolution_note' => $r['resolution_note'],
                'platform_private' => (int) $r['platform_private'] === 1,
                'created_at'    => (string) $r['created_at'],
            ];
        }

        $role = $role ?? '';
        return [self::OK, [
            'state'        => $state,
            'role'         => $role,
            'is_admin'     => $isAdmin,
            'can_act'      => $this->moderation->canDecide($role),
            'reasons'      => self::REASONS,
            'actions'      => self::ACTIONS,
            'reports'      => $items,
        ]];
    }

    /* ───────────────────────── Handling ────────────────────────── */

    /**
     * An owner or editor acts on one open report. The action is applied
     * and the report closed inside a single transaction, so the same
     * report can never be handled twice.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function act(
        string $slug,
        int $reportId,
        ?int $userId,
        bool $isAdmin,
        string $action,
        string $note = ''
    ): array {
        $adv = $this->moderation->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->moderation->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->moderation->canView($role)) return [self::FORBIDDEN, null];
        if (!$this->moderation->canDecide($role)) return [self::FORBIDDEN, null];
        if (!in_array($action, self::ACTIONS, true)) return [self::INVALID, null];

        $note = trim(HtmlSanitizer::toPlainText($note));
        if (mb_strlen($note) > self::NOTE_MAX) return [self::INVALID, null];

        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare(
                'SELECT * FROM content_reports
                  WHERE id = :i AND adventure_id = :a LIMIT 1'
            );
            $s->execute([':i' => $reportId, ':a' => $adventureId]);
            $report = $s->fetch(PDO::FETCH_ASSOC);
            if ($report === false) { $this->pdo->rollBack(); return [self::NOT_FOUND, null]; }
            if (!$isAdmin && (int) $report['platform_private'] === 1) {
                $this->pdo->rollBack();
                return [self::FORBIDDEN, null];
            }
            if ((string) $report['state'] !== 'open') {
                $this->pdo->rollBack();
                return [self::CONFLICT, ['state' => (string) $report['state']]];
            }

            $sceneId = $report['scene_id'] === null ? null : (int) $report['scene_id'];

            if ($action === 'hide_scene' || $action === 'lock_scene') {
                if ($sceneId === null) { $this->pdo->rollBack(); return [self::INVALID, ['scene' => 'missing']]; }
                $scene = $this->sceneRow($adventureId, $sceneId);
                if ($scene === null) { $this->pdo->rollBack(); return [self::NOT_FOUND, null]; }
                if ($action === 'hide_scene') {
                    if ((int) $scene['is_start'] === 1) {
                        $this->pdo->rollBack();
                        return [self::CONFLICT, ['reason' => 'opening_scene']];
                    }
                    $this->pdo->prepare(
                        "UPDATE scenes SET state = 'hidden',
                                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                          WHERE id = :i AND adventure_id = :a"
                    )->execute([':i' => $sceneId, ':a' => $adventureId]);
                } else {
                    $this->pdo->prepare(
                        "UPDATE scenes SET is_locked = 1,
                                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                          WHERE id = :i AND adventure_id = :a"
                    )->execute([':i' => $sceneId, ':a' => $adventureId]);
                }
            }

            $state = $action === 'escalate' ? 'escalated'
                   : ($action === 'dismiss' ? 'dismissed' : 'resolved');

            $u = $this->pdo->prepare(
                "UPDATE content_reports
                    SET state = :st, action_taken = :act, resolved_by = :by,
                        resolution_note = :n,
                        resolved_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :i AND adventure_id = :a AND state = 'open'"
            );
            $u->execute([
                ':st' => $state, ':act' => $action, ':by' => $userId,
                ':n' => $note !== '' ? $note : null,
                ':i' => $reportId, ':a' => $adventureId,
            ]);
            if ($u->rowCount() !== 1) { $this->pdo->rollBack(); return [self::CONFLICT, null]; }

            $this->pdo->prepare(
                'INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state, note)
                 VALUES (:a, :u, :act, :f, :t, :n)'
            )->execute([
                ':a' => $adventureId, ':u' => $userId, ':act' => 'report_' . $action,
                ':f' => 'open', ':t' => $state, ':n' => $note !== '' ? $note : null,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, ['report_id' => $reportId, 'state' => $state, 'action' => $action]];
    }

    /**
     * Administrators only: keep a report off the adventure team's queue.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function setPlatformPrivate(int $reportId, bool $isAdmin, bool $private): array
    {
        if (!$isAdmin) return [self::FORBIDDEN, null];
        $u = $this->pdo->prepare(
            'UPDATE content_reports SET platform_private = :p WHERE id = :i'
        );
        $u->execute([':p' => $private ? 1 : 0, ':i' => $reportId]);
        if ($u->rowCount() !== 1) return [self::NOT_FOUND, null];
        return [self::OK, ['report_id' => $reportId, 'platform_private' => $private]];
    }

    /* ────────────────────── Content warnings ───────────────────── */

    /**
     * The published warnings for an adventure.
     *
     * @return list<array{code:string,label:string,details:?string}>
     */
    public function warnings(int $adventureId): array
    {
        $s = $this->pdo->prepare(
            'SELECT code, label, details FROM content_warnings
              WHERE adventure_id = :a ORDER BY position ASC, id ASC'
        );
        $s->execute([':a' => $adventureId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $code = (string) ($r['code'] ?? 'other');
            $out[] = [
                'code'    => in_array($code, self::WARNING_CODES, true) ? $code : 'other',
                'label'   => (string) $r['label'],
                'details' => $r['details'] === null || $r['details'] === '' ? null : (string) $r['details'],
            ];
        }
        return $out;
    }

    /**
     * Replace the warning list. Owners and editors only.
     *
     * @param list<array<string,mixed>> $items
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function setWarnings(string $slug, ?int $userId, bool $isAdmin, array $items): array
    {
        $adv = $this->moderation->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->moderation->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->moderation->canDecide($role)) return [self::FORBIDDEN, null];

        $clean = [];
        foreach ($items as $raw) {
            if (!is_array($raw)) continue;
            $code = (string) ($raw['code'] ?? '');
            if (!in_array($code, self::WARNING_CODES, true)) return [self::INVALID, ['code' => $code]];
            if (isset($clean[$code])) continue;
            $label   = trim(HtmlSanitizer::toPlainText((string) ($raw['label'] ?? '')));
            $details = trim(HtmlSanitizer::toPlainText((string) ($raw['details'] ?? '')));
            if (mb_strlen($details) > 240) return [self::INVALID, ['details' => 'too_long']];
            if ($label === '') $label = self::WARNING_LABELS[$code];
            if ($code === 'other' && $details === '') return [self::INVALID, ['details' => 'required']];
            $clean[$code] = ['label' => mb_substr($label, 0, 80), 'details' => $details];
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM content_warnings WHERE adventure_id = :a')
                      ->execute([':a' => $adventureId]);
            $ins = $this->pdo->prepare(
                'INSERT INTO content_warnings (adventure_id, label, position, code, details)
                 VALUES (:a, :l, :p, :c, :d)'
            );
            $pos = 0;
            foreach ($clean as $code => $w) {
                $ins->execute([
                    ':a' => $adventureId, ':l' => $w['label'], ':p' => $pos++,
                    ':c' => $code, ':d' => $w['details'] !== '' ? $w['details'] : null,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, ['warnings' => $this->warnings($adventureId)]];
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === false) return null;
        if (!is_numeric($v)) return null;
        $i = (int) $v;
        return $i > 0 ? $i : null;
    }

    private function sceneBelongs(int $adventureId, int $sceneId): bool
    {
        return $this->sceneRow($adventureId, $sceneId) !== null;
    }

    /** @return array<string,mixed>|null */
    private function sceneRow(int $adventureId, int $sceneId): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM scenes WHERE id = :i AND adventure_id = :a LIMIT 1');
        $s->execute([':i' => $sceneId, ':a' => $adventureId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** The scene a choice leaves from, when the choice is in this adventure. */
    private function choiceScene(int $adventureId, int $choiceId): ?int
    {
        $s = $this->pdo->prepare(
            'SELECT c.scene_id FROM choices c
               JOIN scenes s ON s.id = c.scene_id
              WHERE c.id = :c AND s.adventure_id = :a LIMIT 1'
        );
        $s->execute([':c' => $choiceId, ':a' => $adventureId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : (int) $r['scene_id'];
    }

    /**
     * A contribution can only be reported when a reader could actually
     * reach it: it belongs to this adventure and is not withdrawn.
     */
    private function submissionAccessible(int $adventureId, int $submissionId): bool
    {
        $s = $this->pdo->prepare(
            'SELECT state FROM branch_submissions
              WHERE id = :i AND adventure_id = :a LIMIT 1'
        );
        $s->execute([':i' => $submissionId, ':a' => $adventureId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r === false) return false;
        return (string) $r['state'] !== 'withdrawn';
    }
}
