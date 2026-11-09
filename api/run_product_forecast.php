<?php
// api/run_product_forecast.php
// AJAX bridge: receives product_id + days from the forecast modal,
// calls Flask /forecast/product, and passes the JSON result back.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/flask_client.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$productId = isset($_POST['product_id']) ? (int)  $_POST['product_id'] : null;
$fromDate  = isset($_POST['from_date'])  ? trim($_POST['from_date'])  : null;
$toDate    = isset($_POST['to_date'])    ? trim($_POST['to_date'])    : null;

if (!$productId) {
    echo json_encode(['error' => 'No product selected.']);
    exit;
}
if (!$fromDate || !$toDate) {
    echo json_encode(['error' => 'Please select a forecast date range.']);
    exit;
}

$payload = json_encode([
    'user_id'    => (int) $_SESSION['user_id'],
    'product_id' => $productId,
    'from_date'  => $fromDate,
    'to_date'    => $toDate,
]);

[$result, $httpCode, $flaskError] = flaskRequest('/forecast/product', $payload, 120);

if ($flaskError) {
    echo json_encode(['error' => 'Cannot reach the forecast server. Make sure python/app.py is running.']);
    exit;
}

// Persist Prophet regressor coefficients to event_impact_cache (on success only).
// Strip event_coefficients from the response before forwarding — the frontend doesn't need them.
$decoded = json_decode($result, true);
if (is_array($decoded) && !empty($decoded['event_coefficients'])) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../queries/events.query.php';

    upsertEventImpactCache($pdo, $productId, $decoded['event_coefficients']);

    // Refresh the weighted-average impact badge for each affected event.
    $affectedIds = array_unique(array_column($decoded['event_coefficients'], 'event_id'));
    foreach ($affectedIds as $eid) {
        refreshEventAvgImpact($pdo, (int) $eid);
    }

    unset($decoded['event_coefficients']);
    $result = json_encode($decoded);
}

http_response_code($httpCode);
echo $result;
