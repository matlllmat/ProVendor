<?php
// api/run_catalogue_accuracy.php
// Primary, no-upload backtest: tests every active product against its own
// held-out recent history (same method/endpoint the old per-product "Refresh"
// button used) and returns the catalogue-wide MAPE/MAE/RMSE. This is what lets
// Reports show a real accuracy figure automatically — no CSV, no per-product
// clicking. Only products without a cached result are tested, so repeat calls
// (e.g. re-opening the tab) are cheap; pass refresh=1 to re-test everything.
//
// Input  (POST, optional): refresh=1
// Output (JSON): { catalogue: {...}, products: [...], tested, failed }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';
header('Content-Type: application/json');

set_time_limit(600);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$userId  = (int) $_SESSION['user_id'];
$refresh = !empty($_POST['refresh']);

$stmt = $pdo->prepare('SELECT id, accuracy_mape FROM products WHERE user_id = ? AND is_active = 1');
$stmt->execute([$userId]);

$toTest = [];
foreach ($stmt->fetchAll() as $p) {
    if ($refresh || $p['accuracy_mape'] === null) $toTest[] = (int) $p['id'];
}

$failed = 0;
foreach ($toTest as $productId) {
    $ch = curl_init('http://localhost:5000/forecast/product/evaluate');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['user_id' => $userId, 'product_id' => $productId]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $result  = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) { $failed++; continue; }

    $decoded = json_decode($result, true);
    if (!is_array($decoded) || isset($decoded['error'])) { $failed++; continue; }

    saveProductAccuracy(
        $pdo, $userId, $productId,
        (float) $decoded['accuracy_pct'],
        (float) $decoded['mape'],
        (float) $decoded['mae'],
        (float) $decoded['rmse'],
        (int)   $decoded['horizon_days'],
        (float) $decoded['residual_rho']
    );
}

// Normalize the per-product breakdown to the same row shape the upload-backtest
// endpoint returns, so the Reports tab can render both with one function.
$breakdown = [];
foreach (getProductAccuracyBreakdown($pdo, $userId) as $p) {
    $totalUnits = (int) $p['total_units'];
    $saleDays   = (int) $p['sale_days'];
    $pct        = $p['accuracy_pct'];
    $evaluated  = $pct !== null;
    $tone       = !$evaluated ? 'untested' : ((float) $pct >= 80 ? 'good' : ((float) $pct >= 60 ? 'okay' : 'low'));

    $breakdown[] = [
        'id'           => (int) $p['id'],
        'name'         => $p['name'],
        'category'     => $p['category'],
        'avg_daily'    => $saleDays > 0 ? $totalUnits / $saleDays : null,
        'total_units'  => $totalUnits,
        'mape'         => $p['accuracy_mape'] !== null ? (float) $p['accuracy_mape'] : null,
        'mae'          => $p['accuracy_mae']  !== null ? (float) $p['accuracy_mae']  : null,
        'rmse'         => $p['accuracy_rmse'] !== null ? (float) $p['accuracy_rmse'] : null,
        'accuracy_pct' => $evaluated ? (float) $pct : null,
        'tone'         => $tone,
        'tone_label'   => ['good' => 'Good', 'okay' => 'Fair', 'low' => 'Poor', 'untested' => 'Untested'][$tone],
    ];
}

echo json_encode([
    'catalogue' => getCatalogueAccuracy($pdo, $userId),
    'products'  => $breakdown,
    'tested'    => count($toTest) - $failed,
    'failed'    => $failed,
]);
