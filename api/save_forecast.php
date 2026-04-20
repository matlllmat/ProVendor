<?php
// api/save_forecast.php
// AJAX: saves a forecast result (all predicted dates) to the forecasts table.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$productId    = isset($_POST['product_id'])    ? (int)   $_POST['product_id']    : null;
$forecastJson = $_POST['forecast_data']        ?? '[]';
$restockQty   = isset($_POST['restock_qty'])   ? (int)   $_POST['restock_qty']   : 0;
$costPrice    = isset($_POST['cost_price'])    ? (float) $_POST['cost_price']    : 0.0;
$sellingPrice = isset($_POST['selling_price']) ? (float) $_POST['selling_price'] : 0.0;
$currentStock = isset($_POST['current_stock']) ? (int)   $_POST['current_stock'] : 0;
$totalStd     = isset($_POST['total_std'])     ? (float) $_POST['total_std']     : 0.0;
$optimalTotal = isset($_POST['optimal_total']) ? (int)   $_POST['optimal_total'] : 0;
$estProfit    = isset($_POST['est_profit'])    ? (float) $_POST['est_profit']    : 0.0;

if (!$productId) {
    echo json_encode(['error' => 'Missing product.']);
    exit;
}

$forecastRows = json_decode($forecastJson, true);
if (!is_array($forecastRows) || empty($forecastRows)) {
    echo json_encode(['error' => 'No forecast data to save.']);
    exit;
}

// Verify the product belongs to this user before writing.
$stmt = $pdo->prepare('SELECT id FROM products WHERE id = ? AND user_id = ?');
$stmt->execute([$productId, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    echo json_encode(['error' => 'Product not found.']);
    exit;
}

saveForecastRows($pdo, $productId, $forecastRows, $restockQty,
    $costPrice, $sellingPrice, $currentStock, $totalStd, $optimalTotal, $estProfit);
echo json_encode(['success' => true]);
