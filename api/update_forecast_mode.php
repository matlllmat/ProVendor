<?php
// api/update_forecast_mode.php
// Switches how the forecast window is kept current:
//   'manual' — it only moves when the owner presses "Re-forecast all products"
//   'auto'   — the app tops it back up to the horizon as days elapse
// POST: mode. CSRF verified by bootstrap.php.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

$mode = $_POST['mode'] ?? '';
if (!in_array($mode, ['manual', 'auto'], true)) {
    echo json_encode(['error' => 'Invalid mode.']);
    exit;
}

setForecastMode($pdo, (int) $_SESSION['user_id'], $mode);
echo json_encode(['success' => true, 'mode' => $mode]);
