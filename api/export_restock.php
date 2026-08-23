<?php
// api/export_restock.php
// Streams the logged-in user's suggested-restock list as a CSV download.
// GET (a plain download link) — read-only, no CSRF needed. Regenerated from the
// DB so it matches the dashboard's on-screen list.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authenticated.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/dashboard.query.php';
require_once __DIR__ . '/export_helpers.php';

$rows = getRestockOverview($pdo, (int) $_SESSION['user_id']);

streamRestockCsv($rows, 'provendor_restock_' . date('Y-m-d') . '.csv');
exit;
