<?php
// api/export_data.php
// Streams the logged-in user's CURRENT sales data as a CSV download.
// GET request (a plain download link) — no CSRF needed, it's read-only.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authenticated.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/export_helpers.php';

$rows = getSalesForExport($pdo, (int) $_SESSION['user_id']);

streamSalesCsv($rows, 'provendor_sales_' . date('Y-m-d') . '.csv');
exit;
