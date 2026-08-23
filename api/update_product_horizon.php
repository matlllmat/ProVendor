<?php
// api/update_product_horizon.php
// Saves a product's per-product forecast horizon override (days).
// POST — CSRF verified by bootstrap.php.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
$days      = isset($_POST['forecast_horizon_days']) ? (int) $_POST['forecast_horizon_days'] : 0;

if ($productId <= 0) {
    echo json_encode(['error' => 'No product specified.']);
    exit;
}
if ($days < 1 || $days > 60) {
    echo json_encode(['error' => 'Range must be between 1 and 60 days.']);
    exit;
}

$own = $pdo->prepare('SELECT 1 FROM products WHERE id = ? AND user_id = ? LIMIT 1');
$own->execute([$productId, (int) $_SESSION['user_id']]);
if (!$own->fetchColumn()) {
    echo json_encode(['error' => 'Product not found.']);
    exit;
}

setProductHorizon($pdo, (int) $_SESSION['user_id'], $productId, $days);

echo json_encode(['success' => true, 'forecast_horizon_days' => $days]);
