<?php
// api/export_restock.php
// Streams the logged-in user's suggested-restock list as a CSV download.
// GET (a plain download link) — read-only, no CSRF needed. Regenerated from the
// DB so it matches the dashboard's on-screen list.
//
// Optional GET ids=1,2,3 — the dashboard's "Export selected" option. Filtered
// in PHP against the user's own full list, so a tampered id just drops out
// rather than leaking another account's product.
//
// Optional GET view=simple — only the columns of the dashboard's Simple view.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authenticated.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/dashboard.query.php';
require_once __DIR__ . '/export_helpers.php';

$rows = getRestockOverview($pdo, (int) $_SESSION['user_id']);

if (isset($_GET['ids']) && $_GET['ids'] !== '') {
    $ids  = array_map('intval', explode(',', $_GET['ids']));
    $rows = array_values(array_filter($rows, fn($r) => in_array($r['id'], $ids, true)));
}

streamRestockCsv($rows, 'provendor_restock_' . date('Y-m-d') . '.csv', ($_GET['view'] ?? '') === 'simple');
exit;
