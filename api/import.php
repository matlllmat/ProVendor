<?php
// api/import.php
// Receives the confirmed column mapping from the landing page Step 3.
// Reads the temp CSV, inserts products + sales into the DB, saves the import session.
// Returns JSON { success: true } or { error: "..." }.

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

// ── Read inputs ───────────────────────────────────────────────────────────────
$mapping  = json_decode($_POST['mapping'] ?? '{}', true);
$csvRows  = (int) ($_POST['csv_rows'] ?? 0);
$replace  = ($_POST['replace'] ?? '0') === '1'; // replace overlapping records instead of skipping

// Validate required fields are mapped
if (empty($mapping['date']) || empty($mapping['product']) || empty($mapping['quantity'])) {
    echo json_encode(['error' => 'Date, Product, and Quantity columns must be mapped.']);
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

// ── Parse CSV ─────────────────────────────────────────────────────────────────
$tempPath = $_SESSION['temp_csv'];
$filename = $_SESSION['temp_csv_name'] ?? basename($tempPath);

$handle = fopen($tempPath, 'r');
if (!$handle) {
    echo json_encode(['error' => 'Could not read the uploaded file.']);
    exit;
}

$headers = array_map('trim', fgetcsv($handle));

// Validate mapped columns actually exist in the file
foreach (['date' => $colDate, 'product' => $colProduct, 'quantity' => $colQty] as $field => $col) {
    if (!in_array($col, $headers)) {
        fclose($handle);
        echo json_encode(['error' => "Mapped column \"$col\" not found in CSV."]);
        exit;
    }
}

// Read all data rows. Rows with the wrong column count are not silently dropped
// here — they're captured as the first class of "dropped" rows so the user can
// see them in the import response.
$dataRows      = [];   // [['rowNum' => int, 'data' => array<header,value>], ...]
$dropped       = 0;
$droppedSamples = [];
$rowNum        = 1; // header is row 1

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) !== count($headers)) {
        $dropped++;
        if (count($droppedSamples) < 10) {
            $droppedSamples[] = [
                'row'     => $rowNum,
                'product' => '',
                'date'    => '',
                'qty'     => '',
                'reason'  => 'Row has wrong number of columns',
            ];
        }
        continue;
    }
    $dataRows[] = ['rowNum' => $rowNum, 'data' => array_combine($headers, $row)];
}
fclose($handle);

if (empty($dataRows)) {
    echo json_encode(['error' => 'CSV has no data rows.']);
    exit;
}

// Resolve the date format. Preflight should have stashed it on the session;
// if not (e.g. user POSTed directly to import.php), sniff fresh from this file.
$dateFormat = $_SESSION['temp_csv_date_format'] ?? null;
if (!$dateFormat) {
    $samples = [];
    foreach (array_slice($dataRows, 0, 50) as $r) {
        $v = trim((string) ($r['data'][$colDate] ?? ''));
        if ($v !== '') $samples[] = $v;
    }
    $dateFormat = sniffDateFormat($samples);
}

// ── Detect granularity from date gaps ─────────────────────────────────────────
// Computed on every import for the response. Forecasting recomputes from `sales`
// when it needs this — we no longer persist it.
$rawDates    = array_column(array_column($dataRows, 'data'), $colDate);
$granularity = detectGranularity($rawDates, $dateFormat['format']);

// ── Write to DB (wrapped in transaction) ──────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/version.query.php';

