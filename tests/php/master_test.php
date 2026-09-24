<?php
/**
 * Version 0.25.0 — master administration: complete authorization matrix,
 * reauthentication gates, safeguards, and secret redaction.
 */
declare(strict_types=1);

use App\AdventureService;
use App\Database;
use App\MasterService;
use App\Migrator;
use App\PasswordHasher;
use App\UserRepository;

final class BPMasterTest
{
    private string $tmpDb;
    private \PDO $pdo;
    /** @var array<string,int> */
    private array $u = [];
    private int $adventureId;
    private int $sceneId;
    private bool $reauthOk = true;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-master-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        foreach (['admin', 'moderator', 'user', 'target', 'owner', 'admin2'] as $n) {
            $this->u[$n] = (int) $repo->insert([
                'email' => $n . '@example.com', 'username' => 'm' . $n, 'display_name' => ucfirst($n),
                'password_hash' => $hash['hash'], 'password_algo' => $hash['algo'], 'status' => 'active',
                'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
        $ins = $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:u,:r)');
        $ins->execute([':u' => $this->u['admin'], ':r' => 'admin']);
        $ins->execute([':u' => $this->u['admin2'], ':r' => 'admin']);
        $ins->execute([':u' => $this->u['moderator'], ':r' => 'moderator']);
        [$o, $e, $d] = (new AdventureService($this->pdo))->create($this->u['owner'], [
            'title' => 'The Lantern Pier', 'description' => 'Fog and a bell.', 'genre' => 'mystery',
            'content_rating' => 'everyone', 'content_warnings' => [], 'visibility' => 'public',
            'opening_title' => 'The pier', 'opening_body' => '<p>Fog.</p>', 'status' => 'published',
            'contribution_mode' => 'approval', 'anonymous_contributions' => false, 'max_branches_per_scene' => 4,
        ]);
        assert_same('ok', $o, json_encode($e));
        $this->adventureId = (int) $d['id'];
        $this->sceneId = (int) $d['opening_scene']['id'];
        $this->reauthOk = true;
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) if (is_file($f)) @unlink($f);
    }

    private function svc(): MasterService
    {
        return new MasterService($this->pdo, fn (?int $s): bool => $this->reauthOk);
    }

