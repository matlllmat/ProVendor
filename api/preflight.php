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
//     rows:        [ { rowNum, product, date, qty, sku, category,
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
$colCategories  = mappingCategoryColumns($mapping);
$colCost       = $mapping['cost']        ?? null;
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

// Read every row, keeping the date column so its format is judged on all of it.
$dateColIdx   = array_search($colDate, $headers, true);
$dateValues   = [];
$bufferedRows = [];
while (($row = fgetcsv($handle)) !== false) {
    $bufferedRows[] = $row;
    if ($dateColIdx !== false && count($row) === count($headers)) {
        $v = trim((string) ($row[$dateColIdx] ?? ''));
        if ($v !== '') $dateValues[] = $v;
    }
}
fclose($handle);

$dateFormat = sniffDateFormat($dateValues);
$dateIsSure = $dateFormat['format'] !== null && !$dateFormat['ambiguous'];

// When the system is sure, its answer stands. Otherwise the owner must choose,
// and a choice the dates contradict is sent back.
if (!$dateIsSure) {
    $userFormat = $_POST['date_format'] ?? 'auto';

    if (!isset(DATE_FORMAT_CHOICES[$userFormat])) {
        echo json_encode([
            'error' => 'We can\'t tell the date format of this file. Please choose it.',
            'code'  => 'date_format',
        ]);
        exit;
    }

    $problem = dateFormatProblem($dateValues, $userFormat);
    if ($problem !== null) {
        echo json_encode(['error' => $problem, 'code' => 'date_format']);
        exit;
    }

    $dateFormat = ['format' => $userFormat, 'ambiguous' => false];
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
$rowNum         = 1 + (int) ($_SESSION['temp_csv_row_offset'] ?? 0);

$pickField = function (array $r, ?string $col): ?string {
    if (!$col) return null;
    $v = trim((string) ($r[$col] ?? ''));
    return $v === '' ? null : $v;
};

// Money cells that only needed a ₱ or a thousands separator stripped. Lossless,
// so it's reported once at file level rather than flagged row by row.
$moneyReformatted = 0;

// Extracts the optional metadata (sku/categories/cost/price), clamped
// to what the DB columns can actually hold. Without this, a 60-character
// category or a price past DECIMAL(10,2) reached MySQL and threw, rolling back
// the entire import with a generic error and no indication of which row.
//
// Lossless repairs are applied silently; lossy ones (truncation, out-of-range
// money) are recorded in 'warnings' for the user to review. A bad optional
// field never invalidates the sale — quantity and date are the real data.
$pickOptional = function (array $r) use (
    $pickField, $colSku, $colCategories, $colCost, $colPrice, &$moneyReformatted
): array {
    $out      = [];
    $warnings = [];

    $shortenedWarning = fn(string $field, string $original, string $applied) => [
        'field'    => $field,
        'original' => $original,
        'applied'  => $applied,
        'reason'   => 'Shortened to ' . FIELD_LIMITS[$field] . ' characters to fit',
    ];

    [$sku, $original] = clampFieldLength($pickField($r, $colSku), 'sku');
    if ($original !== null) $warnings[] = $shortenedWarning('sku', $original, $sku);
    $out['sku'] = $sku;

    [$categories, $shortened] = parseCategories(array_map(fn($col) => $pickField($r, $col), $colCategories));
    foreach ($shortened as [$original, $applied]) {
        $warnings[] = $shortenedWarning('category', $original, $applied);
    }
    $out['category'] = $categories ? implode(', ', $categories) : null;

    foreach (['cost' => $colCost, 'price' => $colPrice] as $field => $col) {
        $raw = $col ? trim((string) ($r[$col] ?? '')) : '';
        [$parsed, $reformatted] = parseMoney($raw);
        [$value, $reason]       = clampMoney($parsed);

        if ($reformatted) $moneyReformatted++;

        if ($reason !== null) {
            $warnings[] = [
                'field'    => $field,
                'original' => $raw,
                'applied'  => null,
                'reason'   => 'Left empty — ' . $reason,
            ];
        } elseif ($parsed === null && $raw !== '') {
            $warnings[] = [
                'field'    => $field,
                'original' => $raw,
                'applied'  => null,
                'reason'   => 'Not a number — left empty',
            ];
        }

        $out[$field] = $value;
    }

    $out['warnings'] = $warnings;
    return $out;
};

foreach ($bufferedRows as $row) {
    $rowNum++;
    // A row with the wrong cell count is lined up by position so the owner can
    // see and repair it: extra cells are dropped, missing ones are empty.
    $wrongColumns = count($row) !== count($headers);
    $r = array_combine($headers, array_slice(array_pad(array_map('strval', $row), count($headers), ''), 0, count($headers)));

    $productName = trim($r[$colProduct] ?? '');
    $dateRaw     = trim($r[$colDate]    ?? '');
    $qtyRaw      = trim($r[$colQty]     ?? '');

    $reason = null;
    if ($productName === '')           $reason = 'Missing product name';
    elseif (mb_strlen($productName) > 100) $reason = 'Product name exceeds 100 characters';
    elseif ($dateRaw === '')           $reason = 'Missing date';
    elseif ($qtyRaw === '')            $reason = 'Missing quantity';

    $usedFallback = false;
    $date = $dateRaw === '' ? null : normalizeDateStrict($dateRaw, $dateFormat['format'], $usedFallback);
    if (!$reason && $usedFallback && $date !== null) $recoveredCount++;
    if (!$reason && $date === null) {
        $reason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
    }
    if (!$reason) $reason = futureDateReason($date);
    $qtyIsValid = is_numeric($qtyRaw) && (float) $qtyRaw > 0 && (float) $qtyRaw == (int) $qtyRaw && (int) $qtyRaw <= 999999;
    $qty = $qtyIsValid ? (int) $qtyRaw : null;
    if (!$reason && $qty === null && $qtyRaw !== '') {
        $reason = 'Quantity must be a whole number between 1 and 999,999 (got "' . mb_substr($qtyRaw, 0, 20) . '")';
    }
    if ($wrongColumns) $reason = 'Row has wrong number of columns';

    // Every warning on the row, even when something else already blocks it.
    $optional = $pickOptional($r);
    $optional['warnings'] = array_merge(
        $optional['warnings'],
        suspiciousValueWarnings($dateRaw, $date, $qtyRaw, $qty, $optional['cost'], $optional['price'])
    );

    if ($reason) {
        $invalidRows[] = [
            'rowNum'    => $rowNum,
            'agg_count' => 1,
            'product'   => $productName,
            'date'      => $date ?? $dateRaw,   // raw only when it couldn't be read
            'qty'       => null,
            'raw_date'  => $dateRaw,
            'raw_qty'   => $qty !== null ? (string) $qty : $qtyRaw,
            'reason'    => $reason,
            'wrong_columns' => $wrongColumns,
            'status'    => 'invalid',
            // Optional fields preserved so the user can edit & re-submit without
            // losing sku/category/cost/price metadata that was valid on this row.
        ] + $optional;
        continue;
    }

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
        ] + $optional;
    }
}

