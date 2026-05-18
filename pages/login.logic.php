<?php
// pages/login.logic.php
// Handles login and signup POST actions. Sets $error and $activeTab for the view.

require_once __DIR__ . '/../config/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error     = null;
$activeTab = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../queries/user.query.php';

    $action = $_POST['action'] ?? '';

    // ── Login ──────────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Please fill in all fields.';
        } else {
            $user = getUserByEmail($pdo, $email);

            if ($user && password_verify($password, $user['password'])) {
                // Rotate the session ID at the auth boundary to prevent session fixation.
                // Session data (including csrf_token) is preserved.
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $hasSales = userHasSales($pdo, $user['id']);
                header('Location: ' . BASE_URL . ($hasSales ? '/pages/forecast.view.php' : '/pages/landing.view.php'));
                exit;
            } else {
                $error = 'Incorrect email or password.';
            }
        }

    // ── Signup ─────────────────────────────────────────────────────────────
    } elseif ($action === 'signup') {
        $activeTab = 'signup';
        $name      = trim($_POST['name']       ?? '');
        $storeName = trim($_POST['store_name'] ?? '');
        $email     = trim($_POST['email']      ?? '');
        $password  = trim($_POST['password']   ?? '');

        if ($name === '' || $storeName === '' || $email === '' || $password === '') {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            $existing = getUserByEmail($pdo, $email);

            if ($existing) {
                $error = 'An account with that email already exists.';
            } else {
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $newId = createUser($pdo, $name, $storeName, $email, $hash);
                // Rotate the session ID at the auth boundary (same reason as login).
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newId;
                header('Location: ' . BASE_URL . '/pages/landing.view.php');
                exit;
            }
        }
    }
}