    private function report(): int
    {
        $this->pdo->prepare("INSERT INTO content_reports (adventure_id, target_type, scene_id, reason, reporter_ip, reporter_key)
                             VALUES (:a,'scene',:s,'spam','203.0.113.9','secret-fingerprint')")
            ->execute([':a' => $this->adventureId, ':s' => $this->sceneId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Every action for every role; returns outcome. */
    private function attempt(string $action, ?int $actor): string
    {
        $s = $this->svc(); $sid = 1; $t = $this->u['target'];
        [$o] = match ($action) {
            'overview'        => $s->overview($actor),
            'users'           => $s->users($actor),
            'adventures'      => $s->adventures($actor),
            'submissions'     => $s->submissions($actor),
            'reports'         => $s->reports($actor),
            'report_dismiss'  => $s->actOnReport($actor, $sid, $this->report(), 'dismiss'),
            'report_hide'     => $s->actOnReport($actor, $sid, $this->report(), 'hide'),
            'report_restore'  => $s->actOnReport($actor, $sid, $this->report(), 'restore'),
            'adventure_suspend' => $s->setAdventureSuspended($actor, $sid, $this->adventureId, true),
            'escalate'        => $s->escalateAccount($actor, $t, 'Repeated harassment reports'),
            'activity'        => $s->activity($actor),
            'role_change'     => $s->setRole($actor, $sid, $t, 'moderator'),
            'user_suspend'    => $s->setUserStatus($actor, $sid, $t, true),
            'settings_read'   => $s->settings($actor),
            'registration'    => $s->saveSettings($actor, $sid, 'registration', ['registration_enabled' => false]),
            'anonymous'       => $s->saveSettings($actor, $sid, 'anonymous', ['anonymous_reports_allowed' => false]),
            'limits'          => $s->saveSettings($actor, $sid, 'limits', ['max_adventures_per_user' => 5]),
            'smtp_read'       => $s->smtp($actor),
            'smtp_save'       => $s->saveSmtp($actor, $sid, ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls',
                                    'username' => 'u', 'password' => 'hunter2-secret', 'from_email' => 'no-reply@example.com']),
            'queue'           => $s->emailQueue($actor, null),
            'queue_cancel'    => $s->queueAction($actor, 999, 'cancel'),
            'maintenance'     => $s->saveSettings($actor, $sid, 'maintenance', ['maintenance_mode' => false]),
            'reset_email'     => $s->triggerReset($actor, $t),
            'transfer'        => $s->transferOwnership($actor, $sid, $this->adventureId, $t, true),
            'security_activity' => $s->activity($actor, true),
        };
        return $o;
    }

    private const MODERATOR_ALLOWED = [
        'overview', 'users', 'adventures', 'submissions', 'reports', 'report_dismiss', 'report_hide',
        'report_restore', 'adventure_suspend', 'escalate', 'activity',
    ];
    private const ADMIN_ONLY = [
        'role_change', 'user_suspend', 'settings_read', 'registration', 'anonymous', 'limits', 'smtp_read',
        'smtp_save', 'queue', 'queue_cancel', 'maintenance', 'reset_email', 'transfer', 'security_activity',
    ];

    public function testCompleteAuthorizationMatrix(): void
    {
        $all = array_merge(self::MODERATOR_ALLOWED, self::ADMIN_ONLY);
        foreach (['admin', 'moderator', 'user', 'anonymous'] as $who) {
            foreach ($all as $action) {
                $this->tearDown(); $this->setUp();
                $actor = $who === 'anonymous' ? null : $this->u[$who];
                $expected = match ($who) {
                    'admin' => 'ok',
                    'moderator' => in_array($action, self::MODERATOR_ALLOWED, true) ? 'ok' : 'forbidden',
                    default => 'forbidden',
                };
                assert_same($expected, $this->attempt($action, $actor), "$who → $action");
            }
        }
    }

    public function testPermissionTableMatchesMatrix(): void
    {
        foreach (array_keys(MasterService::PERMISSIONS) as $p) {
            assert_true(MasterService::can('admin', $p), "admin $p");
            assert_true(!MasterService::can('user', $p), "user $p");
        }
        foreach (['manage_roles', 'suspend_user', 'configure_smtp', 'configure_maintenance', 'transfer_ownership',
                  'trigger_reset', 'manage_email_queue', 'configure_registration', 'configure_anonymous',
                  'configure_limits', 'view_security_activity'] as $p) {
            assert_true(!MasterService::can('moderator', $p), "moderator must not $p");
        }
    }

    public function testSensitiveActionsRequireReauthentication(): void
    {
        $this->reauthOk = false;
        foreach (['role_change', 'transfer', 'smtp_save', 'maintenance', 'report_hide', 'adventure_suspend', 'user_suspend'] as $a) {
            $this->tearDown(); $this->setUp(); $this->reauthOk = false;
            assert_same('reauthentication_required', $this->attempt($a, $this->u['admin']), $a);
        }
        // Non-destructive actions do not.
        foreach (['report_dismiss', 'registration', 'limits', 'escalate'] as $a) {
            $this->tearDown(); $this->setUp(); $this->reauthOk = false;
            assert_same('ok', $this->attempt($a, $this->u['admin']), $a);
        }
        // Moderator hide without reauth is still refused.
        $this->tearDown(); $this->setUp(); $this->reauthOk = false;
        assert_same('reauthentication_required', $this->attempt('report_hide', $this->u['moderator']));
    }

    public function testSmtpNonCredentialChangeNeedsNoReauth(): void
    {
        $this->reauthOk = false;
        $cur = (new \App\SmtpSettingsRepository($this->pdo))->loadForApi();
        [$o] = $this->svc()->saveSmtp($this->u['admin'], 1, ['from_name' => 'Paths'] + $cur);
        assert_same('ok', $o);
    }

    public function testRealReauthUsesSessionWindow(): void
    {
        $s = new MasterService($this->pdo);
        [$o] = $s->setRole($this->u['admin'], null, $this->u['target'], 'moderator');
        assert_same('reauthentication_required', $o);
    }

    public function testLastAdminAndSelfSafeguards(): void
    {
        [$o, $d] = $this->svc()->setRole($this->u['admin'], 1, $this->u['admin'], 'user');
        assert_same('conflict', $o); assert_same('cannot_demote_self', $d['reason']);
        [$o] = $this->svc()->setRole($this->u['admin'], 1, $this->u['admin2'], 'user');
        assert_same('ok', $o);
        [$o] = $this->svc()->setUserStatus($this->u['admin'], 1, $this->u['admin'], true);
        assert_same('conflict', $o);
    }

    public function testSuspendUserRevokesSessionsAndRoleDisappears(): void
    {
        $this->pdo->prepare("INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (:u,'h1','2099-01-01T00:00:00Z')")
            ->execute([':u' => $this->u['moderator']]);
        [$o] = $this->svc()->setUserStatus($this->u['admin'], 1, $this->u['moderator'], true);
        assert_same('ok', $o);
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL AND user_id = {$this->u['moderator']}")->fetchColumn();
        assert_same(0, $n);
        assert_same('user', $this->svc()->platformRole($this->u['moderator']));
        [$o, $d] = $this->svc()->setUserStatus($this->u['admin'], 1, $this->u['moderator'], false);
        assert_same('active', $d['status']);
    }

    public function testAdventureSuspendRestoresPriorState(): void
    {
        $this->svc()->setAdventureSuspended($this->u['moderator'], 1, $this->adventureId, true);
        assert_same('suspended', $this->pdo->query("SELECT state FROM adventures WHERE id = {$this->adventureId}")->fetchColumn());
        [$o, $d] = $this->svc()->setAdventureSuspended($this->u['moderator'], 1, $this->adventureId, false);
        assert_same('published', $d['state']);
    }

    public function testHideAndRestoreReportedScene(): void
    {
        $r = $this->report();
        $this->svc()->actOnReport($this->u['moderator'], 1, $r, 'hide');
        assert_same('hidden', $this->pdo->query("SELECT state FROM scenes WHERE id = {$this->sceneId}")->fetchColumn());
        $this->svc()->actOnReport($this->u['moderator'], 1, $r, 'restore');
        assert_same('published', $this->pdo->query("SELECT state FROM scenes WHERE id = {$this->sceneId}")->fetchColumn());
    }

    public function testTransferKeepsExactlyOneOwner(): void
    {
        [$o] = $this->svc()->transferOwnership($this->u['admin'], 1, $this->adventureId, $this->u['target'], false);
        assert_same('invalid', $o);
        [$o] = $this->svc()->transferOwnership($this->u['admin'], 1, $this->adventureId, $this->u['target'], true);
        assert_same('ok', $o);
        $owners = (int) $this->pdo->query("SELECT COUNT(*) FROM adventure_collaborators WHERE adventure_id = {$this->adventureId} AND role = 'owner'")->fetchColumn();
        assert_same(1, $owners);
        assert_same($this->u['target'], (int) $this->pdo->query("SELECT author_id FROM adventures WHERE id = {$this->adventureId}")->fetchColumn());
    }

    public function testNoSecretsInAnyResponse(): void
    {
        $s = $this->svc(); $a = $this->u['admin'];
        $s->saveSmtp($a, 1, ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'u',
            'password' => 'hunter2-secret', 'from_email' => 'no-reply@example.com']);
        $s->triggerReset($a, $this->u['target']);
        $this->report();
        $dump = json_encode([
            $s->me($a), $s->overview($a), $s->users($a), $s->adventures($a), $s->submissions($a, 'all'),
            $s->reports($a, 'all'), $s->settings($a), $s->smtp($a), $s->emailQueue($a, null), $s->activity($a),
            $s->activity($a, true),
        ]);
        foreach (['hunter2-secret', 'password_hash', '$2y$', '$argon2', 'token_hash', 'reset_url', 'verify_url',
                  'password_ciphertext', '.sqlite', 'private/', 'secret-fingerprint', '203.0.113.9'] as $needle) {
            assert_true(strpos($dump, $needle) === false, "leaked $needle");
        }
    }

    public function testModeratorSeesNoEmailsOrSecurityLog(): void
    {
        $this->svc()->setRole($this->u['admin'], 1, $this->u['target'], 'moderator');
        [, $d] = $this->svc()->users($this->u['moderator']);
        foreach ($d['users'] as $row) assert_same(null, $row['email']);
        [, $act] = $this->svc()->activity($this->u['moderator']);
        foreach ($act['activity'] as $row) assert_true($row['action'] !== 'role_changed');
    }

    public function testSettingsValidationAndMaintenanceFlag(): void
    {
        [$o, $d] = $this->svc()->saveSettings($this->u['admin'], 1, 'limits', ['max_adventures_per_user' => 0]);
        assert_same('invalid', $o);
        $this->svc()->saveSettings($this->u['admin'], 1, 'maintenance', ['maintenance_mode' => true, 'maintenance_message' => 'Back soon']);
        assert_true(MasterService::maintenanceActive($this->pdo));
    }
}
