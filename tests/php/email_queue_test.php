<?php
/**
 * tests/php/email_queue_test.php
 *
 * Version 0.12.0 — covers SMTP settings redaction/encryption, the
 * queue's claim/retry/recover life-cycle, template rendering, the
 * error redactor, and the worker's happy and failure paths using a
 * MockTransport.
 *
 * No test connects to a real SMTP server.
 */

declare(strict_types=1);

use App\AdminSession;
use App\Database;
use App\Encryption;
use App\EmailQueueRepository;
use App\EmailQueueService;
use App\EmailTemplateRepository;
use App\Mailer\MailerException;
use App\Mailer\MockTransport;
use App\Migrator;
use App\PasswordHasher;
use App\SmtpSettingsRepository;
use App\UserRepository;

final class BPEmailQueueTest
{
    private string $tmpDb;
    private string $tmpKey;
    private \PDO $pdo;
    private Encryption $crypto;

    public function setUp(): void
    {
        $this->tmpDb  = sys_get_temp_dir() . '/bp-eq-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->tmpKey = sys_get_temp_dir() . '/bp-eq-' . bin2hex(random_bytes(6)) . '.key';
        file_put_contents($this->tmpKey, random_bytes(32));
        $this->crypto = new Encryption($this->tmpKey);
        $this->pdo    = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm', $this->tmpKey] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    // ── SmtpSettingsRepository ─────────────────────────────────────

    public function testSaveEncryptsPasswordAndRedactsForApi(): void
    {
        $repo = new SmtpSettingsRepository($this->pdo, $this->crypto);
        [$ok, $errs] = $repo->save([
            'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'starttls',
            'username' => 'ops', 'password' => 'super-secret',
            'from_email' => 'from@example.com', 'from_name' => 'BP',
            'reply_to' => '', 'enabled' => true, 'retry_limit' => 5, 'batch_size' => 25,
        ]);
        assert_true($ok, 'save should succeed');
        assert_same([], $errs);

        // password_ciphertext must NOT contain the plaintext.
        $row = $this->pdo->query('SELECT password_ciphertext FROM smtp_settings WHERE id=1')->fetch();
        assert_true(strpos((string) $row['password_ciphertext'], 'super-secret') === false,
            'ciphertext must not contain plaintext');

        // API projection must redact.
        $api = $repo->loadForApi();
        assert_same(SmtpSettingsRepository::PASSWORD_UNCHANGED_MARKER, $api['password']);

        // load() decrypts correctly.
        $s = $repo->load();
        assert_same('super-secret', $s['password']);
    }

    public function testSavePasswordUnchangedMarkerPreservesPassword(): void
    {
        $repo = new SmtpSettingsRepository($this->pdo, $this->crypto);
        $repo->save([
            'host'=>'h','port'=>25,'encryption'=>'none','username'=>'u',
            'password'=>'keep-me','from_email'=>'a@b.co','from_name'=>'',
            'reply_to'=>'','enabled'=>true,'retry_limit'=>3,'batch_size'=>10,
        ]);
        // Re-save with the redaction marker: password must NOT change.
        $repo->save([
            'host'=>'h2','port'=>25,'encryption'=>'none','username'=>'u',
            'password'=> SmtpSettingsRepository::PASSWORD_UNCHANGED_MARKER,
            'from_email'=>'a@b.co','from_name'=>'','reply_to'=>'',
            'enabled'=>true,'retry_limit'=>3,'batch_size'=>10,
        ]);
        assert_same('keep-me', $repo->load()['password']);
        assert_same('h2', $repo->load()['host']);
    }

    public function testSaveRejectsInvalidInput(): void
    {
        $repo = new SmtpSettingsRepository($this->pdo, $this->crypto);
        [$ok, $errs] = $repo->save([
            'host'=>'', 'port'=>70000, 'encryption'=>'weird',
            'from_email'=>'not-an-email',
        ]);
        assert_true(!$ok);
        assert_true(isset($errs['host']));
        assert_true(isset($errs['port']));
        assert_true(isset($errs['encryption']));
        assert_true(isset($errs['from_email']));
    }

    // ── EmailQueueRepository ───────────────────────────────────────

    public function testClaimBatchIsBoundedAndOrderedByReadiness(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        for ($i = 0; $i < 5; $i++) {
            $repo->enqueue('welcome', "to$i@example.com", '', ['display_name' => "u$i"]);
        }
        $rows = $repo->claimBatch(3);
        assert_same(3, count($rows));
        foreach ($rows as $r) { assert_same('sending', $r['status']); }
        // A subsequent claim only gets what's left.
        $more = $repo->claimBatch(10);
        assert_same(2, count($more));
    }

    public function testRetryUsesBackoffAndFinalFail(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $id = $repo->enqueue('welcome', 'x@example.com', '', []);
        $rows = $repo->claimBatch(1);
        assert_same($id, (int) $rows[0]['id']);

        $result = $repo->markFailedOrRetry($id, 0, 3, 'smtp_552');
        assert_same('retry', $result);
        $row = $this->pdo->query("SELECT status, attempts, next_attempt_at FROM email_queue WHERE id=$id")->fetch();
        assert_same('pending', $row['status']);
        assert_same(1, (int) $row['attempts']);
        assert_true($row['next_attempt_at'] > gmdate('Y-m-d\TH:i:s\Z'),
            'next_attempt_at should be in the future');

        // Repeatedly failing hits the retry limit.
        $repo->claimBatch(1);
        $repo->markFailedOrRetry($id, 1, 3, 'smtp_552');
        $repo->claimBatch(1);
        $out = $repo->markFailedOrRetry($id, 2, 3, 'smtp_552');
        assert_same('failed', $out);
        $final = $this->pdo->query("SELECT status FROM email_queue WHERE id=$id")->fetch();
        assert_same('failed', $final['status']);
    }

    public function testRecoverStaleReclaimsAbandonedSendingRows(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $id = $repo->enqueue('welcome', 'x@example.com', '', []);
        // Manually simulate a crashed worker.
        $this->pdo->exec("UPDATE email_queue SET status='sending', claimed_at='2000-01-01T00:00:00Z' WHERE id=$id");
        $moved = $repo->recoverStale(60);
        assert_same(1, $moved);
        $row = $this->pdo->query("SELECT status, last_error FROM email_queue WHERE id=$id")->fetch();
        assert_same('pending', $row['status']);
        assert_same('worker_did_not_report', $row['last_error']);
    }

    public function testCancelOnlyAffectsPendingRows(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $id = $repo->enqueue('welcome', 'x@example.com', '', []);
        assert_true($repo->cancel($id));
        assert_true(!$repo->cancel($id));
    }

    // ── EmailTemplateRepository ────────────────────────────────────

    public function testTemplateRenderSubstitutesAndEscapes(): void
    {
        // Insert a template that includes an HTML body so we can
        // verify HTML escaping.
        $this->pdo->exec(
            "INSERT INTO email_templates (template_key,subject,text_body,html_body)
             VALUES ('t','Hi {name}','Hi {name}','<p>Hi {name}</p>')"
        );
        $r = new EmailTemplateRepository($this->pdo);
        [$s, $t, $h] = $r->render('t', ['name' => '<script>']);
        assert_same('Hi <script>', $s);
        assert_same('Hi <script>', $t);
        assert_same('<p>Hi &lt;script&gt;</p>', $h);
    }

    public function testUnknownPlaceholderIsPreserved(): void
    {
        $r = new EmailTemplateRepository($this->pdo);
        [$s] = $r->render('welcome', []);
        assert_true(strpos($s, 'Welcome') !== false);
    }

    // ── EmailQueueService (worker path) ────────────────────────────

    public function testWorkerSendsBatchAndMarksSent(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $repo->enqueue('welcome', "u$i@example.com", "U$i", ['display_name' => "U$i"]);
        }
        $t = new MockTransport();
        $svc = new EmailQueueService(
            $repo, new EmailTemplateRepository($this->pdo), $t,
            $this->smtpConfig(enabled: true)
        );
        $report = $svc->runOnce();
        assert_same(3, $report['claimed']);
        assert_same(3, $report['sent']);
        assert_same(3, count($t->messages()));
        assert_same('u0@example.com', $t->messages()[0]['to_email']);
        // All rows are 'sent'.
        $sent = (int) $this->pdo->query("SELECT COUNT(*) c FROM email_queue WHERE status='sent'")->fetch()['c'];
        assert_same(3, $sent);
    }

