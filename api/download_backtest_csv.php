<?php
// api/download_backtest_csv.php
// Streams back the CSV from the owner's most recent "Backtest With Your Own
// Data" run, exactly as uploaded, so they can review what was tested.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not authenticated.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/backtest.query.php';

$run = getBacktestRun($pdo, (int) $_SESSION['user_id']);
if (!$run) {
    http_response_code(404);
    exit('No saved backtest to download.');
}

$path = __DIR__ . '/../' . $run['stored_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('The saved file is missing.');
}

// Strip anything that could break out of the quoted filename param (quotes,
// control characters) — this is the owner's own filename, but it round-tripped
// through a browser's file picker and shouldn't be trusted verbatim in a header.
$safeName = preg_replace('/[\x00-\x1F"]/', '', $run['original_filename']);
if ($safeName === '') $safeName = 'backtest.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