// ── Classify ─────────────────────────────────────────────────────────────────
// A store holds one dataset and this upload becomes it, so there is nothing to
// reconcile against: every valid row is simply imported. The old
// new/overlap/no-op classification described a merge that no longer happens —
// on a routine re-upload of the same export it would have reported every row as
// a "conflict", which is exactly the confusion the single-dataset model removes.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/../queries/version.query.php';

$newCount     = 0;
$overlapCount = 0;
$noopCount    = 0;
$outRows      = [];

foreach ($validByPair as $key => $v) {
    $v['status'] = 'new';
    $newCount++;
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

$warningsCount = 0;
foreach ($outRows as $r) {
    if (!empty($r['warnings'])) $warningsCount++;
}

// ── What this upload would replace ───────────────────────────────────────────
// Replacing is the one irreversible-feeling action in the app, so the commit
// screen has to be able to say exactly what changes. The dangerous case is a
// partial export — someone whose POS defaults to "last 30 days" uploading over
// five years of history — so the incoming and stored date ranges are compared
// directly rather than left for the owner to infer from row counts.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS rows_now, MIN(s.sale_date) AS first_date, MAX(s.sale_date) AS last_date,
            COUNT(DISTINCT s.product_id) AS products_now
     FROM sales s JOIN products p ON p.id = s.product_id
     WHERE p.user_id = ?'
);
$stmt->execute([$_SESSION['user_id']]);
$current = $stmt->fetch();