    public function testWorkerRetriesTransientFailure(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $id = $repo->enqueue('welcome', 'a@example.com', '', []);
        $t = new MockTransport();
        $t->failNext('smtp_450');
        $svc = new EmailQueueService(
            $repo, new EmailTemplateRepository($this->pdo), $t,
            $this->smtpConfig(enabled: true, retry_limit: 3)
        );
        $r = $svc->runOnce();
        assert_same(0, $r['sent']);
        assert_same(1, $r['retried']);
        $row = $this->pdo->query("SELECT status, last_error FROM email_queue WHERE id=$id")->fetch();
        assert_same('pending', $row['status']);
        assert_same('smtp_450', $row['last_error']);
    }

    public function testWorkerRedactsRawErrorMessages(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $id = $repo->enqueue('welcome', 'a@example.com', '', []);
        $t = new MockTransport();
        // Simulate a transport that emits a chatty error containing
        // the recipient address — the worker must NOT persist it.
        $t->failNext('550 5.1.1 <a@example.com> No such user');
        $svc = new EmailQueueService(
            $repo, new EmailTemplateRepository($this->pdo), $t,
            $this->smtpConfig(enabled: true, retry_limit: 2)
        );
        $svc->runOnce();
        $row = $this->pdo->query("SELECT last_error FROM email_queue WHERE id=$id")->fetch();
        assert_same('transport_error', $row['last_error'],
            'raw provider replies must be redacted');
    }

