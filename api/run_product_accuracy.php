<?php
// api/run_product_accuracy.php
// AJAX bridge: triggers a per-product forecast backtest via Flask, caches the
// result on products.accuracy_*, and returns the cached values to the caller.
//
// Behaviour:
//   - If a fresh cached value exists, returns it immediately (no Flask call).
//   - If cache is empty OR ?refresh=1, calls Flask /forecast/product/evaluate,
//     persists the result, and returns it.
//
// Input:  POST product_id (int), optional refresh=1
// Output: { accuracy_pct, mae, horizon_days, residual_rho, computed_at }
//      OR { error: string, available_days?: int }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
$refresh   = !empty($_POST['refresh']);
$userId    = (int) $_SESSION['user_id'];

if (!$productId) {
    echo json_encode(['error' => 'No product specified.']);
    exit;
}

// Serve from cache when present and not explicitly refreshed.
if (!$refresh) {
    $cached = getProductAccuracy($pdo, $userId, $productId);
    if ($cached && $cached['accuracy_pct'] !== null) {
        echo json_encode([
            'cached'        => true,
            'accuracy_pct'  => (float) $cached['accuracy_pct'],
            'mape'          => $cached['accuracy_mape']         !== null ? (float) $cached['accuracy_mape']         : null,
            'mae'           => $cached['accuracy_mae']          !== null ? (float) $cached['accuracy_mae']          : null,
            'rmse'          => $cached['accuracy_rmse']         !== null ? (float) $cached['accuracy_rmse']         : null,
            'horizon_days'  => $cached['accuracy_horizon_days'] !== null ? (int)   $cached['accuracy_horizon_days'] : null,
            'residual_rho'  => $cached['accuracy_residual_rho'] !== null ? (float) $cached['accuracy_residual_rho'] : 0.0,
            'computed_at'   => $cached['accuracy_computed_at'],
        ]);
        exit;
    }
}

// Cache miss or forced refresh — call Flask.
$payload = json_encode([
    'user_id'    => $userId,
    'product_id' => $productId,
]);

$ch = curl_init('http://localhost:5000/forecast/product/evaluate');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['error' => 'Cannot reach the forecast server. Make sure python/app.py is running.']);
    exit;
}

$decoded = json_decode($result, true);
if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid response from forecast server.']);
    exit;
}

// Propagate explicit errors (e.g. insufficient history) without persisting.
if (isset($decoded['error'])) {
    http_response_code($httpCode);
    echo $result;
    exit;
}

// Persist and return.
saveProductAccuracy(
    $pdo, $userId, $productId,
    (float) $decoded['accuracy_pct'],
    (float) $decoded['mape'],
    (float) $decoded['mae'],
    (float) $decoded['rmse'],
    (int)   $decoded['horizon_days'],
    (float) $decoded['residual_rho']
);

$saved = getProductAccuracy($pdo, $userId, $productId);
echo json_encode([
    'cached'        => false,
    'accuracy_pct'  => (float) $saved['accuracy_pct'],
    'mape'          => (float) $saved['accuracy_mape'],
    'mae'           => (float) $saved['accuracy_mae'],
    'rmse'          => (float) $saved['accuracy_rmse'],
    'horizon_days'  => (int)   $saved['accuracy_horizon_days'],
    'residual_rho'  => (float) $saved['accuracy_residual_rho'],
    'computed_at'   => $saved['accuracy_computed_at'],
]);
