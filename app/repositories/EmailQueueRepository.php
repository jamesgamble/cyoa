<?php
/**
 * EmailQueueRepository — durable outbox operations.
 *
 * The worker uses claimBatch() to atomically flip a bounded set of
 * pending rows to 'sending' inside a short transaction. markSent()
 * and markFailedOrRetry() close out each row afterwards.
 *
 * recoverStale() catches messages that were claimed by a worker that
 * crashed or was killed before it could report an outcome: any row
 * stuck in 'sending' beyond the stale window is returned to
 * 'pending' with its attempts count incremented.
 *
 * All timestamps are ISO-8601 UTC ("...Z") so string comparison
 * matches chronological order.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class EmailQueueRepository
{
    public const STATUSES = ['pending','sending','sent','failed','cancelled'];

    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Enqueue one message. Returns the row id.
     *
     * @param array<string,scalar> $data
     */
    public function enqueue(
        string $templateKey,
        string $toEmail,
        string $toName,
        array $data,
        ?string $notBefore = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_queue
                (template_key, to_email, to_name, data_json, next_attempt_at)
             VALUES
                (:k, :to, :name, :data,
                 COALESCE(:nb, strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')))'
        );
        $stmt->execute([
            ':k'    => $templateKey,
            ':to'   => $toEmail,
            ':name' => $toName,
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':nb'   => $notBefore,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Move up to $limit ready-to-send rows to 'sending' and return
     * them for the worker. The transaction is short so readers stay
     * unblocked.
     *
     * @return array<int, array<string,mixed>>
     */
    public function claimBatch(int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $now = self::isoNow();

        $this->pdo->beginTransaction();
        try {
            $sel = $this->pdo->prepare(
                'SELECT id FROM email_queue
                 WHERE status = \'pending\' AND next_attempt_at <= :now
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT ' . $limit
            );
            $sel->execute([':now' => $now]);
            $ids = array_map(static fn($r) => (int) $r['id'], $sel->fetchAll());
            if ($ids === []) {
                $this->pdo->commit();
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->pdo->prepare(
                'UPDATE email_queue
                    SET status = \'sending\',
                        claimed_at = ?,
                        updated_at = ?
                  WHERE id IN (' . $placeholders . ') AND status = \'pending\''
            );
            $upd->execute(array_merge([$now, $now], $ids));

            $rows = $this->pdo->prepare(
                'SELECT * FROM email_queue WHERE id IN (' . $placeholders . ')'
            );
            $rows->execute($ids);
            $out = $rows->fetchAll();
            $this->pdo->commit();
            return $out;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function markSent(int $id): void
    {
        $now = self::isoNow();
        $this->pdo->prepare(
            'UPDATE email_queue
                SET status = \'sent\', sent_at = :s, updated_at = :s,
                    last_error = NULL
              WHERE id = :id'
        )->execute([':s' => $now, ':id' => $id]);
    }

    /**
     * Record a delivery failure. When attempts + 1 >= retryLimit the
     * row is moved to 'failed'; otherwise it is returned to
     * 'pending' with an exponential-plus-jitter delay so the next
     * worker run picks it up later.
     */
    public function markFailedOrRetry(int $id, int $currentAttempts, int $retryLimit, string $redactedError): string
    {
        $nextAttempts = $currentAttempts + 1;
        $now = self::isoNow();
        if ($nextAttempts >= $retryLimit) {
            $this->pdo->prepare(
                'UPDATE email_queue
                    SET status = \'failed\', attempts = :a, last_error = :err,
                        updated_at = :now
                  WHERE id = :id'
            )->execute([':a' => $nextAttempts, ':err' => $redactedError, ':now' => $now, ':id' => $id]);
            return 'failed';
        }
        $delaySec = self::backoffSeconds($nextAttempts);
        $next = gmdate('Y-m-d\TH:i:s\Z', time() + $delaySec);
        $this->pdo->prepare(
            'UPDATE email_queue
                SET status = \'pending\', attempts = :a, last_error = :err,
                    next_attempt_at = :next, claimed_at = NULL, updated_at = :now
              WHERE id = :id'
        )->execute([
            ':a' => $nextAttempts, ':err' => $redactedError,
            ':next' => $next, ':now' => $now, ':id' => $id,
        ]);
        return 'retry';
    }

    /**
     * Return any 'sending' row whose claimed_at is older than the
     * stale window to the 'pending' state so the next worker run can
     * try again. Returns the count moved.
     */
    public function recoverStale(int $staleSeconds = 300): int
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - max(30, $staleSeconds));
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
                SET status = \'pending\',
                    attempts = attempts + 1,
                    last_error = \'worker_did_not_report\',
                    claimed_at = NULL,
                    updated_at = strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')
              WHERE status = \'sending\' AND claimed_at IS NOT NULL
                AND claimed_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /** Cancel one pending row. Returns true if it was cancelled. */
    public function cancel(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE email_queue
                SET status = \'cancelled\',
                    updated_at = strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')
              WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return the most recent messages for the admin queue view. The
     * `data_json` column is redacted from the API projection because
     * template placeholders may include personal data.
     *
     * @return array<int, array<string,mixed>>
     */
    public function recent(int $limit = 100, ?string $status = null): array
    {
        $sql = 'SELECT id, template_key, to_email, status, attempts,
                       last_error, next_attempt_at, sent_at, created_at
                 FROM email_queue';
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE status = :s';
            $params[':s'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $out = ['pending'=>0,'sending'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
        foreach ($this->pdo->query('SELECT status, COUNT(*) c FROM email_queue GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public static function backoffSeconds(int $attempts): int
    {
        // 60, 120, 240, 480, ... capped at 1 hour, plus 0-30s jitter.
        $base = min(3600, 60 * (2 ** max(0, $attempts - 1)));
        return $base + random_int(0, 30);
    }

    private static function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