    public function testWorkerDisabledSmtpDoesNothing(): void
    {
        $repo = new EmailQueueRepository($this->pdo);
        $repo->enqueue('welcome', 'a@example.com', '', []);
        $svc = new EmailQueueService(
            $repo, new EmailTemplateRepository($this->pdo),
            new MockTransport(),
            $this->smtpConfig(enabled: false)
        );
        $r = $svc->runOnce();
        assert_same(0, $r['claimed']);
        // Row remains pending.
        $status = $this->pdo->query("SELECT status FROM email_queue LIMIT 1")->fetch()['status'];
        assert_same('pending', $status);
    }

    public function testRedactorRejectsWhitespaceAndSymbols(): void
    {
        assert_same('smtp_535', EmailQueueService::redact('smtp_535'));
        assert_same('transport_error', EmailQueueService::redact('smtp 535'));
        assert_same('transport_error', EmailQueueService::redact('nope@example.com'));
        assert_same('transport_error', EmailQueueService::redact(''));
    }

    // ── AdminSession ───────────────────────────────────────────────

    public function testAdminSessionLoginRequiresAdminRole(): void
    {
        // Seed a user with no admin role.
        $h = PasswordHasher::hash('correct-horse-battery-staple');
        $this->pdo->prepare(
            "INSERT INTO users (email,email_normalized,username,username_normalized,
                                display_name,password_hash,password_algo,status,
                                terms_accepted_at,created_at,updated_at)
             VALUES ('op@example.com','op@example.com','op','op','Op',:h,:a,'active',
                     '2025-01-01T00:00:00Z','2025-01-01T00:00:00Z','2025-01-01T00:00:00Z')"
        )->execute([':h' => $h['hash'], ':a' => $h['algo']]);
        $uid = (int) $this->pdo->lastInsertId();

        // Isolated key so we don't touch the real session.key file.
        $secret = random_bytes(32);
        $session = new AdminSession($secret);

        // Without a role: login rejects.
        assert_same(null, $session->login($this->pdo, 'op@example.com', 'correct-horse-battery-staple'));

        // Grant admin: login succeeds.
        $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:u, \'admin\')')
                  ->execute([':u' => $uid]);
        $result = $session->login($this->pdo, 'op@example.com', 'correct-horse-battery-staple');
        assert_same($uid, $result);

        // Bad password rejects.
        $session2 = new AdminSession($secret);
        assert_same(null, $session2->login($this->pdo, 'op@example.com', 'wrong-password!'));
    }

    /** @return array<string,mixed> */
    private function smtpConfig(bool $enabled, int $retry_limit = 5): array
    {
        return [
            'host' => 'mail.example.com', 'port' => 587,
            'encryption' => 'starttls', 'username' => 'u', 'password' => 'p',
            'from_email' => 'from@example.com', 'from_name' => 'BP',
            'reply_to' => '', 'enabled' => $enabled,
            'retry_limit' => $retry_limit, 'batch_size' => 25,
        ];
    }
}
