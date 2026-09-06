<?php
// api/run_backtest_upload.php
// Backtests forecast accuracy against sales data the user has that ISN'T in
// ProVendor yet — e.g. a week held back on purpose, or results that came in
// after the last import. Each matched product is trained on its full existing
// history and asked to predict the uploaded dates; the upload's quantities are
// then treated as ground truth to score against.
//
// Only the MOST RECENT run is kept: the catalogue-wide result is saved to
// backtest_runs (overwriting any previous one) and the uploaded CSV itself is
// kept at uploads/backtest_<user_id>.csv so it can be re-downloaded later — see
// api/download_backtest_csv.php and api/clear_backtest.php.
//
// Input  (multipart POST): csv (file) — columns: product, date, quantity
//                           (any reasonable header names; auto-detected)
// Output (JSON): { catalogue: {...}, products: [...], skipped: [...],
//                  unmatched_products: [...], rows_parsed, rows_dropped,
//                  dropped_samples }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/import_helpers.php';
header('Content-Type: application/json');

set_time_limit(600);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error.']);
    exit;
}

$file = $_FILES['csv'];

if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
    echo json_encode(['error' => 'Only .csv files are accepted.']);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['error' => 'File exceeds the 10 MB limit.']);
    exit;
}

// Excel on Windows writes CSV as ANSI (Windows-1252), which is invalid UTF-8 —
// normalize in memory the same way detect.php does for the main importer.
$raw = file_get_contents($file['tmp_name']);
if ($raw !== false && !mb_check_encoding($raw, 'UTF-8')) {
    $converted = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    if ($converted !== '') $raw = $converted;
}

$handle = fopen('php://temp', 'r+');
fwrite($handle, $raw);
rewind($handle);

$headers = fgetcsv($handle);
if (!$headers) {
    fclose($handle);
    echo json_encode(['error' => 'CSV appears to be empty or has no headers.']);
    exit;
}
$headers = array_map('trim', $headers);
if (!empty($headers[0]) && str_starts_with($headers[0], "\xEF\xBB\xBF")) {
    $headers[0] = substr($headers[0], 3);
}

$allRows = [];
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($headers)) continue;
    $allRows[] = array_combine($headers, $row);
}
fclose($handle);

if (empty($allRows)) {
    echo json_encode(['error' => 'No data rows found in the CSV.']);
    exit;
}

// ── Detect columns + date format from a sample, same approach as the main importer ──
$sample  = array_slice($allRows, 0, 50);
$mapping = detectColumnMapping($headers, $sample);

if (!$mapping['date'] || !$mapping['product'] || !$mapping['quantity']) {
    echo json_encode([
        'error' => 'Could not find product, date, and quantity columns in this CSV. '
                 . 'Expected headers like "product", "date", and "quantity".',
    ]);
    exit;
}

$dateFormat = sniffDateFormat(array_column($sample, $mapping['date']));

// ── Parse + validate every row, aggregating same (product, date) into one total ──
$aggregated     = [];
$dropped        = 0;
$droppedSamples = [];
$rowNum         = 1;

foreach ($allRows as $r) {
    $rowNum++;
    $productName = trim((string) ($r[$mapping['product']]  ?? ''));
    $dateRaw     = trim((string) ($r[$mapping['date']]     ?? ''));
    $qtyRaw      = $r[$mapping['quantity']] ?? '';

    $reason = null;
    if ($productName === '')                    $reason = 'Missing product name';
    elseif ($dateRaw === '')                     $reason = 'Missing date';
    elseif ($qtyRaw === '' || $qtyRaw === null)  $reason = 'Missing quantity';

    $date = $reason ? null : normalizeDateStrict($dateRaw, $dateFormat['format']);
    if (!$reason && $date === null) {
        $reason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
    }
    if (!$reason && (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw != (int) $qtyRaw || (int) $qtyRaw > 999999)) {
        $reason = 'Quantity must be a whole number between 1 and 999,999';
    }

    if ($reason) {
        $dropped++;
        if (count($droppedSamples) < 10) {
            $droppedSamples[] = ['row' => $rowNum, 'product' => $productName ?: '(empty)', 'date' => $dateRaw ?: '(empty)', 'reason' => $reason];
        }
        continue;
    }

    $key = mb_strtolower($productName) . '|' . $date;
    if (!isset($aggregated[$key])) {
        $aggregated[$key] = ['product' => $productName, 'date' => $date, 'qty' => 0];
    }
    $aggregated[$key]['qty'] += (int) $qtyRaw;
}

