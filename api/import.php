<?php
// api/import.php
// Commits the preview rows submitted by the editor UI. The client sends a JSON
// array of already-classified rows (new / overlap / invalid / edited); we
// re-validate each one, upsert products, write the sales rows, and snapshot
// the resulting state into dataset_versions.
//
// Input  (POST):
//   rows     JSON array of row objects (see preflight.php for shape)
//   replace  '1' = apply un-edited overlap rows (overwrite existing qty)
//            '0' = skip un-edited overlap rows (keep existing qty)
//   csv_rows total raw rows in the original CSV (for the response summary)
//
// Output (JSON): { success, rows, replaced, skipped, dropped, dropped_samples,
//                  csv_rows, products, version_id }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/import_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$rows    = json_decode($_POST['rows'] ?? '[]', true);
$csvRows = (int) ($_POST['csv_rows'] ?? 0);
$replace = ($_POST['replace'] ?? '0') === '1';

if (!is_array($rows) || empty($rows)) {
    echo json_encode(['error' => 'No rows submitted.']);
    exit;
}

$filename   = $_SESSION['temp_csv_name'] ?? 'Manual entry';
$tempPath   = $_SESSION['temp_csv'] ?? null;
$dateFormat = $_SESSION['temp_csv_date_format']['format'] ?? null;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/version.query.php';

