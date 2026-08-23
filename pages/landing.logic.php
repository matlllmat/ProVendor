<?php
// pages/landing.logic.php
// Auth guard, logout, and page-level data for the landing (setup) page.

require_once __DIR__ . '/../config/bootstrap.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

// Get user's name for the navbar
$userName = 'Store Owner';
try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../queries/user.query.php';

    // Onboarding is for owners who have no data yet. Once sales exist this page
    // has nothing left to do — and leaving it reachable traps them: re-uploading
    // the same file reports "already in your store", so there was no way forward
    // and no way into the app. Send them straight in instead.
    if (userHasSales($pdo, (int) $_SESSION['user_id']) && !isset($_GET['add'])) {
        header('Location: ' . BASE_URL . '/pages/dashboard.view.php');
        exit;
    }

    $user = getUserById($pdo, $_SESSION['user_id']);
    if ($user) $userName = $user['name'];
} catch (PDOException $e) {
    // Fall through with default name
}
