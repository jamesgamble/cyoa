<?php
/**
 * CollaborationService — collaborators, invitations, and ownership
 * transfer (v0.20.0).
 *
 * Roles on an adventure are owner, editor, and reviewer. The owner is
 * `adventures.author_id`, mirrored by an `owner` row in
 * `adventure_collaborators`; editors and reviewers exist only as
 * collaborator rows. Exactly one owner exists at all times, and the
 * only code path that changes who it is is transferOwnership().
 *
 * Invitations are addressed to a registered account's email address.
 * The token is 32 random bytes; only its SHA-256 is stored, it expires,
 * it can be used once, and the owner can revoke it before use. The
 * recipient learns about it twice: a row in their notification inbox
 * and a message on the durable email queue.
 *
 * Ownership transfer additionally requires a *recent* password
 * re-entry on the acting session (see reauthenticatedRecently) and an
 * explicit confirmation flag in the request. The rewrite runs in one
 * transaction: the new owner is promoted, their collaborator row
 * becomes `owner`, and the previous owner either stays as an editor or
 * leaves entirely — never both, never neither.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class CollaborationService
{
    public const OK           = 'ok';
    public const FORBIDDEN    = 'forbidden';
    public const NOT_FOUND    = 'not_found';
    public const INVALID      = 'invalid';
    public const CONFLICT     = 'conflict';
    public const REAUTH       = 'reauthentication_required';
    public const UNCONFIRMED  = 'confirmation_required';
    public const EXPIRED      = 'expired';

    /** Roles an invitation may carry. Ownership is never invited. */
    public const INVITABLE_ROLES = ['editor', 'reviewer'];

    /** Invitations expire after seven days. */
    public const INVITE_TTL_SECONDS = 60 * 60 * 24 * 7;

    /** A password re-entry counts as "recent" for five minutes. */
    public const REAUTH_WINDOW_SECONDS = 300;

    public const MESSAGE_MAX = 500;

    private PDO $pdo;
    private EmailQueueRepository $queue;
    private SettingsRepository $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->queue    = new EmailQueueRepository($pdo);
        $this->settings = new SettingsRepository($pdo);
    }

    /* ─────────────────────────── Roles ─────────────────────────── */

    /** @return array<string,mixed>|null */
    public function adventureBySlug(string $slug): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * Server-derived role. A role named in a request body is never
     * trusted; every entry point calls this instead.
     */
    public function roleFor(int $adventureId, ?int $userId, bool $isAdmin = false): ?string
    {
        if ($isAdmin) return 'administrator';
        if ($userId === null) return null;

        $s = $this->pdo->prepare('SELECT author_id FROM adventures WHERE id = :a LIMIT 1');
        $s->execute([':a' => $adventureId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ((int) $row['author_id'] === $userId) return 'owner';

        $c = $this->pdo->prepare(
            'SELECT role FROM adventure_collaborators
              WHERE adventure_id = :a AND user_id = :u LIMIT 1'
        );
        $c->execute([':a' => $adventureId, ':u' => $userId]);
        $r = $c->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : (string) $r['role'];
    }

    /** Only the owner (or an administrator) manages the roster. */
    private function canManage(?string $role): bool
    {
        return $role === 'owner' || $role === 'administrator';
    }

    /* ────────────────────── Reauthentication ───────────────────── */

    /**
     * True when the given session re-entered its password inside the
     * reauthentication window. A session that has never re-entered
     * falls back to its login timestamp, which is the same guarantee.
     */
    public function reauthenticatedRecently(?int $sessionId, ?int $withinSeconds = null): bool
    {
        if ($sessionId === null || $sessionId <= 0) return false;
        $window = $withinSeconds ?? self::REAUTH_WINDOW_SECONDS;
        $s = $this->pdo->prepare(
            'SELECT COALESCE(reauthenticated_at, created_at) AS at, revoked_at, expires_at
               FROM sessions WHERE id = :id LIMIT 1'
        );
        $s->execute([':id' => $sessionId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return false;
        if ($row['revoked_at'] !== null) return false;
        if ((string) $row['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) return false;
        $at = strtotime((string) $row['at']);
        if ($at === false) return false;
        return (time() - $at) <= $window;
    }

    /** Stamp a successful password re-entry onto the session. */
    public function markReauthenticated(int $sessionId): void
    {
        $this->pdo->prepare(
            "UPDATE sessions
                SET reauthenticated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :id AND revoked_at IS NULL"
        )->execute([':id' => $sessionId]);
    }

    /**
     * Verify the caller's password and, on success, stamp the session.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function reauthenticate(int $userId, ?int $sessionId, string $password): array
    {
        if ($sessionId === null || $sessionId <= 0) return [self::INVALID, null];
        $s = $this->pdo->prepare('SELECT id, password_hash FROM users WHERE id = :u LIMIT 1');
        $s->execute([':u' => $userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return [self::NOT_FOUND, null];

        $sess = $this->pdo->prepare('SELECT user_id FROM sessions WHERE id = :id LIMIT 1');
        $sess->execute([':id' => $sessionId]);
        $sr = $sess->fetch(PDO::FETCH_ASSOC);
        // A session belonging to somebody else can never be stamped.
        if ($sr === false || (int) $sr['user_id'] !== $userId) return [self::FORBIDDEN, null];

        if (!PasswordHasher::verify($password, (string) $row['password_hash'])) {
            return [self::INVALID, null];
        }
        $this->markReauthenticated($sessionId);
        return [self::OK, ['reauthenticated' => true, 'window_seconds' => self::REAUTH_WINDOW_SECONDS]];
    }

    /* ───────────────────────── Roster ──────────────────────────── */

    /**
     * The collaborators section payload: the owner, every editor and
     * reviewer, and every invitation with its derived state.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function roster(string $slug, ?int $actorId, bool $isAdmin): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $actorId, $isAdmin);
        if ($role === null) return [self::FORBIDDEN, null];

        $members = $this->pdo->prepare(
            'SELECT c.user_id, c.role, c.created_at,
                    u.username, u.display_name
               FROM adventure_collaborators c
               JOIN users u ON u.id = c.user_id
              WHERE c.adventure_id = :a
              ORDER BY CASE c.role WHEN \'owner\' THEN 0 WHEN \'editor\' THEN 1 ELSE 2 END,
                       u.username ASC'
        );
        $members->execute([':a' => $adventureId]);

        $invites = [];
        if ($this->canManage($role)) {
            $st = $this->pdo->prepare(
                'SELECT i.id, i.email, i.role, i.expires_at, i.created_at,
                        i.accepted_at, i.declined_at, i.revoked_at,
                        u.username AS invitee_username
                   FROM adventure_invitations i
              LEFT JOIN users u ON u.id = i.invitee_id
                  WHERE i.adventure_id = :a
                  ORDER BY i.created_at DESC'
            );
            $st->execute([':a' => $adventureId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['state'] = self::invitationState($row);
                $invites[] = $row;
            }
        }

        return [self::OK, [
            'adventure'     => ['slug' => (string) $adv['slug'], 'title' => (string) $adv['title']],
            'viewer_role'   => $role,
            'can_manage'    => $this->canManage($role),
            'owner_id'      => (int) $adv['author_id'],
            'collaborators' => $members->fetchAll(PDO::FETCH_ASSOC),
            'invitations'   => $invites,
            'invitable_roles' => self::INVITABLE_ROLES,
        ]];
    }

    /** Derive the visible state of an invitation row. */
    public static function invitationState(array $row): string
    {
        if ($row['revoked_at']  !== null) return 'revoked';
        if ($row['accepted_at'] !== null) return 'accepted';
        if ($row['declined_at'] !== null) return 'declined';
        if ((string) $row['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) return 'expired';
        return 'pending';
    }

    /* ─────────────────────── Invitations ───────────────────────── */

    /**
     * Invite a registered user by email. Returns the roster; the raw
     * token is never echoed back to the inviter — it reaches the
     * recipient by email and inbox only.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function invite(
        string $slug,
        ?int $actorId,
        bool $isAdmin,
        string $email,
        string $role,
        string $message = ''
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        if (!$this->canManage($this->roleFor($adventureId, $actorId, $isAdmin))) {
            return [self::FORBIDDEN, null];
        }
        if (!in_array($role, self::INVITABLE_ROLES, true)) {
            return [self::INVALID, ['reason' => 'role']];
        }
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [self::INVALID, ['reason' => 'email']];
        }
        $message = trim(HtmlSanitizer::toPlainText($message));
        if (mb_strlen($message) > self::MESSAGE_MAX) return [self::INVALID, ['reason' => 'message']];

        $u = $this->pdo->prepare(
            'SELECT id, email, display_name, username FROM users WHERE lower(email) = :e LIMIT 1'
        );
        $u->execute([':e' => $email]);
        $invitee = $u->fetch(PDO::FETCH_ASSOC);
        // Invitations go to registered accounts only; we do not create
        // shadow accounts and we do not leak who is registered beyond
        // the owner's own roster.
        if ($invitee === false) return [self::NOT_FOUND, ['reason' => 'no_account']];

        $inviteeId = (int) $invitee['id'];
        if ($inviteeId === (int) $adv['author_id']) return [self::CONFLICT, ['reason' => 'owner']];

        $existing = $this->pdo->prepare(
            'SELECT role FROM adventure_collaborators
              WHERE adventure_id = :a AND user_id = :u LIMIT 1'
        );
        $existing->execute([':a' => $adventureId, ':u' => $inviteeId]);
        if ($existing->fetch() !== false) return [self::CONFLICT, ['reason' => 'already_member']];

        // One live invitation per person per adventure: re-inviting
        // supersedes the previous token rather than stacking tokens.
        $this->pdo->prepare(
            "UPDATE adventure_invitations
                SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE adventure_id = :a AND invitee_id = :u
                AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL"
        )->execute([':a' => $adventureId, ':u' => $inviteeId]);

        $raw     = TokenRepository::generate();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + self::INVITE_TTL_SECONDS);
        $this->pdo->prepare(
            'INSERT INTO adventure_invitations
                (adventure_id, email, invitee_id, role, token_hash, invited_by, message, expires_at)
             VALUES (:a, :e, :u, :r, :h, :by, :m, :x)'
        )->execute([
            ':a' => $adventureId, ':e' => $email, ':u' => $inviteeId, ':r' => $role,
            ':h' => TokenRepository::hash($raw), ':by' => $actorId,
            ':m' => $message === '' ? null : $message, ':x' => $expires,
        ]);

        $inviter = $this->userLabel($actorId);
        $url     = $this->inviteUrl($raw);

        $this->notify(
            $inviteeId,
            'invitation',
            'Invitation to help with ' . (string) $adv['title'],
            $inviter . ' invited you to join as ' . $role . '.',
            $url,
            $adventureId
        );
        if ($this->wantsEmail($inviteeId, 'collaborator_invitation')) $this->queue->enqueue('adventure_invitation', (string) $invitee['email'], (string) $invitee['display_name'], [
            'display_name'    => (string) $invitee['display_name'],
            'inviter_name'    => $inviter,
            'adventure_title' => (string) $adv['title'],
            'role'            => $role,
            'invite_url'      => $url,
            'expires_at'      => $expires,
        ]);
        $this->logActivity($adventureId, $actorId, 'invitation_sent', null, $role, $email);

        return $this->roster($slug, $actorId, $isAdmin);
    }

    /**
     * Revoke a pending invitation. Revocation is idempotent for an
     * already-terminal invitation only in the sense that it reports a
     * conflict rather than silently "un-accepting" anything.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function revokeInvitation(string $slug, ?int $actorId, bool $isAdmin, int $invitationId): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        if (!$this->canManage($this->roleFor($adventureId, $actorId, $isAdmin))) {
            return [self::FORBIDDEN, null];
        }

        $s = $this->pdo->prepare(
            'SELECT * FROM adventure_invitations
              WHERE id = :i AND adventure_id = :a LIMIT 1'
        );
        $s->execute([':i' => $invitationId, ':a' => $adventureId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return [self::NOT_FOUND, null];
        if (self::invitationState($row) !== 'pending') return [self::CONFLICT, ['state' => self::invitationState($row)]];

        $this->pdo->prepare(
            "UPDATE adventure_invitations
                SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :i AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL"
        )->execute([':i' => $invitationId]);

        $this->logActivity($adventureId, $actorId, 'invitation_revoked', null, (string) $row['role'], (string) $row['email']);
        return $this->roster($slug, $actorId, $isAdmin);
    }

    /**
     * Look up an invitation by raw token for the signed-in recipient,
     * without consuming it. Used to render the accept/decline screen.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function invitationByToken(string $rawToken, ?int $userId): array
    {
        $row = $this->findInvitation($rawToken);
        if ($row === null) return [self::NOT_FOUND, null];
        $state = self::invitationState($row);
        if ($state === 'expired') return [self::EXPIRED, null];
        if ($state !== 'pending') return [self::CONFLICT, ['state' => $state]];
        if ($userId !== null && (int) $row['invitee_id'] !== $userId) return [self::FORBIDDEN, null];

        return [self::OK, [
            'id'              => (int) $row['id'],
            'role'            => (string) $row['role'],
            'message'         => $row['message'],
            'expires_at'      => (string) $row['expires_at'],
            'adventure_slug'  => (string) $row['slug'],
            'adventure_title' => (string) $row['title'],
            'invited_by'      => $this->userLabel($row['invited_by'] === null ? null : (int) $row['invited_by']),
        ]];
    }

    /**
     * Accept an invitation. Single-use: the same transaction that
     * writes the collaborator row consumes the token, so a replayed
     * link can never grant a second role.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function acceptInvitation(string $rawToken, ?int $userId): array
    {
        if ($userId === null) return [self::FORBIDDEN, null];
        $row = $this->findInvitation($rawToken);
        if ($row === null) return [self::NOT_FOUND, null];
        if ((int) $row['invitee_id'] !== $userId) return [self::FORBIDDEN, null];
        $state = self::invitationState($row);
        if ($state === 'expired') return [self::EXPIRED, null];
        if ($state !== 'pending') return [self::CONFLICT, ['state' => $state]];

        $adventureId = (int) $row['adventure_id'];
        $role        = (string) $row['role'];

        $this->pdo->beginTransaction();
        try {
            // Consume first and check the row count: two concurrent
            // accepts race here and exactly one wins.
            $consume = $this->pdo->prepare(
                "UPDATE adventure_invitations
                    SET accepted_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE id = :i AND accepted_at IS NULL
                    AND declined_at IS NULL AND revoked_at IS NULL
                    AND expires_at > strftime('%Y-%m-%dT%H:%M:%fZ', 'now')"
            );
            $consume->execute([':i' => (int) $row['id']]);
            if ($consume->rowCount() !== 1) {
                $this->pdo->rollBack();
                return [self::CONFLICT, ['state' => 'used']];
            }
            // Never overwrite an owner row through an invitation.
            $this->pdo->prepare(
                'INSERT INTO adventure_collaborators (adventure_id, user_id, role)
                 VALUES (:a, :u, :r)
                 ON CONFLICT(adventure_id, user_id)
                 DO UPDATE SET role = excluded.role WHERE adventure_collaborators.role <> \'owner\''
            )->execute([':a' => $adventureId, ':u' => $userId, ':r' => $role]);

            $this->logActivity($adventureId, $userId, 'invitation_accepted', null, $role, null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        $ownerId = (int) $row['author_id'];
        $this->notify(
            $ownerId,
            'invitation_accepted',
            $this->userLabel($userId) . ' joined ' . (string) $row['title'],
            'They accepted your invitation as ' . $role . '.',
            '/adventure/' . (string) $row['slug'] . '/manage?section=collaborators',
            $adventureId
        );

        return [self::OK, [
            'adventure_slug'  => (string) $row['slug'],
            'adventure_title' => (string) $row['title'],
            'role'            => $role,
        ]];
    }

    /**
     * Decline an invitation. Also single-use.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function declineInvitation(string $rawToken, ?int $userId): array
    {
        if ($userId === null) return [self::FORBIDDEN, null];
        $row = $this->findInvitation($rawToken);
        if ($row === null) return [self::NOT_FOUND, null];
        if ((int) $row['invitee_id'] !== $userId) return [self::FORBIDDEN, null];
        $state = self::invitationState($row);
        if ($state === 'expired') return [self::EXPIRED, null];
        if ($state !== 'pending') return [self::CONFLICT, ['state' => $state]];

        $st = $this->pdo->prepare(
            "UPDATE adventure_invitations
                SET declined_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :i AND accepted_at IS NULL
                AND declined_at IS NULL AND revoked_at IS NULL"
        );
        $st->execute([':i' => (int) $row['id']]);
        if ($st->rowCount() !== 1) return [self::CONFLICT, ['state' => 'used']];

        $this->logActivity((int) $row['adventure_id'], $userId, 'invitation_declined', null, (string) $row['role'], null);
        $this->notify(
            (int) $row['author_id'],
            'invitation_declined',
            $this->userLabel($userId) . ' declined your invitation',
            'They will not be joining ' . (string) $row['title'] . '.',
            '/adventure/' . (string) $row['slug'] . '/manage?section=collaborators',
            (int) $row['adventure_id']
        );
        return [self::OK, ['adventure_slug' => (string) $row['slug']]];
    }

    /* ─────────────────── Roles and removals ────────────────────── */

    /**
     * Change an editor's or reviewer's role, or remove them by passing
     * a null role. The owner row is untouchable here: ownership only
     * moves through transferOwnership().
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function setRole(string $slug, ?int $actorId, bool $isAdmin, int $targetUserId, ?string $newRole): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        if (!$this->canManage($this->roleFor($adventureId, $actorId, $isAdmin))) {
            return [self::FORBIDDEN, null];
        }
        if ($targetUserId === (int) $adv['author_id']) return [self::CONFLICT, ['reason' => 'owner']];
        if ($newRole !== null && !in_array($newRole, self::INVITABLE_ROLES, true)) {
            return [self::INVALID, ['reason' => 'role']];
        }

        $cur = $this->pdo->prepare(
            'SELECT role FROM adventure_collaborators
              WHERE adventure_id = :a AND user_id = :u LIMIT 1'
        );
        $cur->execute([':a' => $adventureId, ':u' => $targetUserId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return [self::NOT_FOUND, null];
        if ((string) $row['role'] === 'owner') return [self::CONFLICT, ['reason' => 'owner']];

        if ($newRole === null) {
            $this->pdo->prepare(
                'DELETE FROM adventure_collaborators
                  WHERE adventure_id = :a AND user_id = :u AND role <> \'owner\''
            )->execute([':a' => $adventureId, ':u' => $targetUserId]);
            $this->logActivity($adventureId, $actorId, 'collaborator_removed', (string) $row['role'], null, null);
            $this->notify(
                $targetUserId,
                'role_removed',
                'You were removed from ' . (string) $adv['title'],
                'You no longer have a role on this adventure.',
                '/adventure/' . (string) $adv['slug'],
                $adventureId
            );
        } else {
            $this->pdo->prepare(
                'UPDATE adventure_collaborators SET role = :r
                  WHERE adventure_id = :a AND user_id = :u AND role <> \'owner\''
            )->execute([':a' => $adventureId, ':u' => $targetUserId, ':r' => $newRole]);
            $this->logActivity($adventureId, $actorId, 'collaborator_role_changed', (string) $row['role'], $newRole, null);
            $this->notify(
                $targetUserId,
                'role_changed',
                'Your role on ' . (string) $adv['title'] . ' changed',
                'You are now ' . ($newRole === 'editor' ? 'an editor' : 'a reviewer') . '.',
                '/adventure/' . (string) $adv['slug'] . '/manage',
                $adventureId
            );
        }

        return $this->roster($slug, $actorId, $isAdmin);
    }

    /* ─────────────────── Ownership transfer ────────────────────── */

    /**
     * Hand the adventure to another team member.
     *
     * Requires: owner (or administrator) role, a recent password
     * re-entry on the acting session, an explicit confirm flag, and a
     * target who is already an editor or reviewer.
     *
     * The write is one transaction that leaves exactly one owner: the
     * new owner is written to adventures.author_id and to their
     * collaborator row, and the previous owner becomes an editor or,
     * if they asked to leave, is removed entirely.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function transferOwnership(
        string $slug,
        ?int $actorId,
        bool $isAdmin,
        ?int $sessionId,
        int $targetUserId,
        bool $confirmed,
        bool $stayAsEditor = true
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $currentOwnerId = (int) $adv['author_id'];
        if (!$this->canManage($this->roleFor($adventureId, $actorId, $isAdmin))) {
            return [self::FORBIDDEN, null];
        }
        if (!$confirmed) return [self::UNCONFIRMED, null];
        if (!$this->reauthenticatedRecently($sessionId)) return [self::REAUTH, null];
        if ($targetUserId === $currentOwnerId) return [self::CONFLICT, ['reason' => 'already_owner']];

        $t = $this->pdo->prepare(
            'SELECT c.role, u.id, u.email, u.display_name
               FROM adventure_collaborators c
               JOIN users u ON u.id = c.user_id
              WHERE c.adventure_id = :a AND c.user_id = :u LIMIT 1'
        );
        $t->execute([':a' => $adventureId, ':u' => $targetUserId]);
        $target = $t->fetch(PDO::FETCH_ASSOC);
        // Ownership can only go to somebody already on the team, so
        // the new owner has always accepted an invitation first.
        if ($target === false) return [self::INVALID, ['reason' => 'not_a_collaborator']];

        $this->pdo->beginTransaction();
        try {
            // Re-read the owner inside the transaction: a concurrent
            // transfer that landed first must lose this one.
            $chk = $this->pdo->prepare('SELECT author_id FROM adventures WHERE id = :a LIMIT 1');
            $chk->execute([':a' => $adventureId]);
            $now = $chk->fetch(PDO::FETCH_ASSOC);
            if ($now === false || (int) $now['author_id'] !== $currentOwnerId) {
                $this->pdo->rollBack();
                return [self::CONFLICT, ['reason' => 'owner_changed']];
            }

            $this->pdo->prepare('UPDATE adventures SET author_id = :u WHERE id = :a')
                 ->execute([':u' => $targetUserId, ':a' => $adventureId]);

            $this->pdo->prepare(
                'INSERT INTO adventure_collaborators (adventure_id, user_id, role)
                 VALUES (:a, :u, \'owner\')
                 ON CONFLICT(adventure_id, user_id) DO UPDATE SET role = \'owner\''
            )->execute([':a' => $adventureId, ':u' => $targetUserId]);

            if ($stayAsEditor) {
                $this->pdo->prepare(
                    'INSERT INTO adventure_collaborators (adventure_id, user_id, role)
                     VALUES (:a, :u, \'editor\')
                     ON CONFLICT(adventure_id, user_id) DO UPDATE SET role = \'editor\''
                )->execute([':a' => $adventureId, ':u' => $currentOwnerId]);
            } else {
                $this->pdo->prepare(
                    'DELETE FROM adventure_collaborators WHERE adventure_id = :a AND user_id = :u'
                )->execute([':a' => $adventureId, ':u' => $currentOwnerId]);
            }

            // Any invitation still outstanding was issued under the
            // old owner's authority; retire them.
            $this->pdo->prepare(
                "UPDATE adventure_invitations
                    SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE adventure_id = :a AND invitee_id = :u
                    AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL"
            )->execute([':a' => $adventureId, ':u' => $targetUserId]);

            $this->logActivity(
                $adventureId, $actorId, 'ownership_transferred',
                (string) $currentOwnerId, (string) $targetUserId,
                $stayAsEditor ? 'previous owner stays as editor' : 'previous owner left the team'
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        $title    = (string) $adv['title'];
        $previous = $this->userLabel($currentOwnerId);
        $url      = '/adventure/' . (string) $adv['slug'] . '/manage';

        $this->notify($targetUserId, 'ownership_received',
            'You now own ' . $title,
            $previous . ' transferred ownership to you.', $url, $adventureId);
        $this->notify($currentOwnerId, 'ownership_transferred',
            'You transferred ' . $title,
            $stayAsEditor
                ? 'You remain an editor on this adventure.'
                : 'You have left the team for this adventure.',
            $url, $adventureId);

        if ($this->wantsEmail($targetUserId, 'ownership_transfer')) $this->queue->enqueue('ownership_transferred', (string) $target['email'], (string) $target['display_name'], [
            'display_name'    => (string) $target['display_name'],
            'previous_owner'  => $previous,
            'adventure_title' => $title,
            'adventure_url'   => $this->canonicalUrl() . $url,
        ]);

        [$o, $d] = $this->roster($slug, $actorId, $isAdmin);
        return [self::OK, [
            'owner_id'        => $targetUserId,
            'previous_owner'  => ['id' => $currentOwnerId, 'role' => $stayAsEditor ? 'editor' : null],
            'roster'          => $o === self::OK ? $d : null,
        ]];
    }

    /* ────────────────────── Notifications ──────────────────────── */

    /** @return array<int,array<string,mixed>> */
    public function notifications(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $s = $this->pdo->prepare(
            'SELECT id, kind, title, body, url, adventure_id, read_at, created_at
               FROM notifications WHERE user_id = :u
              ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $s->execute([':u' => $userId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unreadCount(int $userId): int
    {
        $s = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = :u AND read_at IS NULL'
        );
        $s->execute([':u' => $userId]);
        return (int) ($s->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    }

    /** Mark one notification (or all, when $id is null) as read. */
    public function markRead(int $userId, ?int $id): void
    {
        if ($id === null) {
            $this->pdo->prepare(
                "UPDATE notifications SET read_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE user_id = :u AND read_at IS NULL"
            )->execute([':u' => $userId]);
            return;
        }
        $this->pdo->prepare(
            "UPDATE notifications SET read_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :i AND user_id = :u AND read_at IS NULL"
        )->execute([':i' => $id, ':u' => $userId]);
    }

    /**
     * Collaboration notices go through NotificationService (v0.22.0)
     * so they land in the same inbox and honour the same preference
     * table. The email side is suppressed here because invitations and
     * transfers each have their own dedicated template, enqueued by the
     * caller once the recipient's preference has been checked.
     */
    public function notify(
        int $userId,
        string $kind,
        string $title,
        string $body,
        ?string $url,
        ?int $adventureId
    ): void {
        (new NotificationService($this->pdo))->emit(
            $userId, $kind, $title, $body, $url, $adventureId, ['email' => false]
        );
    }

    /** Does this recipient still want collaboration email? */
    public function wantsEmail(int $userId, string $kind): bool
    {
        return (new NotificationService($this->pdo))->emailEnabled($userId, $kind);
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    /**
     * Find an invitation by raw token, joined to its adventure. The
     * token is never compared in plaintext — only its hash is stored.
     *
     * @return array<string,mixed>|null
     */
    private function findInvitation(string $rawToken): ?array
    {
        if (trim($rawToken) === '') return null;
        $s = $this->pdo->prepare(
            'SELECT i.*, a.slug, a.title, a.author_id
               FROM adventure_invitations i
               JOIN adventures a ON a.id = i.adventure_id
              WHERE i.token_hash = :h LIMIT 1'
        );
        $s->execute([':h' => TokenRepository::hash($rawToken)]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function userLabel(?int $userId): string
    {
        if ($userId === null) return 'An administrator';
        $s = $this->pdo->prepare('SELECT display_name, username FROM users WHERE id = :u LIMIT 1');
        $s->execute([':u' => $userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return 'A member';
        $name = trim((string) $row['display_name']);
        return $name !== '' ? $name : (string) $row['username'];
    }

    public function canonicalUrl(): string
    {
        $v = $this->settings->get('canonical_url', bp_config()['app']['url']);
        return rtrim((string) $v, '/');
    }

    public function inviteUrl(string $rawToken): string
    {
        return $this->canonicalUrl() . '/invitations/' . rawurlencode($rawToken);
    }

    private function logActivity(
        int $adventureId,
        ?int $userId,
        string $action,
        ?string $from,
        ?string $to,
        ?string $note
    ): void {
        $this->pdo->prepare(
            'INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state, note)
             VALUES (:a, :u, :act, :f, :t, :n)'
        )->execute([
            ':a' => $adventureId, ':u' => $userId, ':act' => $action,
            ':f' => $from, ':t' => $to, ':n' => $note,
        ]);
    }
}