try {
    $pdo->beginTransaction();

    // ── Validate & normalize each submitted row ──────────────────────────────
    $clean          = [];
    $dropped        = 0;
    $droppedSamples = [];

    foreach ($rows as $r) {
        $rowNum      = isset($r['rowNum']) ? (int) $r['rowNum'] : 0;
        $productName = trim((string) ($r['product'] ?? ''));
        $dateRaw     = trim((string) ($r['date']    ?? ''));
        $qtyRaw      = $r['qty'] ?? '';

        $reason = null;
        if ($productName === '')               $reason = 'Missing product name';
        elseif (mb_strlen($productName) > 100) $reason = 'Product name exceeds 100 characters';
        elseif ($dateRaw === '')               $reason = 'Missing date';
        elseif ($qtyRaw === '' || $qtyRaw === null) $reason = 'Missing quantity';

        $date = $reason ? null : normalizeDateStrict($dateRaw, $dateFormat);
        if (!$reason && $date === null) {
            $reason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
        }

        if (!$reason && (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw != (int) $qtyRaw || (int) $qtyRaw > 999999)) {
            $reason = 'Quantity must be a whole number between 1 and 999,999';
        }

        if ($reason) {
            $dropped++;
            if (count($droppedSamples) < 10) {
                $droppedSamples[] = [
                    'row'     => $rowNum,
                    'product' => $productName ?: '(empty)',
                    'date'    => $dateRaw    ?: '(empty)',
                    'qty'     => (string) $qtyRaw ?: '(empty)',
                    'reason'  => $reason,
                ];
            }
            continue;
        }

        $clean[] = [
            'rowNum'      => $rowNum,
            'product'     => $productName,
            'date'        => $date,
            'qty'         => (int) $qtyRaw,
            'edited'      => !empty($r['edited']),
            'sku'         => isset($r['sku'])         && $r['sku']         !== '' ? trim((string) $r['sku'])         : null,
            'category'    => isset($r['category'])    && $r['category']    !== '' ? trim((string) $r['category'])    : null,
            'subcategory' => isset($r['subcategory']) && $r['subcategory'] !== '' ? trim((string) $r['subcategory']) : null,
            'cost'        => isset($r['cost'])  && is_numeric($r['cost'])  ? (float) $r['cost']  : null,
            'price'       => isset($r['price']) && is_numeric($r['price']) ? (float) $r['price'] : null,
        ];
    }

    if (empty($clean)) {
        $pdo->rollBack();
        echo json_encode([
            'error'           => 'No valid rows to import.',
            'dropped'         => $dropped,
            'dropped_samples' => $droppedSamples,
        ]);
        exit;
    }

    // ── Pre-pass: latest cost/price per product across all clean rows ────────
    // Cost and price track independently so a row missing one but not the other
    // still contributes to whichever value it has. "Latest" is by sale_date.
    $latestByProduct = [];
    foreach ($clean as $r) {
        if ($r['cost'] === null && $r['price'] === null) continue;
        $pn = $r['product'];
        $d  = $r['date'];
        if (!isset($latestByProduct[$pn])) {
            $latestByProduct[$pn] = ['cost' => null, 'cost_date' => '', 'price' => null, 'price_date' => ''];
        }
        if ($r['cost'] !== null && $d > $latestByProduct[$pn]['cost_date']) {
            $latestByProduct[$pn]['cost']      = $r['cost'];
            $latestByProduct[$pn]['cost_date'] = $d;
        }
        if ($r['price'] !== null && $d > $latestByProduct[$pn]['price_date']) {
            $latestByProduct[$pn]['price']      = $r['price'];
            $latestByProduct[$pn]['price_date'] = $d;
        }
    }

    // ── Lookup existing (product_id|date) pairs by name, with IDs ────────────
    $existingByName = getExistingSalesWithIdsByName($pdo, $_SESSION['user_id']);

    $productCache   = [];
    $salesBatch     = [];
    $updateBatch    = [];
    $skippedDupes   = 0;
    $replacedCount  = 0;

    foreach ($clean as $r) {
        // Upsert product (cached by name to avoid repeat lookups).
        if (!isset($productCache[$r['product']])) {
            $latestCost  = $latestByProduct[$r['product']]['cost']  ?? null;
            $latestPrice = $latestByProduct[$r['product']]['price'] ?? null;
            $productCache[$r['product']] = upsertProduct(
                $pdo,
                $_SESSION['user_id'],
                $r['product'],
                $r['sku'],
                $r['category'],
                $r['subcategory'],
                $latestCost,
                $latestPrice
            );
        }
        $pid = $productCache[$r['product']];

        // Existing-pair check uses lowercase name (consistent with preflight + DB collation).
        $existKey = mb_strtolower($r['product']) . '|' . $r['date'];
        if (isset($existingByName[$existKey])) {
            $existQty = (int) $existingByName[$existKey]['qty'];
            $saleId   = (int) $existingByName[$existKey]['id'];

            if ($existQty === $r['qty']) {
                // Exact match — silent no-op, doesn't count toward replaced.
                continue;
            }

            // Apply this row if (a) the user edited it (explicit intent) or
            // (b) the "replace" toggle was set (bulk override).
            if ($r['edited'] || $replace) {
                $updateBatch[] = ['sale_id' => $saleId, 'qty' => $r['qty']];
                $replacedCount++;
            } else {
                $skippedDupes++;
            }
            continue;
        }

        $salesBatch[] = [
            'product_id'    => $pid,
            'quantity_sold' => $r['qty'],
            'sale_date'     => $r['date'],
        ];
    }

    if (empty($salesBatch) && empty($updateBatch)) {
        $pdo->rollBack();
        echo json_encode([
            'error'           => 'No changes to apply.',
            'skipped'         => $skippedDupes,
            'dropped'         => $dropped,
            'dropped_samples' => $droppedSamples,
        ]);
        exit;
    }

    if (!empty($salesBatch))  insertSalesBatch($pdo, $salesBatch);
    if (!empty($updateBatch)) bulkUpdateSalesByPair($pdo, $updateBatch);

    // Stale accuracy caches: every product that just got new/changed sales.
    invalidateProductAccuracy($pdo, array_values($productCache));

    // Snapshot resulting state so the user can roll back this import.
    $versionId = saveDatasetVersion(
        $pdo,
        $_SESSION['user_id'],
        $filename,
        count($salesBatch),
        $replacedCount
    );

    $pdo->commit();

    // Best-effort cleanup of the temp CSV (only matters in the upload-driven flow).
    if ($tempPath && file_exists($tempPath)) @unlink($tempPath);
    unset($_SESSION['temp_csv'], $_SESSION['temp_csv_name'], $_SESSION['temp_csv_date_format']);

    echo json_encode([
        'success'         => true,
        'rows'            => count($salesBatch),
        'replaced'        => $replacedCount,
        'skipped'         => $skippedDupes,
        'dropped'         => $dropped,
        'dropped_samples' => $droppedSamples,
        'csv_rows'        => $csvRows,
        'products'        => count($productCache),
        'version_id'      => $versionId,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ProVendor import] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error during import. Please try again.']);
}