if (empty($aggregated)) {
    echo json_encode([
        'error'           => 'No valid rows to test.',
        'rows_dropped'    => $dropped,
        'dropped_samples' => $droppedSamples,
    ]);
    exit;
}

// ── Match uploaded product names against the user's existing catalogue ──────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/backtest.query.php';
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, name, category FROM products WHERE user_id = ? AND is_active = 1');
$stmt->execute([$userId]);
$byNameLower = [];
foreach ($stmt->fetchAll() as $p) {
    $byNameLower[mb_strtolower(trim($p['name']))] = $p;
}

$byProductId       = []; // product_id => ['name' => .., 'category' => .., 'actuals' => [['date'=>,'qty'=>], ...]]
$unmatchedProducts = [];

foreach ($aggregated as $row) {
    $nameKey = mb_strtolower($row['product']);
    if (!isset($byNameLower[$nameKey])) {
        $unmatchedProducts[$row['product']] = true;
        continue;
    }
    $product = $byNameLower[$nameKey];
    $pid     = (int) $product['id'];
    if (!isset($byProductId[$pid])) {
        $byProductId[$pid] = ['name' => $product['name'], 'category' => $product['category'], 'actuals' => []];
    }
    $byProductId[$pid]['actuals'][] = ['date' => $row['date'], 'qty' => $row['qty']];
}

if (empty($byProductId)) {
    echo json_encode([
        'error'              => 'None of the uploaded product names match your catalogue.',
        'unmatched_products' => array_keys($unmatchedProducts),
        'rows_dropped'       => $dropped,
        'dropped_samples'    => $droppedSamples,
    ]);
    exit;
}

$productIds = array_keys($byProductId);
$placeholders = implode(',', array_fill(0, count($productIds), '?'));

// Existing sale dates per product — uploaded rows that land on a date already
// in the system aren't a genuine "future" test, so they're excluded rather
// than silently counted as if the model had never seen them.
$stmt = $pdo->prepare("SELECT product_id, sale_date FROM sales WHERE product_id IN ($placeholders)");
$stmt->execute($productIds);
$existingDates = [];
foreach ($stmt->fetchAll() as $r) {
    $existingDates[(int) $r['product_id']][$r['sale_date']] = true;
}

// Lifetime volume per product — used both for the on-screen "avg/day" figure
// and as the weight in the catalogue-wide accuracy average (a 500-unit/day
// product's error matters more than a 2-unit/day product's).
$stmt = $pdo->prepare(
    "SELECT product_id, SUM(quantity_sold) AS total_units, COUNT(DISTINCT sale_date) AS sale_days
     FROM sales WHERE product_id IN ($placeholders) GROUP BY product_id"
);
$stmt->execute($productIds);
$volumeByProduct = [];
foreach ($stmt->fetchAll() as $r) {
    $volumeByProduct[(int) $r['product_id']] = $r;
}

// ── Run each matched product's backtest against the (filtered) uploaded actuals ──
$evaluated = [];
$skipped   = [];

