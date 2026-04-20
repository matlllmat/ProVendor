<?php
// api/get_forecast_detail.php
// AJAX: returns historical sales + saved forecast rows for one session.
// Used by the Reports page detail modal to render the chart.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/reports.query.php';

$productId   = isset($_POST['product_id'])   ? (int)    $_POST['product_id']   : null;
$generatedAt = isset($_POST['generated_at']) ? trim($_POST['generated_at'])    : null;

if (!$productId || !$generatedAt) {
    echo json_encode(['error' => 'Missing parameters.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

$forecastRows = getForecastSessionRows($pdo, $productId, $generatedAt, $userId);

if (empty($forecastRows)) {
    echo json_encode(['error' => 'Forecast session not found.']);
    exit;
}

// Load historical sales up to the start of this forecast window.
$cutoffDate  = $forecastRows[0]['date'];
$historical  = getProductSalesUpTo($pdo, $productId, $cutoffDate, $userId);

// Extract session-level metadata from the first row (same value on every row).
$first = $forecastRows[0];
$meta  = [
    'restock_qty'   => (int)   $first['restock_qty'],
    'cost_price'    => $first['cost_price']    !== null ? (float) $first['cost_price']    : null,
    'selling_price' => $first['selling_price'] !== null ? (float) $first['selling_price'] : null,
    'current_stock' => $first['current_stock'] !== null ? (int)   $first['current_stock'] : null,
    'total_std'     => $first['total_std']     !== null ? (float) $first['total_std']     : null,
    'optimal_total' => $first['optimal_total'] !== null ? (int)   $first['optimal_total'] : null,
    'est_profit'    => $first['est_profit']    !== null ? (float) $first['est_profit']    : null,
];

// Cast historical and forecast to the right types; strip metadata from forecast rows.
foreach ($historical as &$row) {
    $row['actual'] = (float) $row['actual'];
}
unset($row);

$forecastData = array_map(function ($row) {
    return ['date' => $row['date'], 'predicted' => (float) $row['predicted']];
}, $forecastRows);

echo json_encode([
    'historical' => $historical,
    'forecast'   => $forecastData,
    'meta'       => $meta,
]);
