<?php
// api/run_batch_prophet.php
// Runs Prophet forecast for multiple products in sequence.
// Returns per-product forecast + historical arrays; does NOT run Newsvendor.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/flask_client.php';
header('Content-Type: application/json');

set_time_limit(600);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/events.query.php';

$userId = (int) $_SESSION['user_id'];
$body   = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$productIds = is_array($body['product_ids'] ?? null) ? $body['product_ids'] : [];
$fromDate   = $body['from_date'] ?? '';
$toDate     = $body['to_date']   ?? '';

if (empty($productIds)) {
    echo json_encode(['error' => 'No products provided.']);
    exit;
}

if (!$fromDate || !$toDate || $fromDate >= $toDate) {
    echo json_encode(['error' => 'Invalid date range.']);
    exit;
}

$results = [];

foreach ($productIds as $rawId) {
    $productId = (int) $rawId;
    if ($productId <= 0) continue;

    $stmt = $pdo->prepare('SELECT id, name FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([$productId, $userId]);
    $productRow = $stmt->fetch();
    if (!$productRow) {
        $results[] = ['product_id' => $productId, 'success' => false, 'error' => 'Product not found.'];
        continue;
    }

    $startedAt = date('Y-m-d H:i:s');
    [$fcResult, , $fcErr] = flaskRequest('/forecast/product', json_encode([
        'user_id'    => $userId,
        'product_id' => $productId,
        'from_date'  => $fromDate,
        'to_date'    => $toDate,
    ]), 120);

    if ($fcErr || !$fcResult) {
        $results[] = ['product_id' => $productId, 'product_name' => $productRow['name'], 'success' => false, 'error' => 'Forecast server unreachable.'];
        continue;
    }

    $fcData = json_decode($fcResult, true);
    if (!is_array($fcData) || !empty($fcData['error'])) {
        $results[] = ['product_id' => $productId, 'product_name' => $productRow['name'], 'success' => false, 'error' => $fcData['error'] ?? 'Forecast failed.'];
        continue;
    }

    $forecastRows = $fcData['forecast'] ?? [];
    if (empty($forecastRows)) {
        $results[] = ['product_id' => $productId, 'product_name' => $productRow['name'], 'success' => false, 'error' => 'No forecast data returned.'];
        continue;
    }

    markProductForecastRun($pdo, $productId, $startedAt);

    // Persist event regressor coefficients — same side-effect as the single-product flow.
    if (!empty($fcData['event_coefficients'])) {
        upsertEventImpactCache($pdo, $productId, $fcData['event_coefficients']);
        foreach (array_unique(array_column($fcData['event_coefficients'], 'event_id')) as $eid) {
            refreshEventAvgImpact($pdo, (int) $eid);
        }
    }

    $results[] = [
        'product_id'         => $productId,
        'product_name'       => $productRow['name'],
        'success'            => true,
        'forecast'           => $forecastRows,
        'historical'         => $fcData['historical']         ?? [],
        'event_coefficients' => $fcData['event_coefficients'] ?? [],
    ];
}

echo json_encode(['results' => $results]);
