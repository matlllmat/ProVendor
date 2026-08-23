<?php
// api/forecast_job_status.php
// Progress for the user's latest background forecast job. Polled by the floating
// progress pill (includes/forecast_progress.php) — GET, read-only, no CSRF.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/job.query.php';

$job = getLatestForecastJob($pdo, (int) $_SESSION['user_id']);

if ($job === null) {
    echo json_encode(['job' => null]);
    exit;
}

$total = (int) $job['total'];
$done  = (int) $job['done'];

echo json_encode(['job' => [
    'id'              => (int) $job['id'],
    'status'          => $job['status'],
    'total'           => $total,
    'done'            => $done,
    'failed'          => (int) $job['failed'],
    'percent'         => $total > 0 ? (int) round($done / $total * 100) : 0,
    'current_product' => $job['current_product'],
    'error'           => $job['error'],
    'finished_at'     => $job['finished_at'],
]]);
