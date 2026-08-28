<?php
// api/preflight.php
// Pre-import scan. Parses the temp CSV, classifies every aggregated (product, date)
// pair as new / overlap / invalid / noop, and returns the full row list so the UI
// can render an editable preview table. The user can tweak qty/date before commit;
// import.php re-validates whatever rows the client sends back.
//
// Response shape:
//   {
//     date_format: {...},
//     csv_rows:    total raw rows in the file,
//     summary:     { new, overlap, invalid, noop, total_aggregated },
//     rows:        [ { rowNum, product, date, qty, sku, category, subcategory,
//                      cost, price, status, existing_qty?, reason?, raw_date?,
//                      raw_qty?, agg_count }, ... ]
//   }

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

$colDate        = $mapping['date'];
$colProduct     = $mapping['product'];
$colQty         = $mapping['quantity'];
$colSku         = $mapping['sku']         ?? null;
$colCategory    = $mapping['category']    ?? null;
$colSubcategory = $mapping['subcategory'] ?? null;
$colCost        = $mapping['cost']        ?? null;
$colPrice       = $mapping['price']       ?? null;

// ── Read CSV ──────────────────────────────────────────────────────────────────
$handle = fopen($_SESSION['temp_csv'], 'r');
if (!$handle) {
    echo json_encode(['error' => 'Could not read the uploaded file.']);
    exit;
}

$headers = array_map('trim', fgetcsv($handle));
if (!empty($headers[0]) && str_starts_with($headers[0], "\xEF\xBB\xBF")) {
    $headers[0] = substr($headers[0], 3);
}
foreach ([$colDate, $colProduct, $colQty] as $col) {
    if (!in_array($col, $headers)) {
        fclose($handle);
        echo json_encode(['error' => "Mapped column \"$col\" not found in CSV."]);
        exit;
    }
}

// First pass: sniff date format from up to 50 sample values, then read rest.
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

// OVERRIDE WITH USER CHOICE IF PROVIDED
$userFormat = $_POST['date_format'] ?? 'auto';
if ($userFormat !== 'auto' && $userFormat !== '') {
    $dateFormat = [
        'format' => $userFormat,
        'ambiguous' => false
    ];
}

$_SESSION['temp_csv_date_format'] = $dateFormat;

// Kept for import.php: a Google Sheet import saves this mapping with the link so
// the 5-minute refresh can re-read the sheet without re-asking which column is
// which. Held server-side rather than posted back, so a page reload mid-wizard
// can't lose it.
$_SESSION['temp_csv_mapping'] = $mapping;

// ── Classify ──────────────────────────────────────────────────────────────────
// Invalid rows: collected individually (we can't aggregate without a valid key).
// Valid rows:   aggregated by "<product>|<date>" so preview matches what would
//               actually be inserted (the importer aggregates the same way).
$invalidRows    = [];
$validByPair    = []; // pairKey => row data
$recoveredCount = 0;  // valid rows whose date was parsed via the fallback, not the chosen format
$rowNum         = 1;

$pickField = function (array $r, ?string $col): ?string {
    if (!$col) return null;
    $v = trim((string) ($r[$col] ?? ''));
    return $v === '' ? null : $v;
};
$pickNumeric = function (array $r, ?string $col): ?float {
    if (!$col) return null;
    $v = $r[$col] ?? '';
    return is_numeric($v) ? (float) $v : null;
};