$incomingDates    = array_column(array_filter($outRows, fn($r) => $r['status'] !== 'invalid'), 'date');
$incomingProducts = [];
foreach ($outRows as $r) {
    if ($r['status'] !== 'invalid' && $r['product'] !== '') {
        $incomingProducts[mb_strtolower($r['product'])] = true;
    }
}

// Products that would drop out of the catalogue (kept, but deactivated).
$stmt = $pdo->prepare(
    'SELECT DISTINCT p.name FROM products p
     JOIN sales s ON s.product_id = p.id
     WHERE p.user_id = ? AND p.is_active = 1'
);
$stmt->execute([$_SESSION['user_id']]);
$leaving = [];
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
    if (!isset($incomingProducts[mb_strtolower($name)])) $leaving[] = $name;
}

$replaces = [
    'rows_now'         => (int) $current['rows_now'],
    'rows_incoming'    => $newCount,
    'products_now'     => (int) $current['products_now'],
    'products_incoming'=> count($incomingProducts),
    'first_date_now'   => $current['first_date'],
    'last_date_now'    => $current['last_date'],
    'first_date_new'   => $incomingDates ? min($incomingDates) : null,
    'last_date_new'    => $incomingDates ? max($incomingDates) : null,
    'products_leaving' => $leaving,
];
// The loud case: the incoming file covers a strictly narrower period than what's
// stored, i.e. history would be dropped rather than refreshed.
$replaces['narrows_history'] = $replaces['rows_now'] > 0
    && $replaces['first_date_new'] !== null
    && ($replaces['first_date_new'] > $current['first_date']
        || $replaces['last_date_new'] < $current['last_date']);

// Version retention: history is capped, so say which version this upload pushes
// out while the owner can still download it.
$versionsNow  = listDatasetVersions($pdo, (int) $_SESSION['user_id']);
$prunedByThis = null;
if (count($versionsNow) >= MAX_VERSIONS_PER_USER) {
    $oldest = $versionsNow[count($versionsNow) - 1];
    $prunedByThis = [
        'id'         => (int) $oldest['id'],
        'label'      => $oldest['label'],
        'total_rows' => (int) $oldest['total_rows'],
        'created_at' => $oldest['created_at'],
        'max'        => MAX_VERSIONS_PER_USER,
    ];
}

echo json_encode([
    'today'              => date('Y-m-d'),
    'replaces'           => $replaces,
    'pruned_version'     => $prunedByThis,
    'date_format'        => $dateFormat,
    'csv_rows'           => count($bufferedRows),
    'recovered_count'    => $dateIsSure ? 0 : $recoveredCount,
    'warnings_count'     => $warningsCount,
    'money_reformatted'  => $moneyReformatted,
    'encoding_converted' => !empty($_SESSION['temp_csv_encoding_conv']),
    'workbook_note'      => $_SESSION['temp_csv_workbook_note'] ?? null,
    'summary'         => [
        'new'              => $newCount,
        'overlap'          => $overlapCount,
        'invalid'          => count($invalidRows),
        'noop'             => $noopCount,
        'total_aggregated' => count($outRows),
    ],
    'rows' => $outRows,
]);
