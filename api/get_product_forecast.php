<?php
// api/get_product_forecast.php
// Returns a product's current saved forecast (per-day series + restock meta) so
// the forecast page can render the forecast-projection chart inline. Historical
// sales are loaded separately by get_sales_chart.php.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
if ($productId <= 0) {
    echo json_encode(['error' => 'No product specified.']);
    exit;
}

$data = getProductForecast($pdo, (int) $_SESSION['user_id'], $productId);

// Not an error — the product just hasn't been forecast yet. The frontend shows
// its historical sales only in that case.
if ($data === null) {
    echo json_encode(['forecast' => [], 'meta' => null]);
    exit;
}

echo json_encode($data);
