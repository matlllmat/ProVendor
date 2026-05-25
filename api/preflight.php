<?php
// api/preflight.php
// Pre-import validation: scans the temp CSV with the confirmed column mapping and
// returns a 3-bucket breakdown for the editor UI:
//   - new      : valid rows that don't exist in the DB yet (green)
//   - overlap  : valid rows whose (product, date) already exists with a different qty (yellow)
//   - invalid  : rows that can't be accepted, with the reason (red)
// Same-qty overlaps are silent no-ops — surfacing them as decisions would be noise.
// Writes nothing to the DB.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/import_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

if (empty($_SESSION['temp_csv']) || !file_exists($_SESSION['temp_csv'])) {
    echo json_encode(['error' => 'No uploaded file found. Please re-upload your CSV.']);
    exit;
}

$mapping = json_decode($_POST['mapping'] ?? '{}', true);
if (empty($mapping['date']) || empty($mapping['product']) || empty($mapping['quantity'])) {
    echo json_encode(['error' => 'Required columns not mapped.']);
    exit;
}

$colDate    = $mapping['date'];
$colProduct = $mapping['product'];
$colQty     = $mapping['quantity'];

// ── Scan CSV ──────────────────────────────────────────────────────────────────
$handle = fopen($_SESSION['temp_csv'], 'r');
if (!$handle) {
    echo json_encode(['error' => 'Could not read the uploaded file.']);
    exit;
}

$headers = array_map('trim', fgetcsv($handle));

foreach ([$colDate, $colProduct, $colQty] as $col) {
    if (!in_array($col, $headers)) {
        fclose($handle);
        echo json_encode(['error' => "Mapped column \"$col\" not found in CSV."]);
        exit;
    }
}

// Sniff the date format BEFORE rejecting anything — see api/import_helpers.php.
$dateColIdx   = array_search($colDate, $headers, true);
$dateSamples  = [];
$bufferedRows = [];
while (($row = fgetcsv($handle)) !== false) {
    $bufferedRows[] = $row;
    if ($dateColIdx !== false && count($row) === count($headers)) {
        $v = trim((string) ($row[$dateColIdx] ?? ''));
        if ($v !== '' && count($dateSamples) < 50) $dateSamples[] = $v;
    }
    if (count($bufferedRows) >= 50 && count($dateSamples) >= 50) break;
}
while (($row = fgetcsv($handle)) !== false) {
    $bufferedRows[] = $row;
}
fclose($handle);

$dateFormat = sniffDateFormat($dateSamples);
$_SESSION['temp_csv_date_format'] = $dateFormat;

// ── First pass: parse rows + classify by validity ─────────────────────────────
// We collect valid rows by their (product_name, date) key so we can later
// aggregate same-key CSV rows into a single record before comparing with the DB.
$invalid        = []; // [['row', 'product', 'date', 'qty', 'reason'], ...]
$validByPair    = []; // pairKey => ['product_name', 'date', 'qty', 'first_row']
$rowNum         = 1;

foreach ($bufferedRows as $row) {
    $rowNum++;
    if (count($row) !== count($headers)) {
        $invalid[] = [
            'row'     => $rowNum,
            'product' => '',
            'date'    => '',
            'qty'     => '',
            'reason'  => 'Row has wrong number of columns',
        ];
        continue;
    }

    $r           = array_combine($headers, $row);
    $productName = trim($r[$colProduct] ?? '');
    $dateRaw     = trim($r[$colDate]    ?? '');
    $qtyRaw      = trim($r[$colQty]     ?? '');

    $reason = null;
    if ($productName === '')      $reason = 'Missing product name';
    elseif ($dateRaw === '')      $reason = 'Missing date';
    elseif ($qtyRaw === '')       $reason = 'Missing quantity';

    $date = $reason ? null : normalizeDateStrict($dateRaw, $dateFormat['format']);
    if (!$reason && $date === null) {
        $reason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
    }
    if (!$reason && (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw != (int) $qtyRaw)) {
        $reason = 'Quantity must be a whole number greater than 0 (got "' . mb_substr($qtyRaw, 0, 20) . '")';
    }

    if ($reason) {
        $invalid[] = [
            'row'     => $rowNum,
            'product' => $productName ?: '(empty)',
            'date'    => $dateRaw    ?: '(empty)',
            'qty'     => $qtyRaw     ?: '(empty)',
            'reason'  => $reason,
        ];
        continue;
    }

    $qty     = (int) $qtyRaw;
    $pairKey = $productName . '|' . $date;

    // Aggregate same-key CSV rows so the preview reflects what would actually
    // be inserted (one row per product/day).
    if (isset($validByPair[$pairKey])) {
        $validByPair[$pairKey]['qty'] += $qty;
    } else {
        $validByPair[$pairKey] = [
            'product_name' => $productName,
            'date'         => $date,
            'qty'          => $qty,
            'first_row'    => $rowNum,
        ];
    }
}

// ── Second pass: classify each valid pair as new / overlap-changed / no-op ────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';

// Existing sales for this user, keyed by "<product_name>|<sale_date>" so we can
// compare against the CSV's product names without needing a pre-existing
// product_id lookup (new products simply don't match).
$existing = getExistingSalesByName($pdo, $_SESSION['user_id']);

$newRows      = [];
$overlapRows  = [];
$noopCount    = 0;
$minDate      = null;
$maxDate      = null;

foreach ($validByPair as $pairKey => $v) {
    if ($minDate === null || $v['date'] < $minDate) $minDate = $v['date'];
    if ($maxDate === null || $v['date'] > $maxDate) $maxDate = $v['date'];

    $existingKey = $v['product_name'] . '|' . $v['date'];
    if (!isset($existing[$existingKey])) {
        $newRows[] = [
            'row'     => $v['first_row'],
            'product' => $v['product_name'],
            'date'    => $v['date'],
            'qty'     => $v['qty'],
        ];
        continue;
    }

    $existingQty = (int) $existing[$existingKey];
    if ($existingQty === $v['qty']) {
        $noopCount++;
        continue;
    }

    $overlapRows[] = [
        'row'          => $v['first_row'],
        'product'      => $v['product_name'],
        'date'         => $v['date'],
        'qty_new'      => $v['qty'],
        'qty_existing' => $existingQty,
    ];
}

// Cap sample rows at 50 each so the UI table stays scannable.
$sampleCap = 50;

echo json_encode([
    'date_format' => $dateFormat,
    'csv_rows'    => count($bufferedRows),
    'date_range'  => ['from' => $minDate, 'to' => $maxDate],
    'buckets' => [
        'new' => [
            'count'   => count($newRows),
            'samples' => array_slice($newRows, 0, $sampleCap),
        ],
        'overlap' => [
            'count'   => count($overlapRows),
            'samples' => array_slice($overlapRows, 0, $sampleCap),
        ],
        'invalid' => [
            'count'   => count($invalid),
            'samples' => array_slice($invalid, 0, $sampleCap),
        ],
        'noop' => [
            'count' => $noopCount,
        ],
    ],
]);
