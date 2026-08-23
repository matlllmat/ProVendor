<?php
// api/catalogue_products.php
// Returns the session user's full product catalogue (id, name, cost, price) as
// JSON. Used by the post-import auto-forecast on the landing page, which needs
// the product list to forecast each one.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$products = getProducts($pdo, (int) $_SESSION['user_id'], '', '');

$out = array_map(function ($p) {
    return [
        'id'      => (int) $p['id'],
        'name'    => $p['name'],
        'cost'    => $p['cost_price']    !== null ? (float) $p['cost_price']    : null,
        'price'   => $p['selling_price'] !== null ? (float) $p['selling_price'] : null,
        // Per-product horizon override (null = use the user's global horizon).
        'horizon' => $p['forecast_horizon_days'] !== null ? (int) $p['forecast_horizon_days'] : null,
    ];
}, $products);

echo json_encode(['products' => $out]);
