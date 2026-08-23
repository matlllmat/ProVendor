<?php
// api/export_version.php
// Streams one saved dataset version (an import snapshot) as a CSV download —
// i.e. the data as it was for that upload. GET request (download link), no CSRF.
// ?version_id=<id>

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authenticated.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/version.query.php';
require_once __DIR__ . '/export_helpers.php';

$userId    = (int) $_SESSION['user_id'];
$versionId = isset($_GET['version_id']) ? (int) $_GET['version_id'] : 0;

// Ownership check: getDatasetVersion returns null if the version isn't this user's.
$version = $versionId > 0 ? getDatasetVersion($pdo, $versionId, $userId) : null;
if (!$version) {
    http_response_code(404);
    exit('Version not found.');
}

$rows  = getVersionSalesForExport($pdo, $userId, $versionId);
$stamp = date('Y-m-d', strtotime($version['created_at']));

streamSalesCsv($rows, 'provendor_v' . $versionId . '_' . $stamp . '.csv');
exit;
