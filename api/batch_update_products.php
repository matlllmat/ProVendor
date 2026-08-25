<?php
// api/batch_update_products.php
// Applies cost / selling price / current-stock edits to many products in one go
// (the "Batch edit" table on the forecast page), so an owner with incomplete
// imported data can fill everything in once instead of product by product.
//
// Per product it:
//   1. saves the effective price to `products` (orig_* is left alone, so
//      "reset to imported" keeps working exactly as it does per-product), then
//   2. re-runs the Newsvendor optimization against that product's ALREADY SAVED
//      forecast and re-saves it, so the restock qty / est. profit / current stock
//      reflect the new numbers.
//
// Prophet is NOT re-run — the demand forecast doesn't change when a price or a
// stock count changes, only the order quantity derived from it. That keeps this
// fast enough to do synchronously.
//
// Input  (JSON): { items: [ { id, cost_price, selling_price, current_stock }, ... ] }
// Output (JSON): { success, updated, repriced, skipped, failed, results: [...] }

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

if (!is_array($body) || empty($body['items']) || !is_array($body['items'])) {
    echo json_encode(['error' => 'No products submitted.']);
    exit;
}

// Everything needed from the session has been read. Release the lock so this
// user's other requests (page loads, the progress poll) aren't blocked while a
// long batch runs.
session_write_close();

$results  = [];
$updated  = 0;   // pricing written
$repriced = 0;   // Newsvendor re-run against the saved forecast
$skipped  = 0;   // valid, but nothing to re-optimize (no forecast yet)
$failed   = 0;

foreach ($body['items'] as $item) {
    $productId = (int)   ($item['id']            ?? 0);
    $cost      = (float) ($item['cost_price']    ?? 0);
    $price     = (float) ($item['selling_price'] ?? 0);
    $stock     = max(0, (int) ($item['current_stock'] ?? 0));

    if ($productId <= 0) { $failed++; continue; }

    // Ownership — the UPDATE is scoped by user_id too, but reject explicitly
    // rather than reporting a false success for someone else's product.
    $own = $pdo->prepare('SELECT name FROM products WHERE id = ? AND user_id = ? LIMIT 1');
    $own->execute([$productId, $userId]);
    $name = $own->fetchColumn();
    if ($name === false) {
        $failed++;
        $results[] = ['id' => $productId, 'ok' => false, 'error' => 'Product not found.'];
        continue;
    }

    // Same margin rule the per-product form uses.
    if ($cost <= 0 || $price <= $cost) {
        $failed++;
        $results[] = [
            'id' => $productId, 'name' => $name, 'ok' => false,
            'error' => 'Selling price must be greater than cost price, and cost above 0.',
        ];
        continue;
    }

    setProductPricing($pdo, $userId, $productId, $cost, $price);
    $updated++;

    // Re-optimize against the saved forecast. A product that has never been
    // forecast simply keeps its new price for the next forecast run.
    $saved = getProductForecast($pdo, $userId, $productId);
    if ($saved === null || empty($saved['forecast'])) {
        $skipped++;
        $results[] = ['id' => $productId, 'name' => $name, 'ok' => true, 'note' => 'Price saved (not forecast yet).'];
        continue;
    }

    $opt = runOptimize($pdo, $userId, $productId, $saved['forecast'], $cost, $price, $stock);
    if ($opt === null) {
        // Pricing is already saved; only the re-optimization failed.
        $results[] = [
            'id' => $productId, 'name' => $name, 'ok' => true,
            'note' => 'Price saved, but the restock could not be recalculated (is the forecast server running?).',
        ];
        continue;
    }

    $repriced++;
    $results[] = [
        'id'          => $productId,
        'name'        => $name,
        'ok'          => true,
        'restock_qty' => (int)   ($opt['restock_qty'] ?? 0),
        'demand'      => (int)   round($opt['total_predicted'] ?? 0),
        'est_profit'  => (float) ($opt['est_profit']  ?? 0),
    ];
}

echo json_encode([
    'success'  => true,
    'updated'  => $updated,
    'repriced' => $repriced,
    'skipped'  => $skipped,
    'failed'   => $failed,
    'results'  => $results,
]);


// Runs Flask /optimize for one product and re-saves its forecast with the new
// cost / price / stock. Returns the optimize payload, or null if it failed.
// (Mirrors the per-product path in api/run_batch_newsvendor.php, but reads the
// forecast from the DB instead of taking it from the request.)
function runOptimize(PDO $pdo, int $userId, int $productId, array $forecastRows, float $cost, float $price, int $stock): ?array
{
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
            'cost_price'    => $cost,
            'selling_price' => $price,
            'current_stock' => $stock,
            'residual_rho'  => $residualRho,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$raw) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !empty($data['error'])) return null;

    saveForecastRows(
        $pdo, $productId, $forecastRows,
        (int)   ($data['restock_qty']   ?? 0),
        $cost, $price, $stock,
        (float) ($data['total_std']     ?? 0.0),
        (int)   ($data['optimal_total'] ?? 0),
        (float) ($data['est_profit']    ?? 0.0),
        isset($data['rho_used'])             ? (float) $data['rho_used']             : null,
        isset($data['std_inflation_factor']) ? (float) $data['std_inflation_factor'] : null
    );

    return $data;
}
