<?php
// api/list_versions.php
// Returns the user's dataset versions newest-first. Used by the Version History UI.
// Output (JSON): { versions: [...] }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/version.query.php';

try {
    $versions = listDatasetVersions($pdo, $_SESSION['user_id']);
    echo json_encode(['versions' => $versions]);
} catch (PDOException $e) {
    error_log('[ProVendor list_versions] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
