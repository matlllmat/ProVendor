<?php
// api/export_event_impact.php
// Exports the product-impact analysis for a given event as a CSV file.

define('BASE_URL', '/ProVendor');

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/events.query.php';

$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(400);
    exit('Missing event_id');
}

$event = getEventById($pdo, $eventId, $_SESSION['user_id']);
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

// Expand event into occurrence windows (same logic as event_detail.logic.php).
$dateRange    = getUserSaleDateRange($pdo, $_SESSION['user_id']);
$historyStart = $dateRange['earliest'] ?? date('Y-m-d', strtotime('-2 years'));
$historyEnd   = $dateRange['latest']   ?? date('Y-m-d');
$occurrences  = expandEvents([$event], $historyStart, $historyEnd);
$windows      = buildEventWindows($occurrences, 7);

if (empty($windows)) {
    http_response_code(404);
    exit('No occurrence windows found for this event');
}

// Fetch all products (no pagination) sorted by |impact_pct| DESC.
$result   = getProductImpactForEvent($pdo, $_SESSION['user_id'], $windows, 1, PHP_INT_MAX);
$products = $result['products'];

// Build a safe filename: event name with special chars stripped.
$safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $event['name']);
$filename = 'event_impact_' . $safeName . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens it correctly.
fwrite($out, "\xEF\xBB\xBF");

// Header row.
fputcsv($out, ['Product', 'Category', 'Avg Daily Sales', 'Event Avg Daily Sales', 'Impact %']);

foreach ($products as $p) {
    fputcsv($out, [
        $p['name'],
        $p['category'] ?? '',
        number_format($p['overall_avg'], 2),
        number_format($p['event_avg'],   2),
        $p['impact_pct'],
    ]);
}

fclose($out);
exit;
