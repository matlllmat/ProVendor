<?php
// pages/login.logic.php
// Handles login and signup POST actions. Sets $error and $activeTab for the view.

define('BASE_URL', '/ProVendor');

session_start();

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
                $_SESSION['user_id'] = $user['id'];
                $hasSales = userHasSales($pdo, $user['id']);
                header('Location: ' . BASE_URL . ($hasSales ? '/pages/dashboard.view.php' : '/pages/landing.view.php'));
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
        } else {
            $existing = getUserByEmail($pdo, $email);

            if ($existing) {
                $error = 'An account with that email already exists.';
            } else {
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $newId = createUser($pdo, $name, $storeName, $email, $hash);
                $_SESSION['user_id'] = $newId;
                header('Location: ' . BASE_URL . '/pages/landing.view.php');
                exit;
            }
        }
    }
}
