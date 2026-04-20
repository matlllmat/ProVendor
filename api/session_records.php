<?php
// api/session_records.php
// Returns a paginated list of sales records for one import session.
// Only accessible to the session's owner.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';

$sessionId = (int) ($_GET['session_id'] ?? 0);
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 50;

if ($sessionId <= 0) {
    echo json_encode(['error' => 'Invalid session.']);
    exit;
}

$total   = countSessionRecords($pdo, $sessionId, $_SESSION['user_id']);
$records = getSessionRecords($pdo, $sessionId, $_SESSION['user_id'], $page, $perPage);

echo json_encode([
    'records'     => $records,
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $perPage,
    'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
]);
