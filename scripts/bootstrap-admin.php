<?php
/**
 * scripts/bootstrap-admin.php
 *
 * Create or promote the initial administrator account. Intended to be
 * run once from the shell during installation:
 *
 *   php scripts/bootstrap-admin.php \
 *     --email=admin@example.com \
 *     --username=admin \
 *     --display="Administrator" \
 *     --password="…"
 *
 * If the user already exists, the script promotes them to `admin`
 * without touching the password. If the account is new, it is created
 * with `status = active`. Passwords may also be read from stdin so
 * they never appear in shell history:
 *
 *   php scripts/bootstrap-admin.php --email=… --username=… --display=… --stdin-password
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\UserRepository;
use App\WriteLock;

$args = parse_args($argv);
$email    = trim((string) ($args['email']    ?? ''));
$username = trim((string) ($args['username'] ?? ''));
$display  = trim((string) ($args['display']  ?? $username));
$password = (string) ($args['password'] ?? '');
if (empty($args['password']) && !empty($args['stdin-password'])) {
    $password = trim((string) fgets(STDIN));
}

$errors = [];
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'invalid email';
if (!preg_match('/^[A-Za-z0-9_\-]{3,32}$/', $username)) $errors[] = 'invalid username';
if ($display === '' || strlen($display) > 64) $errors[] = 'invalid display name';
if (strlen($password) < 12) $errors[] = 'password must be at least 12 characters';

if ($errors !== []) {
    fwrite(STDERR, "usage: bootstrap-admin.php --email=<> --username=<> --display=<> --password=<>\n");
    foreach ($errors as $e) fwrite(STDERR, "  - $e\n");
    exit(2);
}

$pdo = Database::open();
$mig = new Migrator($pdo);
if ((int) $mig->currentVersion() < 3) {
    fwrite(STDERR, "error: run scripts/migrate.php first (need schema >= 0003)\n");
    exit(2);
}

$lock = new WriteLock();
$exit = $lock->withLock(static function () use ($pdo, $email, $username, $display, $password): int {
    $repo = new UserRepository($pdo);
    // Locate existing account by either normalised form.
    $stmt = $pdo->prepare(
        'SELECT id FROM users
          WHERE email_normalized = :e OR username_normalized = :u
          LIMIT 1'
    );
    $stmt->execute([
        ':e' => UserRepository::normaliseEmail($email),
        ':u' => UserRepository::normaliseUsername($username),
    ]);
    $existing = $stmt->fetch();

    if ($existing !== false) {
        $userId = (int) $existing['id'];
        fwrite(STDOUT, "user #$userId already exists — promoting to admin\n");
    } else {
        $h = PasswordHasher::hash($password);
        $userId = $repo->insert([
            'email'             => $email,
            'username'          => $username,
            'display_name'      => $display,
            'password_hash'     => $h['hash'],
            'password_algo'     => $h['algo'],
            'status'            => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        if ($userId === null) {
            fwrite(STDERR, "error: could not create user (duplicate or db error)\n");
            return 1;
        }
        fwrite(STDOUT, "created user #$userId ($username)\n");
    }

    // Idempotent role grant.
    $pdo->prepare(
        'INSERT OR IGNORE INTO user_roles (user_id, role) VALUES (:u, :r)'
    )->execute([':u' => $userId, ':r' => 'admin']);

    // Ensure the account is active so the operator can actually use it.
    $pdo->prepare('UPDATE users SET status = \'active\' WHERE id = :id AND status != \'suspended\'')
        ->execute([':id' => $userId]);

    fwrite(STDOUT, "granted role: admin\n");
    return 0;
});
exit($exit);

function parse_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--', 2) !== 0) continue;
        $eq = strpos($a, '=');
        if ($eq === false) { $out[substr($a, 2)] = '1'; }
        else { $out[substr($a, 2, $eq - 2)] = substr($a, $eq + 1); }
    }
    return $out;
}