try {
    $pdo->beginTransaction();

    // Fetch existing (product_id|sale_date) pairs for duplicate detection.
    // In replace mode we also need the sale IDs so we can UPDATE them.
    $existingPairsWithIds = $replace ? getExistingSalesPairsWithIds($pdo, $_SESSION['user_id']) : [];
    $existingPairs        = $replace
        ? array_fill_keys(array_keys($existingPairsWithIds), true)
        : getExistingSalesPairs($pdo, $_SESSION['user_id']);

    // Pre-pass: figure out the most recent cost and most recent selling price per
    // product based on sale_date, so the canonical price stored on `products` is
    // the latest one seen — not just whatever row happened to come first.
    // Cost and price are tracked independently in case rows have one but not the other.
    $latestByProduct = []; // name => ['cost'=>?float, 'cost_date'=>string, 'price'=>?float, 'price_date'=>string]
    foreach ($dataRows as $entry) {
        $row = $entry['data'];
        $pn = trim($row[$colProduct] ?? '');
        $dr = trim($row[$colDate]    ?? '');
        if ($pn === '' || $dr === '') continue;
        $d  = normalizeDateStrict($dr, $dateFormat['format']);
        if ($d === null) continue;

        $rc = $colCost  ? (is_numeric($row[$colCost]  ?? '') ? (float) $row[$colCost]  : null) : null;
        $rp = $colPrice ? (is_numeric($row[$colPrice] ?? '') ? (float) $row[$colPrice] : null) : null;
        if ($rc === null && $rp === null) continue;

        if (!isset($latestByProduct[$pn])) {
            $latestByProduct[$pn] = ['cost' => null, 'cost_date' => '', 'price' => null, 'price_date' => ''];
        }
        if ($rc !== null && $d > $latestByProduct[$pn]['cost_date']) {
            $latestByProduct[$pn]['cost']      = $rc;
            $latestByProduct[$pn]['cost_date'] = $d;
        }
        if ($rp !== null && $d > $latestByProduct[$pn]['price_date']) {
            $latestByProduct[$pn]['price']      = $rp;
            $latestByProduct[$pn]['price_date'] = $d;
        }
    }

    // Process rows — upsert products, aggregate quantities by (product, date)
    $productCache   = []; // name → id, avoids re-querying the same product
    $salesBatch     = []; // indexed array for insertSalesBatch (new records)
    $salesBatchIdx  = []; // pairKey → index in $salesBatch
    $updateBatch    = []; // replace mode: [{sale_id, qty, session_id}]
    $updateBatchIdx = []; // pairKey → index in $updateBatch
    $skippedDupes   = 0;
    $replacedCount  = 0;

    foreach ($dataRows as $entry) {
        $row         = $entry['data'];
        $rowNum      = $entry['rowNum'];
        $productName = trim($row[$colProduct] ?? '');
        $dateRaw     = trim($row[$colDate]    ?? '');
        $qtyRaw      = trim($row[$colQty]     ?? '');

        // Drop rows with missing required values — record the reason so the user
        // can see exactly which rows didn't make it instead of just a count.
        $dropReason = null;
        if ($productName === '')      $dropReason = 'Missing product name';
        elseif ($dateRaw === '')      $dropReason = 'Missing date';
        elseif ($qtyRaw === '')       $dropReason = 'Missing quantity';

        $date = $dropReason ? null : normalizeDateStrict($dateRaw, $dateFormat['format']);
        if (!$dropReason && $date === null) {
            $dropReason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
        }

        // Quantity must be a positive whole number — reject fractions and non-numeric.
        if (!$dropReason && (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw != (int) $qtyRaw)) {
            $dropReason = 'Quantity must be a whole number greater than 0 (got "' . mb_substr($qtyRaw, 0, 20) . '")';
        }

        if ($dropReason) {
            $dropped++;
            if (count($droppedSamples) < 10) {
                $droppedSamples[] = [
                    'row'     => $rowNum,
                    'product' => $productName ?: '(empty)',
                    'date'    => $dateRaw    ?: '(empty)',
                    'qty'     => $qtyRaw     ?: '(empty)',
                    'reason'  => $dropReason,
                ];
            }
            continue;
        }

        $qty = (int) $qtyRaw;

        $sku         = $colSku         ? (trim($row[$colSku]         ?? '') ?: null) : null;
        $category    = $colCategory    ? (trim($row[$colCategory]    ?? '') ?: null) : null;
        $subcategory = $colSubcategory ? (trim($row[$colSubcategory] ?? '') ?: null) : null;
        $cost        = $colCost        ? (is_numeric($row[$colCost]  ?? '') ? (float) $row[$colCost]  : null) : null;
        $price       = $colPrice       ? (is_numeric($row[$colPrice] ?? '') ? (float) $row[$colPrice] : null) : null;

        // Get or create product — cache by name to avoid duplicate DB lookups.
        // Cost and selling price come from the pre-pass (most recent sale_date),
        // not this individual row, so older rows can never overwrite a newer price.
        if (!isset($productCache[$productName])) {
            $latestCost  = $latestByProduct[$productName]['cost']  ?? null;
            $latestPrice = $latestByProduct[$productName]['price'] ?? null;
            $productCache[$productName] = upsertProduct(
                $pdo,
                $_SESSION['user_id'],
                $productName,
                $sku,
                $category,
                $subcategory,
                $latestCost,
                $latestPrice
            );
        }

        $pid     = $productCache[$productName];
        $pairKey = $pid . '|' . $date;

        // Handle existing (product, date) records
        if (isset($existingPairs[$pairKey])) {
            if ($replace) {
                // Aggregate into the update batch (same pair may appear multiple times in CSV)
                if (isset($updateBatchIdx[$pairKey])) {
                    $updateBatch[$updateBatchIdx[$pairKey]]['qty'] += $qty;
                } else {
                    $updateBatchIdx[$pairKey] = count($updateBatch);
                    $updateBatch[] = [
                        'sale_id' => $existingPairsWithIds[$pairKey],
                        'qty'     => $qty,
                    ];
                }
                $replacedCount++;
            } else {
                $skippedDupes++;
            }
            continue;
        }

        // Multiple rows in the CSV for the same (product, date) are aggregated —
        // quantities are summed into a single daily record rather than discarded.
        if (isset($salesBatchIdx[$pairKey])) {
            $salesBatch[$salesBatchIdx[$pairKey]]['quantity_sold'] += $qty;
        } else {
            $salesBatchIdx[$pairKey]  = count($salesBatch);
            $existingPairs[$pairKey]  = true; // prevent DB-duplicate check from matching later rows
            $salesBatch[] = [
                'product_id'    => $pid,
                'quantity_sold' => $qty,
                'sale_date'     => $date,
            ];
        }
    }

    if (empty($salesBatch) && empty($updateBatch)) {
        $pdo->rollBack();
        echo json_encode(['error' => 'No valid sales rows found in CSV.']);
        exit;
    }

    if (!empty($salesBatch)) {
        insertSalesBatch($pdo, $salesBatch);
    }

    // Apply replace-mode updates in chunked CASE-WHEN batches instead of per-row UPDATEs.
    bulkUpdateSalesByPair($pdo, $updateBatch);

    // Any product that just received new sales now has a stale accuracy cache —
    // wipe it so the next forecast modal recomputes the backtest.
    invalidateProductAccuracy($pdo, array_values($productCache));

    // Snapshot the resulting state so the user can roll back this import.
    // Label uses the source filename for traceability — counts come from the
    // pass we just did so they're consistent with what the user sees in the UI.
    $rowsAdded   = count($salesBatch);
    $rowsChanged = $replacedCount;
    $versionId   = saveDatasetVersion(
        $pdo,
        $_SESSION['user_id'],
        $filename,
        $rowsAdded,
        $rowsChanged
    );

    $pdo->commit();

    // Clean up temp file
    unlink($tempPath);
    unset($_SESSION['temp_csv'], $_SESSION['temp_csv_name'], $_SESSION['temp_csv_date_format']);

    echo json_encode([
        'success'         => true,
        'rows'            => $rowsAdded,
        'replaced'        => $replacedCount,
        'skipped'         => $skippedDupes,
        'dropped'         => $dropped,
        'dropped_samples' => $droppedSamples,
        'csv_rows'        => $csvRows,
        'products'        => count($productCache),
        'granularity'     => $granularity,
        'date_format'     => $dateFormat,
        'version_id'      => $versionId,
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    // Log the underlying SQL error server-side; show the client a generic message.
    error_log('[ProVendor import] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error during import. Please try again.']);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

// Infers granularity from the unique sorted dates in the dataset. Uses the
// sniffed format when given so DD/MM dates don't get misread as MM/DD here.
function detectGranularity(array $rawDates, ?string $format = null): string
{
    $dates = [];
    foreach ($rawDates as $d) {
        $iso = normalizeDateStrict((string) $d, $format);
        if ($iso !== null) $dates[] = strtotime($iso);
    }

    $dates = array_unique($dates);
    sort($dates);

    if (count($dates) < 2) return 'daily';

    $gaps = [];
    for ($i = 1; $i < count($dates); $i++) {
        $diffDays = ($dates[$i] - $dates[$i - 1]) / 86400;
        if ($diffDays > 0) $gaps[] = $diffDays;
    }

    if (empty($gaps)) return 'daily';

    sort($gaps);
    $median = $gaps[(int) floor(count($gaps) / 2)];

    if ($median <= 2)  return 'daily';
    if ($median <= 10) return 'weekly';
    return 'monthly';
}
