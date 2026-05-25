<?php
// api/delete_version.php
// Removes a dataset version + its snapshot rows. Does NOT touch the current
// sales table — this only deletes the historical snapshot.
//
// Input  (POST): { version_id: int }
// Output (JSON): { success: true } or { error: ... }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$versionId = isset($_POST['version_id']) ? (int) $_POST['version_id'] : 0;
if ($versionId <= 0) {
    echo json_encode(['error' => 'Invalid version ID.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/version.query.php';

try {
    if (deleteDatasetVersion($pdo, $versionId, $_SESSION['user_id'])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Version not found or access denied.']);
    }
} catch (PDOException $e) {
    error_log('[ProVendor delete_version] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error during delete. Please try again.']);
}
