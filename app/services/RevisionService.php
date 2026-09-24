<?php
/**
 * RevisionService (v0.24.0) — revision history for published text.
 *
 * Before a published adventure description, published writing
 * guidelines, a published scene's title or body, or a published
 * scene's choice text changes, the old value is stored as a revision.
 * The most recent RETAIN revisions per field are kept.
 *
 * Managers (owner, editor, administrator) can list, compare, and
 * restore. Restoring snapshots the current value first, so a restore
 * is itself a revision and can be undone. Only story text and the
 * editor's display name are ever returned.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class RevisionService
{
    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';
    public const READ_ONLY = 'read_only';

    public const RETAIN = 20;
    public const PUBLIC_STATES = ['published', 'on-hold', 'complete'];

    private const FIELDS = [
        'adventure' => ['description', 'writing_guidelines'],
        'scene'     => ['title', 'body'],
        'choice'    => ['label'],
    ];

    public function __construct(private PDO $pdo, private int $retain = self::RETAIN) {}

    /* ───────────────────────── Recording ───────────────────────── */

    /** Record the adventure's current description / guidelines if published and about to change. */
    public function beforeAdventureChange(int $adventureId, ?int $editorId, array $new): void
    {
        $adv = $this->row('SELECT * FROM adventures WHERE id = :i', $adventureId);
        if ($adv === null || !in_array((string) $adv['state'], self::PUBLIC_STATES, true)) return;
        foreach (['description', 'writing_guidelines'] as $f) {
            if (!array_key_exists($f, $new)) continue;
            $old = (string) ($adv[$f] ?? '');
            if ($old !== (string) $new[$f]) {
                $this->record($adventureId, 'adventure', $adventureId, $f, $old, $editorId);
            }
        }
    }

    /** Record a published scene's title / body before they change. */
    public function beforeSceneChange(int $sceneId, ?int $editorId, array $new): void
    {
        $scene = $this->row('SELECT * FROM scenes WHERE id = :i', $sceneId);
        if ($scene === null || (string) $scene['state'] !== 'published') return;
        foreach (['title', 'body'] as $f) {
            if (!array_key_exists($f, $new)) continue;
            $old = (string) ($scene[$f] ?? '');
            if ($old !== (string) $new[$f]) {
                $this->record((int) $scene['adventure_id'], 'scene', $sceneId, $f, $old, $editorId);
            }
        }
    }

    /** Record a choice label on a published scene before it changes. */
    public function beforeChoiceChange(int $choiceId, ?int $editorId, string $newLabel): void
    {
        $s = $this->pdo->prepare(
            'SELECT c.label, s.state, s.adventure_id FROM choices c
               JOIN scenes s ON s.id = c.scene_id WHERE c.id = :i'
        );
        $s->execute([':i' => $choiceId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r === false || (string) $r['state'] !== 'published') return;
        if ((string) $r['label'] !== $newLabel) {
            $this->record((int) $r['adventure_id'], 'choice', $choiceId, 'label', (string) $r['label'], $editorId);
        }
    }

    private function record(
        int $adventureId, string $type, int $targetId, string $field,
        string $content, ?int $editorId, string $reason = 'edit'
    ): int {
        $this->pdo->prepare(
            'INSERT INTO content_revisions
               (adventure_id, target_type, target_id, field, content, editor_id, reason, created_at)
             VALUES (:a, :t, :i, :f, :c, :e, :r, :at)'
        )->execute([
            ':a' => $adventureId, ':t' => $type, ':i' => $targetId, ':f' => $field,
            ':c' => $content, ':e' => $editorId, ':r' => $reason,
            ':at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->prune($type, $targetId, $field);
        return $id;
    }

    private function prune(string $type, int $targetId, string $field): void
    {
        $this->pdo->prepare(
            'DELETE FROM content_revisions
              WHERE target_type = :t AND target_id = :i AND field = :f
                AND id NOT IN (
                  SELECT id FROM content_revisions
                   WHERE target_type = :t AND target_id = :i AND field = :f
                   ORDER BY id DESC LIMIT ' . max(1, $this->retain) . ')'
        )->execute([':t' => $type, ':i' => $targetId, ':f' => $field]);
    }

    /* ───────────────────────── Reading ─────────────────────────── */

    /** @return array{0:string,1:array<string,mixed>|null} */
    public function list(string $slug, ?int $userId, bool $isAdmin): array
    {
        [$o, $adv] = $this->gate($slug, $userId, $isAdmin, false);
        if ($o !== self::OK) return [$o, null];
        $s = $this->pdo->prepare(
            'SELECT r.id, r.target_type, r.target_id, r.field, r.reason, r.created_at,
                    u.display_name AS editor
               FROM content_revisions r LEFT JOIN users u ON u.id = r.editor_id
              WHERE r.adventure_id = :a ORDER BY r.id DESC'
        );
        $s->execute([':a' => (int) $adv['id']]);
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id' => (int) $r['id'],
                'target_type' => (string) $r['target_type'],
                'target_id' => (int) $r['target_id'],
                'target_label' => $this->targetLabel((string) $r['target_type'], (int) $r['target_id']),
                'field' => (string) $r['field'],
                'reason' => (string) $r['reason'],
                'editor' => $r['editor'] !== null ? (string) $r['editor'] : 'Former member',
                'created_at' => (string) $r['created_at'],
            ];
        }
        return [self::OK, [
            'retain' => $this->retain,
            'read_only' => $this->isReadOnly((string) $adv['state']),
            'revisions' => $rows,
        ]];
    }

    /**
     * Compare a revision with another revision or with the current value.
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function compare(string $slug, int $revisionId, ?int $otherId, ?int $userId, bool $isAdmin): array
    {
        [$o, $adv] = $this->gate($slug, $userId, $isAdmin, false);
        if ($o !== self::OK) return [$o, null];
        $rev = $this->revisionIn((int) $adv['id'], $revisionId);
        if ($rev === null) return [self::NOT_FOUND, null];

        if ($otherId !== null) {
            $other = $this->revisionIn((int) $adv['id'], $otherId);
            if ($other === null || $other['target_type'] !== $rev['target_type']
                || (int) $other['target_id'] !== (int) $rev['target_id']
                || $other['field'] !== $rev['field']) {
                return [self::NOT_FOUND, null];
            }
            $newer = (string) $other['content'];
            $newerLabel = 'Revision #' . (int) $other['id'];
        } else {
            $cur = $this->currentValue((string) $rev['target_type'], (int) $rev['target_id'], (string) $rev['field']);
            if ($cur === null) return [self::NOT_FOUND, null];
            $newer = $cur;
            $newerLabel = 'Current';
        }
        $older = (string) $rev['content'];
        return [self::OK, [
            'revision_id' => (int) $rev['id'],
            'field' => (string) $rev['field'],
            'older_label' => 'Revision #' . (int) $rev['id'],
            'newer_label' => $newerLabel,
            'older' => HtmlSanitizer::toPlainText($older),
            'newer' => HtmlSanitizer::toPlainText($newer),
            'diff' => self::diff($this->paragraphs($older), $this->paragraphs($newer)),
        ]];
    }

    /** @return array{0:string,1:array<string,mixed>|null} */
    public function restore(string $slug, int $revisionId, ?int $userId, bool $isAdmin): array
    {
        [$o, $adv] = $this->gate($slug, $userId, $isAdmin, true);
        if ($o !== self::OK) return [$o, null];
        $rev = $this->revisionIn((int) $adv['id'], $revisionId);
        if ($rev === null) return [self::NOT_FOUND, null];
        $type = (string) $rev['target_type'];
        $id = (int) $rev['target_id'];
        $field = (string) $rev['field'];
        $cur = $this->currentValue($type, $id, $field);
        if ($cur === null) return [self::NOT_FOUND, null];
        $content = (string) $rev['content'];

        $this->pdo->beginTransaction();
        try {
            $newId = $this->record((int) $adv['id'], $type, $id, $field, $cur, $userId, 'restore');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if ($type === 'adventure' && $field === 'description') {
                $this->pdo->prepare('UPDATE adventures SET description = :d, synopsis = :s, updated_at = :n WHERE id = :i')
                    ->execute([':d' => $content, ':s' => mb_substr($content, 0, 240), ':n' => $now, ':i' => $id]);
            } elseif ($type === 'adventure') {
                $html = HtmlSanitizer::sanitize($content);
                $this->pdo->prepare('UPDATE adventures SET writing_guidelines = :g, writing_guidelines_plain = :p, updated_at = :n WHERE id = :i')
                    ->execute([':g' => $html, ':p' => HtmlSanitizer::toPlainText($html), ':n' => $now, ':i' => $id]);
            } elseif ($type === 'scene' && $field === 'title') {
                $this->pdo->prepare('UPDATE scenes SET title = :t, updated_at = :n WHERE id = :i')
                    ->execute([':t' => $content, ':n' => $now, ':i' => $id]);
            } elseif ($type === 'scene') {
                $html = HtmlSanitizer::sanitize($content);
                $this->pdo->prepare('UPDATE scenes SET body = :b, body_plain = :p, updated_at = :n WHERE id = :i')
                    ->execute([':b' => $html, ':p' => HtmlSanitizer::toPlainText($html), ':n' => $now, ':i' => $id]);
            } else {
                $this->pdo->prepare('UPDATE choices SET label = :l WHERE id = :i')
                    ->execute([':l' => $content, ':i' => $id]);
            }
            $this->pdo->prepare(
                'INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state, note)
                 VALUES (:a, :u, :act, NULL, NULL, :n)'
            )->execute([':a' => (int) $adv['id'], ':u' => $userId, ':act' => 'revision_restored',
                ':n' => $type . ' ' . $field . ' #' . $revisionId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return [self::OK, ['restored_from' => $revisionId, 'revision_id' => $newId]];
    }

    /* ───────────────────────── Diff ────────────────────────────── */

    /**
     * Paragraph-level LCS diff.
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{op:string,text:string}>
     */
    public static function diff(array $a, array $b): array
    {
        $n = count($a); $m = count($b);
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }
        $out = []; $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $out[] = ['op' => 'same', 'text' => $a[$i]]; $i++; $j++; }
            elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) { $out[] = ['op' => 'removed', 'text' => $a[$i++]]; }
            else { $out[] = ['op' => 'added', 'text' => $b[$j++]]; }
        }
        while ($i < $n) $out[] = ['op' => 'removed', 'text' => $a[$i++]];
        while ($j < $m) $out[] = ['op' => 'added', 'text' => $b[$j++]];
        return $out;
    }

    /** @return list<string> */
    private function paragraphs(string $html): array
    {
        $split = preg_split('#</(?:p|h2|h3|li|blockquote)>|<br\s*/?>|<hr\s*/?>|\n+#i', $html) ?: [];
        $out = [];
        foreach ($split as $part) {
            $t = trim(HtmlSanitizer::toPlainText($part));
            if ($t !== '') $out[] = $t;
        }
        return array_slice($out, 0, 400);
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    /** @return array{0:string,1:array<string,mixed>|null} */
    private function gate(string $slug, ?int $userId, bool $isAdmin, bool $write): array
    {
        $mod = new ModerationService($this->pdo);
        $adv = $mod->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $role = $mod->roleFor((int) $adv['id'], $userId, $isAdmin);
        if (!$mod->canDecide($role)) return [self::FORBIDDEN, null];
        if ($write && $this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];
        return [self::OK, $adv];
    }

    private function isReadOnly(string $state): bool
    {
        return in_array($state, ['archived', 'suspended'], true);
    }

    /** @return array<string,mixed>|null */
    private function revisionIn(int $adventureId, int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM content_revisions WHERE id = :i AND adventure_id = :a');
        $s->execute([':i' => $id, ':a' => $adventureId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function currentValue(string $type, int $id, string $field): ?string
    {
        if (!in_array($field, self::FIELDS[$type] ?? [], true)) return null;
        $table = ['adventure' => 'adventures', 'scene' => 'scenes', 'choice' => 'choices'][$type];
        $s = $this->pdo->prepare("SELECT {$field} AS v FROM {$table} WHERE id = :i");
        $s->execute([':i' => $id]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function targetLabel(string $type, int $id): string
    {
        if ($type === 'adventure') return 'Adventure details';
        if ($type === 'scene') {
            $t = $this->row('SELECT title FROM scenes WHERE id = :i', $id);
            return 'Scene: ' . ($t['title'] ?? 'removed scene');
        }
        $t = $this->row('SELECT label FROM choices WHERE id = :i', $id);
        return 'Choice: ' . ($t['label'] ?? 'removed choice');
    }

    /** @return array<string,mixed>|null */
    private function row(string $sql, int $id): ?array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute([':i' => $id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
}
