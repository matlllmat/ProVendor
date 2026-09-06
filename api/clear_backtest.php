<?php
// api/clear_backtest.php
// Deletes the owner's saved "Backtest With Your Own Data" run — both the
// database row and the CSV kept on disk for re-download.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/backtest.query.php';

$path = deleteBacktestRun($pdo, (int) $_SESSION['user_id']);
if ($path) {
    $fullPath = __DIR__ . '/../' . $path;
    if (is_file($fullPath)) unlink($fullPath);
}

echo json_encode(['success' => true]);
