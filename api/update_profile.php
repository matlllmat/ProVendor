<?php
// api/update_profile.php
// Saves the user's display name, store name, and store location.
// Accepts POST: name (string), store_name (string), lat (float|empty), lng (float|empty)
// Returns JSON: { success: true } or { error: string }

define('BASE_URL', '/ProVendor');
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$name      = trim($_POST['name']       ?? '');
$storeName = trim($_POST['store_name'] ?? '');
$latRaw    = $_POST['lat'] ?? '';
$lngRaw    = $_POST['lng'] ?? '';

if ($name === '') {
    echo json_encode(['error' => 'Name is required.']);
    exit;
}

if ($storeName === '') {
    echo json_encode(['error' => 'Store name is required.']);
    exit;
}

// Accept empty lat/lng — means the location was not set.
$lat = ($latRaw !== '') ? (float) $latRaw : null;
$lng = ($lngRaw !== '') ? (float) $lngRaw : null;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

updateUserProfile($pdo, (int) $_SESSION['user_id'], $name, $storeName, $lat, $lng);

echo json_encode(['success' => true]);
