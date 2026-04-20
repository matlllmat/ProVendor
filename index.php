<?php
// index.php
// Entry point — redirects based on auth state and whether the user has imported data.
define('BASE_URL', '/ProVendor');
session_start();

// if not logged in then redirect the user to login
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

// Once logged in — check if this user has any sales data
$hasSales = false;

if (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM sales s
             JOIN products p ON p.id = s.product_id
             WHERE p.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $hasSales = (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
    }
}

if ($hasSales) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/pages/landing.php');
}
exit;
