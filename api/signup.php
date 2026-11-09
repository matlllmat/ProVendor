<?php
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST for signup verification.']);
    exit;
}
if (isset($_SESSION['user_id'])) {
    http_response_code(409);
    echo json_encode(['error' => 'You are already signed in.']);
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/signup_verification.php';
try {
    echo json_encode(handleSignupVerification($pdo, $_POST));
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[ProVendor signup] ' . get_class($e));
    echo json_encode(['error' => 'Signup is temporarily unavailable. Please try again.']);
}
