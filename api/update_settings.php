<?php
// api/update_settings.php
// Saves user settings. Currently just the global forecast horizon (days).
// POST — CSRF verified by bootstrap.php.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

$days = isset($_POST['forecast_horizon_days']) ? (int) $_POST['forecast_horizon_days'] : 0;

if ($days < 1 || $days > 60) {
    echo json_encode(['error' => 'Forecast range must be between 1 and 60 days.']);
    exit;
}

setForecastHorizon($pdo, (int) $_SESSION['user_id'], $days);

echo json_encode(['success' => true, 'forecast_horizon_days' => $days]);
