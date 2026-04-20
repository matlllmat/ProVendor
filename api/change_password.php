<?php
// api/change_password.php
// Verifies the user's current password and replaces it with a new one.
// Accepts POST: current_password, new_password, confirm_password
// Returns JSON: { success: true } or { error: string }

define('BASE_URL', '/ProVendor');
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$currentPassword = $_POST['current_password'] ?? '';
$newPassword     = $_POST['new_password']     ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    echo json_encode(['error' => 'All three password fields are required.']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['error' => 'New password must be at least 8 characters.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['error' => 'New password and confirmation do not match.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

$currentHash = getUserPasswordHash($pdo, (int) $_SESSION['user_id']);

if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
    echo json_encode(['error' => 'Current password is incorrect.']);
    exit;
}

updateUserPassword($pdo, (int) $_SESSION['user_id'], password_hash($newPassword, PASSWORD_DEFAULT));

echo json_encode(['success' => true]);
