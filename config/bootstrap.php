<?php
// config/bootstrap.php
// Loaded at the top of every entry point. Sets BASE_URL, starts the session,
// generates the per-session CSRF token, and verifies it on every non-GET request.
// Idempotent: safe to require more than once in the same request.

if (!defined('BASE_URL')) {
    define('BASE_URL', '/ProVendor');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// One opaque token per session — used by both AJAX (X-CSRF-Token header,
// injected by assets/global_js/csrf.js) and the login form (hidden input).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Reject any state-changing request whose token doesn't match the session's.
// GET / HEAD / OPTIONS are considered side-effect-free and skipped.
function verify_csrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

    $sent     = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token']       ?? '';

    if ($expected === '' || !hash_equals($expected, (string) $sent)) {
        http_response_code(403);
        // API endpoints return JSON so the client can show a friendly error;
        // page POSTs (login form) get plain text since there's no JS handler.
        $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page and try again.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid CSRF token. Please refresh the page and try again.';
        }
        exit;
    }
}

verify_csrf();
