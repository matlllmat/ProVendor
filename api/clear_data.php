<?php
// api/clear_data.php
// Permanently deletes all imported sales data for the authenticated user.
// Preserves the user account, store name, and store location.
// After success the client redirects to the landing page (pre-data state).
// Accepts POST: (no body required — identity comes from session)
// Returns JSON: { success: true } or { error: string }

define('BASE_URL', '/ProVendor');
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

clearUserData($pdo, (int) $_SESSION['user_id']);

echo json_encode(['success' => true]);
