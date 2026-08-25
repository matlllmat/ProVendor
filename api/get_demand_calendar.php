<?php
// api/get_demand_calendar.php
// AJAX: daily demand (actual + forecast) for the Demand Analysis calendar view.
// Read-only, no Flask — renders from saved data, so it works even when the ML
// server is down.
//
// POST params: product_id (optional) OR category (optional, empty = all products).
// Output: { days: { 'YYYY-MM-DD': {a, p} }, min_date, max_date, forecast_products }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$productId = (isset($_POST['product_id']) && $_POST['product_id'] !== '')
    ? (int) $_POST['product_id']
    : null;
$category  = trim($_POST['category'] ?? '');

echo json_encode(
    getDemandCalendarData($pdo, (int) $_SESSION['user_id'], $productId, $category)
);
