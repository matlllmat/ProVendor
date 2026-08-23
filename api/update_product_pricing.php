<?php
// api/update_product_pricing.php
// Saves a product's EFFECTIVE cost/selling price (edited on the forecast page so the
// Newsvendor profit estimate is accurate). orig_cost_price/orig_selling_price are left
// intact so the forecast page can reset back to the imported price (by passing those
// values here). POST — CSRF verified by bootstrap.php.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$productId = isset($_POST['product_id'])    ? (int)   $_POST['product_id']    : 0;
$cost      = isset($_POST['cost_price'])    ? (float) $_POST['cost_price']    : 0;
$price     = isset($_POST['selling_price']) ? (float) $_POST['selling_price'] : 0;

if ($productId <= 0) {
    echo json_encode(['error' => 'No product specified.']);
    exit;
}
// Same rule the Newsvendor form uses: a positive margin is required.
if ($cost <= 0)      { echo json_encode(['error' => 'Cost price must be greater than 0.']);            exit; }
if ($price <= $cost) { echo json_encode(['error' => 'Selling price must be greater than cost price.']); exit; }

// Ownership check — the UPDATE's WHERE already scopes to this user, but reject an
// unknown/other-user product explicitly rather than reporting a false success.
$own = $pdo->prepare('SELECT 1 FROM products WHERE id = ? AND user_id = ? LIMIT 1');
$own->execute([$productId, (int) $_SESSION['user_id']]);
if (!$own->fetchColumn()) {
    echo json_encode(['error' => 'Product not found.']);
    exit;
}

setProductPricing($pdo, (int) $_SESSION['user_id'], $productId, $cost, $price);

echo json_encode(['success' => true, 'cost_price' => $cost, 'selling_price' => $price]);