foreach ($byProductId as $pid => $info) {
    $alreadyKnown = 0;
    $actuals      = [];
    foreach ($info['actuals'] as $a) {
        if (isset($existingDates[$pid][$a['date']])) {
            $alreadyKnown++;
            continue;
        }
        $actuals[] = $a;
    }

    if (empty($actuals)) {
        $skipped[] = ['name' => $info['name'], 'reason' => 'Every uploaded date for this product is already in your sales history.'];
        continue;
    }

    $ch = curl_init('http://localhost:5000/forecast/product/evaluate_upload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'user_id'    => $userId,
            'product_id' => $pid,
            'actuals'    => $actuals,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $result  = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $skipped[] = ['name' => $info['name'], 'reason' => 'Could not reach the forecast server.'];
        continue;
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded) || isset($decoded['error'])) {
        $skipped[] = ['name' => $info['name'], 'reason' => $decoded['error'] ?? 'Backtest failed.'];
        continue;
    }

    $vol      = $volumeByProduct[$pid] ?? ['total_units' => 0, 'sale_days' => 0];
    $avgDaily = $vol['sale_days'] > 0 ? (float) $vol['total_units'] / (int) $vol['sale_days'] : null;
    $accPct   = (float) $decoded['accuracy_pct'];
    $tone     = $accPct >= 80 ? 'good' : ($accPct >= 60 ? 'okay' : 'low');

    $evaluated[] = [
        'id'            => $pid,
        'name'          => $info['name'],
        'category'      => $info['category'],
        'avg_daily'     => $avgDaily,
        'total_units'   => (int) $vol['total_units'],
        'accuracy_pct'  => $accPct,
        'mape'          => (float) $decoded['mape'],
        'mae'           => (float) $decoded['mae'],
        'rmse'          => (float) $decoded['rmse'],
        'n_test_days'   => (int) $decoded['n_test_days'],
        'already_known' => $alreadyKnown,
        'tone'          => $tone,
        'tone_label'    => ['good' => 'Good', 'okay' => 'Fair', 'low' => 'Poor'][$tone],
    ];
}

// ── Catalogue-wide weighted average, same formula as the old cached summary ──
$weightSum = 0.0; $mapeNum = 0.0; $maeNum = 0.0; $rmseNum = 0.0;
foreach ($evaluated as $row) {
    $w = (float) $row['total_units'];
    if ($w <= 0) continue;
    $weightSum += $w;
    $mapeNum   += $w * $row['mape'];
    $maeNum    += $w * $row['mae'];
    $rmseNum   += $w * $row['rmse'];
}

$catalogue = [
    'evaluated_count'       => count($evaluated),
    'total_count'           => count($byProductId),
    'weighted_mape'         => $weightSum > 0 ? round($mapeNum / $weightSum, 2) : null,
    'weighted_mae'          => $weightSum > 0 ? round($maeNum  / $weightSum, 2) : null,
    'weighted_rmse'         => $weightSum > 0 ? round($rmseNum / $weightSum, 2) : null,
    'weighted_accuracy_pct' => $weightSum > 0 ? round(max(0.0, 100.0 - ($mapeNum / $weightSum)), 2) : null,
];

// Worst offenders first — same "impact" ordering as the old breakdown table.
usort($evaluated, function ($a, $b) {
    return ($b['mae'] * $b['total_units']) <=> ($a['mae'] * $a['total_units']);
});

// ── Save the raw CSV + the catalogue-wide result — overwrites any earlier run ──
$storedRelPath = 'uploads/backtest_' . $userId . '.csv';
file_put_contents(__DIR__ . '/../' . $storedRelPath, $raw);

saveBacktestRun($pdo, $userId, [
    'original_filename'     => mb_substr($file['name'], 0, 255),
    'stored_path'           => $storedRelPath,
    'evaluated_count'       => $catalogue['evaluated_count'],
    'total_count'           => $catalogue['total_count'],
    'weighted_mape'         => $catalogue['weighted_mape'],
    'weighted_mae'          => $catalogue['weighted_mae'],
    'weighted_rmse'         => $catalogue['weighted_rmse'],
    'weighted_accuracy_pct' => $catalogue['weighted_accuracy_pct'],
    'rows_parsed'           => count($allRows),
    'rows_dropped'          => $dropped,
]);

echo json_encode([
    'catalogue'          => $catalogue,
    'products'           => $evaluated,
    'skipped'            => $skipped,
    'unmatched_products' => array_keys($unmatchedProducts),
    'rows_parsed'        => count($allRows),
    'rows_dropped'       => $dropped,
    'dropped_samples'    => $droppedSamples,
]);
