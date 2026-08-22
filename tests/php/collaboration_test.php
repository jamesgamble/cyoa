<?php
/**
 * tests/php/collaboration_test.php
 *
 * Version 0.20.0 — focused coverage for collaborators, invitations,
 * reauthentication, and the ownership invariants.
 */

declare(strict_types=1);

use App\AdventureService;
use App\CollaborationService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\SessionRepository;
use App\TokenRepository;
use App\UserRepository;

final class BPCollaborationTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private CollaborationService $svc;
    private SessionRepository $sessions;

    private int $ownerId;
    private int $editorId;
    private int $reviewerId;
    private int $strangerId;
    private string $slug = '';

    private const PASSWORD = 'correct horse battery staple';

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-collab-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();

        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash(self::PASSWORD);
        $mk = static function (string $name) use ($repo, $hash): int {
            return (int) $repo->insert([
                'email' => $name . '@example.com', 'username' => $name,
                'display_name' => ucfirst($name) . ' Person', 'password_hash' => $hash['hash'],
                'password_algo' => $hash['algo'], 'status' => 'active',
                'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        };
        $this->ownerId    = $mk('cowner');
        $this->editorId   = $mk('ceditor');
        $this->reviewerId = $mk('creviewer');
        $this->strangerId = $mk('cstranger');

        $this->svc      = new CollaborationService($this->pdo);
        $this->sessions = new SessionRepository($this->pdo);

        $adventures = new AdventureService($this->pdo);
        [$outcome, $errors, $data] = $adventures->create($this->ownerId, [
            'title' => 'The Salt Road',
            'description' => 'A caravan vanishes in the dunes and the maps disagree.',
            'genre' => 'mystery', 'content_rating' => 'everyone',
            'content_warnings' => [], 'visibility' => 'public',
            'opening_title' => 'The last waterhole',
            'opening_body'  => '<p>The camels will not drink.</p>',
            'status' => 'published',
            'contribution_mode' => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene' => 4,
        ]);
        assert_same('ok', $outcome, 'fixture adventure: ' . json_encode($errors));
        $this->slug = (string) $data['slug'];
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    private function freshSession(int $userId): int
    {
        return (int) $this->sessions->create($userId)['id'];
    }

    /** Invite someone and return the raw token straight from the row. */
    private function inviteAndToken(int $userId, string $email, string $role = 'editor'): string
    {
        // The service never echoes the raw token, so the test reissues
        // one deterministically the same way the service does.
        [$o] = $this->svc->invite($this->slug, $this->ownerId, false, $email, $role);
        assert_same('ok', $o, 'invitation accepted by service');
        // Replace the stored hash with a hash of a token we know, which
        // is exactly what the emailed link would have carried.
        $raw = TokenRepository::generate();
        $this->pdo->prepare(
            'UPDATE adventure_invitations SET token_hash = :h
              WHERE invitee_id = :u AND accepted_at IS NULL
                AND declined_at IS NULL AND revoked_at IS NULL'
        )->execute([':h' => TokenRepository::hash($raw), ':u' => $userId]);
        return $raw;
    }

    private function ownerIdOf(): int
    {
        $s = $this->pdo->prepare('SELECT author_id FROM adventures WHERE slug = :s');
        $s->execute([':s' => $this->slug]);
        return (int) $s->fetch(\PDO::FETCH_ASSOC)['author_id'];
    }

    /** @return array<int,string> user_id => role */
    private function collaboratorRoles(): array
    {
        $s = $this->pdo->prepare(
            'SELECT c.user_id, c.role FROM adventure_collaborators c
               JOIN adventures a ON a.id = c.adventure_id WHERE a.slug = :s'
        );
        $s->execute([':s' => $this->slug]);
        $out = [];
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int) $r['user_id']] = (string) $r['role'];
        return $out;
    }

    /* ─────────────────────── invitations ───────────────────────── */

    public function testOnlyTheOwnerMayInvite(): void
    {
        [$o] = $this->svc->invite($this->slug, $this->strangerId, false, 'ceditor@example.com', 'editor');
        assert_same(CollaborationService::FORBIDDEN, $o, 'stranger cannot invite');

        [$o2] = $this->svc->invite($this->slug, $this->ownerId, false, 'ceditor@example.com', 'editor');
        assert_same(CollaborationService::OK, $o2, 'owner can invite');
    }

    public function testInvitationRejectsUnknownAccountsAndBadRoles(): void
    {
        [$o] = $this->svc->invite($this->slug, $this->ownerId, false, 'nobody@example.com', 'editor');
        assert_same(CollaborationService::NOT_FOUND, $o, 'must be a registered account');

        [$o2] = $this->svc->invite($this->slug, $this->ownerId, false, 'ceditor@example.com', 'owner');
        assert_same(CollaborationService::INVALID, $o2, 'ownership is never invited');

        [$o3] = $this->svc->invite($this->slug, $this->ownerId, false, 'not-an-email', 'editor');
        assert_same(CollaborationService::INVALID, $o3, 'address must be valid');
    }

    public function testInvitationTokenIsHashedAndNeverStoredRaw(): void
    {
        $raw = $this->inviteAndToken($this->editorId, 'ceditor@example.com');
        $s = $this->pdo->query('SELECT token_hash FROM adventure_invitations');
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC);
        assert_same(1, count($rows), 'one invitation row');
        assert_true($rows[0]['token_hash'] !== $raw, 'raw token is not persisted');
        assert_same(TokenRepository::hash($raw), (string) $rows[0]['token_hash'], 'sha-256 of the token is stored');
        assert_same(64, strlen((string) $rows[0]['token_hash']), 'hash length');
    }

    public function testInvitationReachesInboxAndEmailQueue(): void
    {
        $this->svc->invite($this->slug, $this->ownerId, false, 'ceditor@example.com', 'reviewer');

        $inbox = $this->svc->notifications($this->editorId);
        assert_same(1, count($inbox), 'one inbox notification');
        assert_same('invitation', (string) $inbox[0]['kind'], 'notification kind');
        assert_same(1, $this->svc->unreadCount($this->editorId), 'unread count');

        $q = $this->pdo->query("SELECT template_key, to_email FROM email_queue WHERE template_key = 'adventure_invitation'");
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        assert_same(1, count($rows), 'invitation email queued');
        assert_same('ceditor@example.com', (string) $rows[0]['to_email'], 'queued to the invitee');
    }

    public function testAcceptGrantsTheInvitedRoleExactlyOnce(): void
    {
        $raw = $this->inviteAndToken($this->editorId, 'ceditor@example.com', 'editor');

        [$o, $d] = $this->svc->acceptInvitation($raw, $this->editorId);
        assert_same(CollaborationService::OK, $o, 'accept succeeds');
        assert_same('editor', (string) $d['role'], 'role granted');
        assert_same('editor', $this->collaboratorRoles()[$this->editorId] ?? '', 'collaborator row written');

        // Single use: replaying the same link cannot grant it again.
        [$o2] = $this->svc->acceptInvitation($raw, $this->editorId);
        assert_same(CollaborationService::CONFLICT, $o2, 'token is single-use');
    }

    public function testAcceptRejectsTheWrongRecipientAndRevokedOrExpiredTokens(): void
    {
        $raw = $this->inviteAndToken($this->editorId, 'ceditor@example.com');
        [$o] = $this->svc->acceptInvitation($raw, $this->strangerId);
        assert_same(CollaborationService::FORBIDDEN, $o, 'only the addressee may accept');

        // Revoked before use.
        $id = (int) $this->pdo->query('SELECT id FROM adventure_invitations ORDER BY id DESC LIMIT 1')
                              ->fetch(\PDO::FETCH_ASSOC)['id'];
        [$r] = $this->svc->revokeInvitation($this->slug, $this->ownerId, false, $id);
        assert_same(CollaborationService::OK, $r, 'owner revokes');
        [$o2] = $this->svc->acceptInvitation($raw, $this->editorId);
        assert_same(CollaborationService::CONFLICT, $o2, 'revoked token is dead');

        // Expired.
        $raw2 = $this->inviteAndToken($this->reviewerId, 'creviewer@example.com', 'reviewer');
        $this->pdo->prepare(
            'UPDATE adventure_invitations SET expires_at = :x WHERE token_hash = :h'
        )->execute([':x' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), ':h' => TokenRepository::hash($raw2)]);
        [$o3] = $this->svc->acceptInvitation($raw2, $this->reviewerId);
        assert_same(CollaborationService::EXPIRED, $o3, 'expired token is dead');
        assert_true(!isset($this->collaboratorRoles()[$this->reviewerId]), 'no role granted from a dead token');
    }

    public function testDeclineIsTerminalAndRevokeOnlyAppliesToPending(): void
    {
        $raw = $this->inviteAndToken($this->editorId, 'ceditor@example.com');
        [$o] = $this->svc->declineInvitation($raw, $this->editorId);
        assert_same(CollaborationService::OK, $o, 'decline succeeds');
        [$o2] = $this->svc->acceptInvitation($raw, $this->editorId);
        assert_same(CollaborationService::CONFLICT, $o2, 'declined token cannot be accepted');

        $id = (int) $this->pdo->query('SELECT id FROM adventure_invitations ORDER BY id DESC LIMIT 1')
                              ->fetch(\PDO::FETCH_ASSOC)['id'];
        [$o3] = $this->svc->revokeInvitation($this->slug, $this->ownerId, false, $id);
        assert_same(CollaborationService::CONFLICT, $o3, 'declined invitation cannot be revoked');
    }

    /* ────────────────────────── roles ──────────────────────────── */

    public function testOwnerChangesAndRemovesRoles(): void
    {
        $raw = $this->inviteAndToken($this->editorId, 'ceditor@example.com', 'editor');
        $this->svc->acceptInvitation($raw, $this->editorId);

        [$o] = $this->svc->setRole($this->slug, $this->ownerId, false, $this->editorId, 'reviewer');
        assert_same(CollaborationService::OK, $o, 'role changed');
        assert_same('reviewer', $this->collaboratorRoles()[$this->editorId], 'reviewer now');

        [$o2] = $this->svc->setRole($this->slug, $this->editorId, false, $this->editorId, 'editor');
        assert_same(CollaborationService::FORBIDDEN, $o2, 'a reviewer cannot promote themselves');

        [$o3] = $this->svc->setRole($this->slug, $this->ownerId, false, $this->editorId, null);
        assert_same(CollaborationService::OK, $o3, 'removed');
        assert_true(!isset($this->collaboratorRoles()[$this->editorId]), 'collaborator row gone');
        assert_same('role_removed', (string) $this->svc->notifications($this->editorId)[0]['kind'], 'removal notified');
    }

    public function testRoleChangesCanNeverTouchTheOwnerRow(): void
    {
        [$o] = $this->svc->setRole($this->slug, $this->ownerId, false, $this->ownerId, 'editor');
        assert_same(CollaborationService::CONFLICT, $o, 'owner row is not editable here');
        assert_same($this->ownerId, $this->ownerIdOf(), 'still the owner');

        [$o2] = $this->svc->setRole($this->slug, $this->ownerId, false, $this->strangerId, 'owner');
        assert_same(CollaborationService::INVALID, $o2, 'owner is not an assignable role');
    }

    /* ─────────────────── reauthentication ──────────────────────── */

    public function testReauthenticationRequiresTheCorrectPasswordAndOwnSession(): void
    {
        $session = $this->freshSession($this->ownerId);

        [$bad] = $this->svc->reauthenticate($this->ownerId, $session, 'wrong password');
        assert_same(CollaborationService::INVALID, $bad, 'wrong password rejected');

        [$foreign] = $this->svc->reauthenticate($this->editorId, $session, self::PASSWORD);
        assert_same(CollaborationService::FORBIDDEN, $foreign, 'cannot stamp another user\'s session');

        [$ok] = $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);
        assert_same(CollaborationService::OK, $ok, 'correct password accepted');
        assert_true($this->svc->reauthenticatedRecently($session), 'session is freshly reauthenticated');
    }

    public function testReauthenticationExpiresAndRevokedSessionsNeverCount(): void
    {
        $session = $this->freshSession($this->ownerId);
        $this->pdo->prepare('UPDATE sessions SET reauthenticated_at = :t WHERE id = :i')->execute([
            ':t' => gmdate('Y-m-d\TH:i:s\Z', time() - (CollaborationService::REAUTH_WINDOW_SECONDS + 30)),
            ':i' => $session,
        ]);
        assert_true(!$this->svc->reauthenticatedRecently($session), 'stale reauthentication does not count');

        $fresh = $this->freshSession($this->ownerId);
        $this->sessions->revoke($fresh);
        assert_true(!$this->svc->reauthenticatedRecently($fresh), 'revoked session does not count');
        assert_true(!$this->svc->reauthenticatedRecently(null), 'no session does not count');
    }

    /* ────────────────── ownership invariants ───────────────────── */

    private function makeEditor(): int
    {
        $raw = $this->inviteAndToken($this->editorId, 'ceditor@example.com', 'editor');
        $this->svc->acceptInvitation($raw, $this->editorId);
        return $this->editorId;
    }

    public function testTransferRequiresConfirmationAndRecentReauthentication(): void
    {
        $this->makeEditor();
        $session = $this->freshSession($this->ownerId);

        [$noConfirm] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, false);
        assert_same(CollaborationService::UNCONFIRMED, $noConfirm, 'explicit confirmation required');

        // Age the session past the window.
        $this->pdo->prepare('UPDATE sessions SET reauthenticated_at = :t, created_at = :t WHERE id = :i')->execute([
            ':t' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), ':i' => $session,
        ]);
        [$stale] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true);
        assert_same(CollaborationService::REAUTH, $stale, 'recent reauthentication required');
        assert_same($this->ownerId, $this->ownerIdOf(), 'ownership unchanged after refusals');

        $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);
        [$ok] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true);
        assert_same(CollaborationService::OK, $ok, 'transfer succeeds once both gates pass');
        assert_same($this->editorId, $this->ownerIdOf(), 'new owner recorded');
    }

    public function testTransferPreservesExactlyOneOwnerAndDemotesThePrevious(): void
    {
        $this->makeEditor();
        $session = $this->freshSession($this->ownerId);
        $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);

        [$o] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true, true);
        assert_same(CollaborationService::OK, $o, 'transfer ok');

        $roles = $this->collaboratorRoles();
        $owners = array_keys(array_filter($roles, static fn ($r) => $r === 'owner'));
        assert_same(1, count($owners), 'exactly one owner row');
        assert_same($this->editorId, $owners[0], 'the new owner holds it');
        assert_same($this->editorId, $this->ownerIdOf(), 'author_id agrees with the collaborator row');
        assert_same('editor', $roles[$this->ownerId], 'previous owner kept as editor');

        // The old owner can no longer manage the roster.
        [$forbidden] = $this->svc->invite($this->slug, $this->ownerId, false, 'cstranger@example.com', 'editor');
        assert_same(CollaborationService::FORBIDDEN, $forbidden, 'previous owner loses owner powers');
    }

    public function testTransferCanLetThePreviousOwnerLeaveEntirely(): void
    {
        $this->makeEditor();
        $session = $this->freshSession($this->ownerId);
        $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);

        [$o] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true, false);
        assert_same(CollaborationService::OK, $o, 'transfer ok');
        $roles = $this->collaboratorRoles();
        assert_true(!isset($roles[$this->ownerId]), 'previous owner left the team');
        assert_same(1, count(array_filter($roles, static fn ($r) => $r === 'owner')), 'still exactly one owner');
    }

    public function testTransferTargetMustAlreadyBeOnTheTeam(): void
    {
        $session = $this->freshSession($this->ownerId);
        $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);

        [$o] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->strangerId, true);
        assert_same(CollaborationService::INVALID, $o, 'strangers cannot be handed an adventure');
        assert_same($this->ownerId, $this->ownerIdOf(), 'ownership unchanged');

        [$self] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->ownerId, true);
        assert_same(CollaborationService::CONFLICT, $self, 'cannot transfer to yourself');
    }

    public function testOnlyTheOwnerMayTransferAndTheSecondTransferLoses(): void
    {
        $this->makeEditor();
        $editorSession = $this->freshSession($this->editorId);
        $this->svc->reauthenticate($this->editorId, $editorSession, self::PASSWORD);
        [$o] = $this->svc->transferOwnership($this->slug, $this->editorId, false, $editorSession, $this->editorId, true);
        assert_same(CollaborationService::FORBIDDEN, $o, 'an editor cannot transfer ownership');

        $session = $this->freshSession($this->ownerId);
        $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);
        [$first] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true);
        assert_same(CollaborationService::OK, $first, 'first transfer wins');
        [$second] = $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true);
        assert_same(CollaborationService::FORBIDDEN, $second, 'the ex-owner cannot repeat it');
        assert_same($this->editorId, $this->ownerIdOf(), 'ownership stable');
    }

    public function testTransferWritesActivityAndNotifiesBothParties(): void
    {
        $this->makeEditor();
        $session = $this->freshSession($this->ownerId);
        $this->svc->reauthenticate($this->ownerId, $session, self::PASSWORD);
        $this->svc->transferOwnership($this->slug, $this->ownerId, false, $session, $this->editorId, true);

        $acts = $this->pdo->query(
            "SELECT action FROM adventure_activity WHERE action = 'ownership_transferred'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        assert_same(1, count($acts), 'activity recorded');

        $kinds = array_map(
            static fn ($n) => (string) $n['kind'],
            $this->svc->notifications($this->editorId)
        );
        assert_true(in_array('ownership_received', $kinds, true), 'new owner notified');
        $ownerKinds = array_map(
            static fn ($n) => (string) $n['kind'],
            $this->svc->notifications($this->ownerId)
        );
        assert_true(in_array('ownership_transferred', $ownerKinds, true), 'previous owner notified');

        $q = $this->pdo->query("SELECT to_email FROM email_queue WHERE template_key = 'ownership_transferred'")
                       ->fetchAll(\PDO::FETCH_ASSOC);
        assert_same(1, count($q), 'ownership email queued');
    }

    /* ───────────────────── notifications ───────────────────────── */

    public function testNotificationsCanBeMarkedRead(): void
    {
        $this->svc->invite($this->slug, $this->ownerId, false, 'ceditor@example.com', 'editor');
        assert_same(1, $this->svc->unreadCount($this->editorId), 'one unread');
        $id = (int) $this->svc->notifications($this->editorId)[0]['id'];

        $this->svc->markRead($this->strangerId, $id);
        assert_same(1, $this->svc->unreadCount($this->editorId), 'another user cannot mark it read');

        $this->svc->markRead($this->editorId, $id);
        assert_same(0, $this->svc->unreadCount($this->editorId), 'marked read');
    }
}
