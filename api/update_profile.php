<?php
// api/update_profile.php
// Saves the user's display name and store name.
// Accepts POST: name (string), store_name (string)
// Returns JSON: { success: true } or { error: string }

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$name      = trim($_POST['name']       ?? '');
$storeName = trim($_POST['store_name'] ?? '');

if ($name === '') {
    echo json_encode(['error' => 'Name is required.']);
    exit;
}

if ($storeName === '') {
    echo json_encode(['error' => 'Store name is required.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

updateUserProfile($pdo, (int) $_SESSION['user_id'], $name, $storeName);

echo json_encode(['success' => true]);