foreach ($bufferedRows as $row) {
    $rowNum++;
    if (count($row) !== count($headers)) {
        $invalidRows[] = [
            'rowNum'    => $rowNum,
            'agg_count' => 1,
            'product'   => '',
            'date'      => '',
            'qty'       => null,
            'raw_date'  => '',
            'raw_qty'   => '',
            'reason'    => 'Row has wrong number of columns',
            'status'    => 'invalid',
        ];
        continue;
    }

    $r           = array_combine($headers, $row);
    $productName = trim($r[$colProduct] ?? '');
    $dateRaw     = trim($r[$colDate]    ?? '');
    $qtyRaw      = trim($r[$colQty]     ?? '');

    $reason = null;
    if ($productName === '')           $reason = 'Missing product name';
    elseif (mb_strlen($productName) > 100) $reason = 'Product name exceeds 100 characters';
    elseif ($dateRaw === '')           $reason = 'Missing date';
    elseif ($qtyRaw === '')            $reason = 'Missing quantity';

    $usedFallback = false;
    $date = $reason ? null : normalizeDateStrict($dateRaw, $dateFormat['format'], $usedFallback);
    if (!$reason && $usedFallback && $date !== null) $recoveredCount++;
    if (!$reason && $date === null) {
        $reason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
    }
    if (!$reason && (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw != (int) $qtyRaw || (int) $qtyRaw > 999999)) {
        $reason = 'Quantity must be a whole number between 1 and 999,999 (got "' . mb_substr($qtyRaw, 0, 20) . '")';
    }

    if ($reason) {
        $invalidRows[] = [
            'rowNum'    => $rowNum,
            'agg_count' => 1,
            'product'   => $productName,
            'date'      => $dateRaw,        // show raw so user sees what they typed
            'qty'       => null,
            'raw_date'  => $dateRaw,
            'raw_qty'   => $qtyRaw,
            'reason'    => $reason,
            'status'    => 'invalid',
            // Optional fields preserved so the user can edit & re-submit without
            // losing sku/category/cost/price metadata that was valid on this row.
            'sku'         => $pickField($r, $colSku),
            'category'    => $pickField($r, $colCategory),
            'subcategory' => $pickField($r, $colSubcategory),
            'cost'        => $pickNumeric($r, $colCost),
            'price'       => $pickNumeric($r, $colPrice),
        ];
        continue;
    }

    $qty     = (int) $qtyRaw;
    $pairKey = mb_strtolower($productName) . '|' . $date;

    if (isset($validByPair[$pairKey])) {
        $validByPair[$pairKey]['qty']       += $qty;
        $validByPair[$pairKey]['agg_count'] += 1;
    } else {
        $validByPair[$pairKey] = [
            'rowNum'      => $rowNum,
            'agg_count'   => 1,
            'product'     => $productName,
            'date'        => $date,
            'qty'         => $qty,
            'sku'         => $pickField($r, $colSku),
            'category'    => $pickField($r, $colCategory),
            'subcategory' => $pickField($r, $colSubcategory),
            'cost'        => $pickNumeric($r, $colCost),
            'price'       => $pickNumeric($r, $colPrice),
        ];
    }
}

// ── Cross-check against existing DB rows ──────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';

$existing = getExistingSalesByName($pdo, $_SESSION['user_id']);

$newCount    = 0;
$overlapCount = 0;
$noopCount   = 0;
$outRows     = [];

foreach ($validByPair as $key => $v) {
    $existingKey = mb_strtolower($v['product']) . '|' . $v['date'];
    if (!isset($existing[$existingKey])) {
        $v['status'] = 'new';
        $newCount++;
    } elseif ((int) $existing[$existingKey] === $v['qty']) {
        $v['status']       = 'noop';
        $v['existing_qty'] = (int) $existing[$existingKey];
        $noopCount++;
    } else {
        $v['status']       = 'overlap';
        $v['existing_qty'] = (int) $existing[$existingKey];
        $overlapCount++;
    }
    $outRows[] = $v;
}

// Invalid rows go in as-is.
foreach ($invalidRows as $r) {
    $outRows[] = $r;
}

// Sort: invalid first (so user fixes them), then overlap, then new, then noop.
$statusOrder = ['invalid' => 0, 'overlap' => 1, 'new' => 2, 'noop' => 3];
usort($outRows, function ($a, $b) use ($statusOrder) {
    $sa = $statusOrder[$a['status']] ?? 9;
    $sb = $statusOrder[$b['status']] ?? 9;
    if ($sa !== $sb) return $sa - $sb;
    return $a['rowNum'] - $b['rowNum'];
});

echo json_encode([
    'date_format'     => $dateFormat,
    'csv_rows'        => count($bufferedRows),
    'recovered_count' => $recoveredCount,
    'summary'         => [
        'new'              => $newCount,
        'overlap'          => $overlapCount,
        'invalid'          => count($invalidRows),
        'noop'             => $noopCount,
        'total_aggregated' => count($outRows),
    ],
    'rows' => $outRows,
]);
