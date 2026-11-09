<?php
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/mailer.php';

const SIGNUP_CODE_TTL = 600;
const SIGNUP_RESEND_SECONDS = 60;
const SIGNUP_MAX_ATTEMPTS = 5;

function signupVerificationState(?int $now = null): array
{
    $now ??= time();
    $pending = $_SESSION['pending_signup'] ?? null;
    if (!$pending || $now - $pending['created_at'] >= 3600) {
        unset($_SESSION['pending_signup']);
        return [];
    }
    return [
        'requires_verification' => true,
        'email' => $pending['email'],
        'resend_after' => max(0, $pending['sent_at'] + SIGNUP_RESEND_SECONDS - $now),
        'expires_in' => max(0, $pending['expires_at'] - $now),
    ];
}

// Reserve both email and IP limits atomically, before contacting SMTP.
function reserveSignupEmail(PDO $pdo, string $email, int $now): ?array
{
    $buckets = [
        hash('sha256', 'email|' . mb_strtolower($email)) => ['max' => 5, 'cooldown' => SIGNUP_RESEND_SECONDS],
        hash('sha256', 'ip|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) => ['max' => 20, 'cooldown' => 0],
    ];
    ksort($buckets);
    $pdo->beginTransaction();
    try {
        foreach ($buckets as $bucket => $limit) {
            $pdo->prepare('INSERT IGNORE INTO signup_otp_limits (bucket, window_started) VALUES (?, ?)')
                ->execute([$bucket, $now]);
            $stmt = $pdo->prepare('SELECT window_started, sent_count, last_sent_at FROM signup_otp_limits WHERE bucket = ? FOR UPDATE');
            $stmt->execute([$bucket]);
            $row = $stmt->fetch();
            $newWindow = $now - (int) $row['window_started'] >= 3600;
            $wait = $limit['cooldown'] > 0 ? $limit['cooldown'] - ($now - (int) $row['last_sent_at']) : 0;
            if ($wait > 0 || (!$newWindow && (int) $row['sent_count'] >= $limit['max'])) {
                $retry = max($wait, $newWindow ? 0 : 3600 - ($now - (int) $row['window_started']));
                if ($wait > 0) $retry = $wait;
                $pdo->rollBack();
                return ['error' => $wait > 0 ? 'Please wait before requesting another code.' : 'Too many verification emails. Please try again later.', 'retry_after' => $retry];
            }
            $pdo->prepare('UPDATE signup_otp_limits SET window_started = ?, sent_count = ?, last_sent_at = ? WHERE bucket = ?')
                ->execute([$newWindow ? $now : $row['window_started'], $newWindow ? 1 : (int) $row['sent_count'] + 1, $now, $bucket]);
        }
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function handleSignupVerification(PDO $pdo, array $input, ?callable $send = null, ?int $now = null): array
{
    $now ??= time();
    $send ??= 'sendSignupCode';
    $action = $input['action'] ?? '';
    if ($action === 'cancel_signup') {
        unset($_SESSION['pending_signup']);
        return ['cancelled' => true];
    }

    if ($action === 'signup') {
        $name = trim((string) ($input['name'] ?? ''));
        $store = trim((string) ($input['store_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['confirm_password'] ?? '');
        if ($name === '' || $store === '' || $email === '' || $password === '' || $confirm === '') {
            return ['error' => 'Please fill in all fields.'];
        }
        if (mb_strlen($name) > 100 || mb_strlen($store) > 100 || mb_strlen($email) > 150) {
            return ['error' => 'Name and store name must be at most 100 characters, and email at most 150.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'Please enter a valid email address.'];
        if ($password !== $confirm) return ['error' => 'Passwords do not match.'];
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password)) {
            return ['error' => 'Password must be at least 8 characters, include 1 uppercase, 1 lowercase, 1 number, and 1 special character.'];
        }
        if (getUserByEmail($pdo, $email)) return ['error' => 'An account with that email already exists.'];
        $pending = ['name' => $name, 'store_name' => $store, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => $now];
    } else {
        $state = signupVerificationState($now);
        if (!$state) return ['error' => 'Verification expired. Please enter your account details again.', 'restart' => true];
        $pending = $_SESSION['pending_signup'];
        if ($action === 'verify_signup') {
            if ($pending['attempts'] >= SIGNUP_MAX_ATTEMPTS) return $state + ['error' => 'Too many incorrect attempts. Request a new code.'];
            if ($now >= $pending['expires_at']) return $state + ['error' => 'This code has expired. Request a new code.'];
            $code = trim((string) ($input['code'] ?? ''));
            $_SESSION['pending_signup']['attempts']++;
            if (!preg_match('/^[0-9]{6}$/D', $code) || !password_verify($code, $pending['code_hash'])) {
                $left = SIGNUP_MAX_ATTEMPTS - $_SESSION['pending_signup']['attempts'];
                return $state + ['error' => $left > 0 ? 'Incorrect code. ' . $left . ' attempts remaining.' : 'Too many incorrect attempts. Request a new code.'];
            }
            try {
                $id = createUser($pdo, $pending['name'], $pending['store_name'], $pending['email'], $pending['password_hash']);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                unset($_SESSION['pending_signup']);
                return ['error' => 'An account with that email already exists. Please log in.', 'restart' => true];
            }
            unset($_SESSION['pending_signup']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $id;
            return ['success' => true, 'redirect' => BASE_URL . '/pages/landing.view.php'];
        }
        if ($action !== 'resend_signup') return ['error' => 'Unknown signup action.'];
    }

    $limited = reserveSignupEmail($pdo, $pending['email'], $now);
    if ($limited) return $action === 'signup' ? $limited : signupVerificationState($now) + $limited;
    $code = sprintf('%06d', random_int(0, 999999));
    try {
        $send($pending['email'], $code);
    } catch (Throwable $e) {
        // Do not expose SMTP credentials or transport details to the browser.
        return ($action === 'signup' ? [] : signupVerificationState($now)) + ['error' => 'We could not send the verification email. Please wait a minute and try again.'];
    }
    $pending['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $pending['expires_at'] = $now + SIGNUP_CODE_TTL;
    $pending['sent_at'] = $now;
    $pending['attempts'] = 0;
    $_SESSION['pending_signup'] = $pending;
    return signupVerificationState($now) + ['message' => 'Verification code sent. Check your inbox and spam folder.'];
}
