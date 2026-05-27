<?php
// api/run_batch_newsvendor.php
// Runs Newsvendor optimization for multiple products using pre-computed forecast arrays.
// Saves each result to the forecasts table.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

set_time_limit(300);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$userId = (int) $_SESSION['user_id'];
$body   = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$productsIn = is_array($body['products'] ?? null) ? $body['products'] : [];

if (empty($productsIn)) {
    echo json_encode(['error' => 'No products provided.']);
    exit;
}

$results = [];

foreach ($productsIn as $p) {
    $productId    = (int)   ($p['id']            ?? 0);
    $costPrice    = (float) ($p['cost_price']    ?? 0);
    $sellingPrice = (float) ($p['selling_price'] ?? 0);
    $currentStock = (int)   ($p['current_stock'] ?? 0);
    $forecastRows = is_array($p['forecast'] ?? null) ? $p['forecast'] : [];

    if ($productId <= 0 || empty($forecastRows)) {
        $results[] = ['product_id' => $productId, 'success' => false, 'error' => 'Invalid product or empty forecast.'];
        continue;
    }

    $stmt = $pdo->prepare('SELECT id, name FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([$productId, $userId]);
    $productRow = $stmt->fetch();
    if (!$productRow) {
        $results[] = ['product_id' => $productId, 'success' => false, 'error' => 'Product not found.'];
        continue;
    }

    // Look up cached residual_rho for AR(1) σ correction.
    $residualRho = 0.0;
    $acc = getProductAccuracy($pdo, $userId, $productId);
    if ($acc && $acc['accuracy_residual_rho'] !== null) {
        $residualRho = (float) $acc['accuracy_residual_rho'];
    }

    $ch = curl_init('http://localhost:5000/optimize');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'forecast'      => $forecastRows,
            'cost_price'    => $costPrice,
            'selling_price' => $sellingPrice,
            'current_stock' => $currentStock,
            'residual_rho'  => $residualRho,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $optResult = curl_exec($ch);
    $optErr    = curl_error($ch);
    curl_close($ch);

    if ($optErr || !$optResult) {
        $results[] = ['product_id' => $productId, 'product_name' => $productRow['name'], 'success' => false, 'error' => 'Optimization server unreachable.'];
        continue;
    }

    $optData = json_decode($optResult, true);
    if (!is_array($optData) || !empty($optData['error'])) {
        $results[] = ['product_id' => $productId, 'product_name' => $productRow['name'], 'success' => false, 'error' => $optData['error'] ?? 'Optimization failed.'];
        continue;
    }

    saveForecastRows(
        $pdo, $productId, $forecastRows,
        (int)   ($optData['restock_qty']          ?? 0),
        $costPrice, $sellingPrice, $currentStock,
        (float) ($optData['total_std']            ?? 0.0),
        (int)   ($optData['optimal_total']        ?? 0),
        (float) ($optData['est_profit']           ?? 0.0),
        isset($optData['rho_used'])             ? (float) $optData['rho_used']             : null,
        isset($optData['std_inflation_factor']) ? (float) $optData['std_inflation_factor'] : null
    );

    $results[] = [
        'product_id'      => $productId,
        'product_name'    => $productRow['name'],
        'success'         => true,
        'total_predicted' => (int) round($optData['total_predicted'] ?? 0),
        'restock_qty'     => (int) ($optData['restock_qty']          ?? 0),
        'current_stock'   => $currentStock,
        'est_profit'      => (float) ($optData['est_profit']         ?? 0.0),
    ];
}

echo json_encode(['results' => $results]);
