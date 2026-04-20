<?php
// api/import.php
// Receives the confirmed column mapping from the landing page Step 3.
// Reads the temp CSV, inserts products + sales into the DB, saves the import session.
// Returns JSON { success: true } or { error: "..." }.

session_start();
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
$lat      = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
$lng      = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float) $_POST['lng'] : null;

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

// Read all data rows
$dataRows = [];
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($headers)) continue;
    $dataRows[] = array_combine($headers, $row);
}
fclose($handle);

if (empty($dataRows)) {
    echo json_encode(['error' => 'CSV has no data rows.']);
    exit;
}

// ── Detect granularity from date gaps ─────────────────────────────────────────
$granularity = detectGranularity(array_column($dataRows, $colDate));

// ── Write to DB (wrapped in transaction) ──────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/../queries/user.query.php';

try {
    $pdo->beginTransaction();

    // Save store location if provided
    if ($lat !== null && $lng !== null) {
        saveUserLocation($pdo, $_SESSION['user_id'], $lat, $lng);
    }

    // Save import session first so we have its id for sales rows
    $sessionId = saveImportSession(
        $pdo,
        $_SESSION['user_id'],
        $filename,
        $mapping,
        $granularity
    );

    // Fetch existing (product_id|sale_date) pairs for duplicate detection.
    // In replace mode we also need the sale IDs so we can UPDATE them.
    $existingPairsWithIds = $replace ? getExistingSalesPairsWithIds($pdo, $_SESSION['user_id']) : [];
    $existingPairs        = $replace
        ? array_fill_keys(array_keys($existingPairsWithIds), true)
        : getExistingSalesPairs($pdo, $_SESSION['user_id']);

    // Process rows — upsert products, aggregate quantities by (product, date)
    $productCache   = []; // name → id, avoids re-querying the same product
    $salesBatch     = []; // indexed array for insertSalesBatch (new records)
    $salesBatchIdx  = []; // pairKey → index in $salesBatch
    $updateBatch    = []; // replace mode: [{sale_id, qty, session_id}]
    $updateBatchIdx = []; // pairKey → index in $updateBatch
    $skippedDupes   = 0;
    $replacedCount  = 0;

    foreach ($dataRows as $row) {
        $productName = trim($row[$colProduct] ?? '');
        $dateRaw     = trim($row[$colDate]    ?? '');
        $qtyRaw      = trim($row[$colQty]     ?? '');

        // Skip rows with missing required values
        if ($productName === '' || $dateRaw === '' || $qtyRaw === '') continue;

        $date = normalizeDate($dateRaw);
        $qty  = (int) $qtyRaw;
        if ($date === null || $qty <= 0) continue;

        $sku         = $colSku         ? trim($row[$colSku]         ?? '') ?: null : null;
        $category    = $colCategory    ? trim($row[$colCategory]    ?? '') ?: null : null;
        $subcategory = $colSubcategory ? trim($row[$colSubcategory] ?? '') ?: null : null;
        $cost        = $colCost        ? (is_numeric($row[$colCost]  ?? '') ? (float) $row[$colCost]  : null) : null;
        $price       = $colPrice       ? (is_numeric($row[$colPrice] ?? '') ? (float) $row[$colPrice] : null) : null;

        // Get or create product — cache by name to avoid duplicate DB lookups
        if (!isset($productCache[$productName])) {
            $productCache[$productName] = upsertProduct(
                $pdo,
                $_SESSION['user_id'],
                $productName,
                $sku,
                $category,
                $subcategory,
                $cost,
                $price
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
                        'sale_id'    => $existingPairsWithIds[$pairKey],
                        'qty'        => $qty,
                        'session_id' => $sessionId,
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
                'product_id'        => $pid,
                'import_session_id' => $sessionId,
                'quantity_sold'     => $qty,
                'sale_date'         => $date,
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

    // Apply replace-mode updates
    foreach ($updateBatch as $upd) {
        $pdo->prepare('UPDATE sales SET quantity_sold = ?, import_session_id = ? WHERE id = ?')
            ->execute([$upd['qty'], $upd['session_id'], $upd['sale_id']]);
    }

    $pdo->commit();

    // Clean up temp file
    unlink($tempPath);
    unset($_SESSION['temp_csv'], $_SESSION['temp_csv_name']);

    echo json_encode([
        'success'      => true,
        'rows'         => count($salesBatch),
        'replaced'     => $replacedCount,
        'skipped'      => $skippedDupes,
        'csv_rows'     => $csvRows,
        'products'     => count($productCache),
        'granularity'  => $granularity,
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

// Normalizes various date formats to Y-m-d for MySQL.
function normalizeDate(string $raw): ?string
{
    $ts = strtotime($raw);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

// Infers granularity from the unique sorted dates in the dataset.
function detectGranularity(array $rawDates): string
{
    $dates = [];
    foreach ($rawDates as $d) {
        $ts = strtotime(trim($d));
        if ($ts !== false) $dates[] = $ts;
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
