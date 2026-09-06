<?php
// api/import.php
// Commits the preview rows submitted by the editor UI.
//
// A store holds exactly ONE dataset. This upload becomes that dataset: the
// outgoing rows are snapshotted into dataset_versions, the sales table is
// cleared, and the submitted rows are written in their place. Products absent
// from the upload are deactivated rather than deleted, so the owner's pricing
// edits, horizon overrides, shelf life and accuracy history survive — and so do
// the snapshots that earlier versions reference.
//
// Input  (POST):
//   rows     JSON array of row objects (see preflight.php for shape)
//   csv_rows total raw rows in the original CSV (for the response summary)
//
// Output (JSON): { success, rows, replaced_rows, dropped, dropped_samples,
//                  csv_rows, products, deactivated, version_id }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/import_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$rows    = json_decode($_POST['rows'] ?? '[]', true);
$csvRows = (int) ($_POST['csv_rows'] ?? 0);

if (!is_array($rows) || empty($rows)) {
    echo json_encode(['error' => 'No rows submitted.']);
    exit;
}

// Clamped to dataset_versions.label (VARCHAR(150)) — a long filename would
// otherwise throw at insert and roll back an otherwise-good import.
$filename   = mb_substr($_SESSION['temp_csv_name'] ?? 'Manual entry', 0, VERSION_LABEL_MAX);
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

    // Trim first, then treat empty as NULL. Checking `!== ''` before trimming
    // let a whitespace-only cell through as '', which is not NULL and so
    // permanently defeats upsertProduct's "only fill when currently NULL" rule.
    $optText = function ($v): ?string {
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    };

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

        // Re-apply the clamps preflight used. These rows come back from the
        // client and are editable there, so this is the last gate before MySQL:
        // an over-length category or a price past DECIMAL(10,2) would otherwise
        // throw at insert and roll back the entire import.
        [$sku]         = clampFieldLength($optText($r['sku']         ?? null), 'sku');
        [$category]    = clampFieldLength($optText($r['category']    ?? null), 'category');
        [$subcategory] = clampFieldLength($optText($r['subcategory'] ?? null), 'subcategory');

        [$costParsed]  = parseMoney($r['cost']  ?? '');
        [$priceParsed] = parseMoney($r['price'] ?? '');
        [$cost]        = clampMoney($costParsed);
        [$price]       = clampMoney($priceParsed);

        $clean[] = [
            'rowNum'      => $rowNum,
            'product'     => $productName,
            'date'        => $date,
            'qty'         => (int) $qtyRaw,
            'edited'      => !empty($r['edited']),
            'sku'         => $sku,
            'category'    => $category,
            'subcategory' => $subcategory,
            'cost'        => $cost,
            'price'       => $price,
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

    // ── Snapshot the outgoing dataset, then clear it ─────────────────────────
    // A store holds exactly one dataset: this upload replaces it rather than
    // being stitched into it. That removes the whole class of merge semantics
    // (conflict/no-op classification, the replace toggle, cross-import
    // aggregation) that made a re-upload hard to predict.
    //
    // The outgoing rows are snapshotted first so they remain browsable,
    // downloadable and restorable from History. Both statements are inside the
    // transaction already open, so a failure anywhere below leaves the previous
    // dataset exactly as it was.
    $replacedRows = 0;
    if (userHasSales($pdo, (int) $_SESSION['user_id'])) {
        // Normally the outgoing dataset is already the newest version, so
        // snapshotting again would duplicate it and waste one of the ten slots.
        // Only capture it when it has drifted (a Google Sheet sync since the
        // last import) and would otherwise be lost.
        if (!datasetMatchesNewestVersion($pdo, (int) $_SESSION['user_id'])) {
            saveDatasetVersion(
                $pdo,
                $_SESSION['user_id'],
                'Before ' . $filename,
                0,
                0,
                false,
                'Automatic snapshot of unversioned changes this upload replaced.'
            );
        }
        $replacedRows = deleteAllUserSales($pdo, (int) $_SESSION['user_id']);
    }

    $productCache   = [];
    $salesBatch     = [];

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

        // No existing-pair check: the table was just cleared, so every valid row
        // is simply inserted. preflight already collapsed duplicate
        // (product, date) pairs within the file itself, which is what the
        // sales_product_date_unique key requires.
        $salesBatch[] = [
            'product_id'    => $pid,
            'quantity_sold' => $r['qty'],
            'sale_date'     => $r['date'],
        ];
    }

    if (empty($salesBatch)) {
        // Rolling back also restores the dataset cleared above, so an upload that
        // turns out to be entirely unusable can't leave the store empty.
        $pdo->rollBack();
        echo json_encode([
            'error'           => 'None of the rows in this file could be imported, so your '
                               . 'existing data has been left untouched.',
            'dropped'         => $dropped,
            'dropped_samples' => $droppedSamples,
            'has_data'        => userHasSales($pdo, (int) $_SESSION['user_id']),
        ]);
        exit;
    }

    insertSalesBatch($pdo, $salesBatch);

    // Products that aren't in this upload are no longer sold. They're kept
    // (settings, history and snapshot references live on the row) but marked
    // inactive and dropped from forecasting.
    $deactivated = deactivateAbsentProducts($pdo, (int) $_SESSION['user_id'],
                                            array_values($productCache));

    // Stale accuracy caches: every product that just got new/changed sales.
    invalidateProductAccuracy($pdo, array_values($productCache));

    // Snapshot the incoming dataset. One version per upload, so History reads as
    // a list of the files this store has been fed.
    $versionId = saveDatasetVersion(
        $pdo,
        $_SESSION['user_id'],
        $filename,
        count($salesBatch),
        0
    );

    $pdo->commit();

    // These rows came from a Google Sheet — now that they're committed, record
    // the link so the 5-minute refresh can keep them current. Saved after the
    // commit on purpose: a link whose first import failed would auto-sync data
    // the owner never approved.
    $linkedSheet = null;
    if (!empty($_SESSION['pending_sheet'])) {
        require_once __DIR__ . '/../queries/sheets.query.php';

        saveSheetLink($pdo, (int) $_SESSION['user_id'], $_SESSION['pending_sheet'] + [
            'column_mapping' => $_SESSION['temp_csv_mapping'] ?? [],
            'date_format'    => $dateFormat,
            'added'          => count($salesBatch),
            'updated'        => 0,
        ]);

        $linkedSheet = $_SESSION['pending_sheet'];
        unset($_SESSION['pending_sheet']);
    }

    // Best-effort cleanup of the temp CSV (only matters in the upload-driven flow).
    if ($tempPath && file_exists($tempPath)) @unlink($tempPath);
    unset($_SESSION['temp_csv'], $_SESSION['temp_csv_name'],
          $_SESSION['temp_csv_date_format'], $_SESSION['temp_csv_mapping']);

    echo json_encode([
        'success'         => true,
        'linked_sheet'    => $linkedSheet,
        'rows'            => count($salesBatch),
        // Rows in the dataset this upload replaced — 0 on a first import. The
        // outgoing data isn't lost: it's the version saved just before the swap.
        'replaced_rows'   => $replacedRows,
        'dropped'         => $dropped,
        'dropped_samples' => $droppedSamples,
        'csv_rows'        => $csvRows,
        'products'        => count($productCache),
        // Products that were in the old dataset but not this one.
        'deactivated'     => $deactivated,
        'version_id'      => $versionId,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ProVendor import] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error during import. Please try again.']);
}
