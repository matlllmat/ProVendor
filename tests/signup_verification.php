<?php
// Run: C:\xampp\php\php.exe tests/signup_verification.php
// Uses a disposable database and an in-memory mail sender; never sends email.
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/signup_verification.php';
define('BASE_URL', '/ProVendor');
ob_start();
session_save_path(sys_get_temp_dir());
session_start();
$testDb = 'provendor_signup_test_' . bin2hex(random_bytes(6));
$admin = $pdo;
$admin->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    $pdo = new PDO("mysql:host=$host;dbname=$testDb;charset=utf8mb4", $user, $pass, $options);
    $pdo->exec('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), store_name VARCHAR(100), email VARCHAR(150) UNIQUE, password VARCHAR(255)) ENGINE=InnoDB');
    $pdo->exec(file_get_contents(__DIR__ . '/../config/migrations/20261109_signup_otp.sql'));
    $now = time();
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $sent = [];
    $sender = function ($email, $code) use (&$sent) { $sent[] = ['email' => $email, 'code' => $code]; };
    $input = ['action' => 'signup', 'name' => 'Test Owner', 'store_name' => 'Test Store', 'email' => 'owner@example.invalid', 'password' => 'TestPassword!42', 'confirm_password' => 'TestPassword!42'];
    $result = handleSignupVerification($pdo, $input, $sender, $now);
    check(!empty($result['requires_verification']), 'Signup must require verification.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'Sending a code must not create an account.');
    check(!isset($_SESSION['user_id']), 'Sending a code must not authenticate the session.');
    $firstCode = $sent[0]['code'];
    check((bool) preg_match('/^[0-9]{6}$/D', $firstCode), 'Code must contain six digits.');
    check($_SESSION['pending_signup']['code_hash'] !== $firstCode, 'OTP must be stored hashed.');
    check(password_verify($input['password'], $_SESSION['pending_signup']['password_hash']), 'Password must be hashed while pending.');
    check(!array_key_exists('code_hash', $result) && !array_key_exists('password_hash', $result), 'Public state must not expose credentials.');
    $wrong = $firstCode === '000000' ? '111111' : '000000';
    for ($i = 0; $i < SIGNUP_MAX_ATTEMPTS; $i++) {
        $bad = handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $wrong], $sender, $now + 1);
        check(isset($bad['error']), 'Incorrect codes must be rejected.');
    }
    $locked = handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $firstCode], $sender, $now + 2);
    check(str_contains($locked['error'], 'Too many'), 'Correct code must not bypass the attempt limit.');
    $cooldown = handleSignupVerification($pdo, ['action' => 'resend_signup'], $sender, $now + 30);
    check($cooldown['retry_after'] === 30 && count($sent) === 1, 'Resend must honor the cooldown.');
    $resend = handleSignupVerification($pdo, ['action' => 'resend_signup'], $sender, $now + 61);
    check(!isset($resend['error']) && count($sent) === 2, 'Resend must work after cooldown.');
    $newCode = $sent[1]['code'];
    if ($newCode !== $firstCode) {
        check(isset(handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $firstCode], $sender, $now + 62)['error']), 'Resend must invalidate the previous code.');
    }
    $verified = handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $newCode, 'email' => 'attacker@example.invalid'], $sender, $now + 63);
    check(!empty($verified['success']), 'A correct code must complete signup.');
    check(isset($_SESSION['user_id']) && !isset($_SESSION['pending_signup']), 'Successful verification must authenticate and consume the challenge.');
    check((bool) getUserByEmail($pdo, $input['email']), 'Verified account must use the server-held email.');
    check(!getUserByEmail($pdo, 'attacker@example.invalid'), 'Posted email must not replace the verified email.');
    check(isset(handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $newCode], $sender, $now + 64)['error']), 'A used code must not be replayable.');
    unset($_SESSION['user_id']);
    check(isset(handleSignupVerification($pdo, $input, $sender, $now + 65)['error']), 'Duplicate accounts must be rejected.');

    $input['email'] = 'expiry@example.invalid';
    handleSignupVerification($pdo, $input, $sender, $now);
    $expiryCode = end($sent)['code'];
    check(isset(handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $expiryCode], $sender, $now + SIGNUP_CODE_TTL)['error']), 'Code must expire at its deadline.');
    $cancelled = handleSignupVerification($pdo, ['action' => 'cancel_signup'], $sender, $now + 601);
    check(!empty($cancelled['cancelled']) && !isset($_SESSION['pending_signup']), 'Changing email must invalidate pending signup.');
    check(!empty(handleSignupVerification($pdo, ['action' => 'verify_signup', 'code' => $expiryCode], $sender, $now + 602)['restart']), 'Cancelled code must not verify.');

    $input['email'] = 'persistent-limit@example.invalid';
    for ($i = 0; $i < 5; $i++) {
        $_SESSION = [];
        check(!isset(handleSignupVerification($pdo, $input, $sender, $now + $i * 61)['error']), 'Allowed email sends should succeed across sessions.');
    }
    $_SESSION = [];
    $limited = handleSignupVerification($pdo, $input, $sender, $now + 5 * 61);
    check(isset($limited['error']) && $limited['retry_after'] > 0, 'New sessions must not bypass hourly email limits.');
    check(!isset(handleSignupVerification($pdo, $input, $sender, $now + 3601)['error']), 'Email limit must reset after its window.');

    $_SESSION = [];
    $input['email'] = 'failed-delivery@example.invalid';
    $failed = handleSignupVerification($pdo, $input, function () { throw new RuntimeException('transport failed'); }, $now);
    check(isset($failed['error']) && !isset($_SESSION['pending_signup']), 'Failed delivery must not produce an active challenge.');
    check(!str_contains($failed['error'], 'transport failed'), 'Transport details must not be exposed.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Invalid and failed flows must not create accounts.');

    $_SERVER['REMOTE_ADDR'] = '192.0.2.20';
    for ($i = 0; $i < 20; $i++) {
        $_SESSION = [];
        $input['email'] = 'ip-limit-' . $i . '@example.invalid';
        check(!isset(handleSignupVerification($pdo, $input, $sender, $now)['error']), 'IP sends within quota should succeed.');
    }
    $_SESSION = [];
    $input['email'] = 'ip-limit-blocked@example.invalid';
    check(isset(handleSignupVerification($pdo, $input, $sender, $now)['error']), 'IP quota must cap sends across different emails and sessions.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'IP-limit tests must not create accounts.');
    $message = "PASS: $checks signup checks (verification gate, hashing, wrong codes, expiry, replay, resend, cancellation, mail failure and persistent email/IP limits).\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null;
    // The generated test database is the only database this test ever removes.
    $admin->exec('DROP DATABASE `' . $testDb . '`');
    session_destroy();
    ob_end_clean();
}
echo $message;
