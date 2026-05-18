<?php
// api/logout.php
// Destroys the session. POST-only so CSRF protection applies (a GET logout link
// would let an attacker force-log-out a user via <img src=".../logout.php">).

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

// Clear all session data, drop the cookie, then destroy the session entirely.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

echo json_encode(['success' => true]);
