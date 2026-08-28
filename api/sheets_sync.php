<?php
// api/sheets_sync.php
// Refreshes the owner's sales data from their linked Google Sheet.
//
// Called two ways:
//   - the browser heartbeat (includes/sheets_autosync.php) every 5 minutes,
//     while auto-sync is on;
//   - the "Update Data" button, which passes force=1 and works even when
//     auto-sync is off.
//
// The sheet is the source of truth: rows it holds are inserted, and days whose
// quantity changed in the sheet are overwritten. Rows DELETED from the sheet are
// deliberately left alone — a mis-click in a spreadsheet must never silently
// erase months of a store's sales history.
//
// No dataset version is snapshotted per sync: at one snapshot every 5 minutes
// the 10-version history would be scrolled away within the hour, destroying the
// import snapshots it exists to protect. Version history stays a record of
// deliberate imports and restores.
//
// Input  (POST): force ('1' skips the 5-minute throttle)
// Output (JSON): { success, added, updated, dropped, skipped_recent?,
//                  last_synced_at, next_eligible_in }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/sheets_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.', 'code' => 'auth']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/sheets.query.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$userId = (int) $_SESSION['user_id'];
$force  = ($_POST['force'] ?? '0') === '1';

// Everything this endpoint needs from the session has now been read, so release
// the session lock before the slow part.
//
// PHP holds an EXCLUSIVE lock on the session file for the whole request, and a
// sync spends most of its life waiting on Google — seconds, on a sheet with
// thousands of rows. Holding the lock through that would serialise every other
// request from the same browser behind the background refresh: the owner's
// clicks, page loads and, worst of all, their own import POST would all sit and
// wait on a job they never asked for and cannot see.
session_write_close();

$link = getSheetLink($pdo, $userId);
if (!$link) {
    echo json_encode(['error' => 'No Google Sheet is linked to this account.', 'code' => 'not_linked']);
    exit;
}

// ── Throttle ─────────────────────────────────────────────────────────────────
// The heartbeat fires per open tab, so without this an owner with four tabs
// would hit Google four times every five minutes.
const SHEET_SYNC_INTERVAL = 300; // seconds

if (!$force && $link['last_synced_at']) {
    $elapsed = time() - strtotime($link['last_synced_at']);
    if ($elapsed < SHEET_SYNC_INTERVAL) {
        echo json_encode([
            'success'          => true,
            'skipped_recent'   => true,
            'added'            => 0,
            'updated'          => 0,
            'last_synced_at'   => $link['last_synced_at'],
            'next_eligible_in' => SHEET_SYNC_INTERVAL - $elapsed,
        ]);
        exit;
    }
}

// ── Pull the sheet ───────────────────────────────────────────────────────────
$sheet = fetchSheet($link['sheet_url']);

if (empty($sheet['ok'])) {
    // Keep the link and its mapping — the owner needs to see WHY the refresh
    // stopped (usually: sharing was revoked) rather than find it silently gone.
    recordSyncResult($pdo, $userId, 'error', $sheet['error'] ?? 'Unknown error');
    echo json_encode([
        'error' => $sheet['error'] ?? 'The sheet could not be read.',
        'code'  => $sheet['code']  ?? 'api_error',
    ]);
    exit;
}

// A mapped column vanishing from the sheet (renamed or deleted) would invalidate
// every row, so check before parsing anything — otherwise the owner is told
// "0 rows synced" when the real answer is "you renamed a column".
foreach (['date', 'product', 'quantity'] as $field) {
    $name = $link['column_mapping'][$field] ?? null;
    if ($name === null || !in_array($name, $sheet['headers'], true)) {
        $msg = 'The "' . ($name ?? $field) . '" column is no longer in your sheet. '
             . 'Rename it back, or disconnect and re-link the sheet to remap the columns.';
        recordSyncResult($pdo, $userId, 'error', $msg);
        echo json_encode(['error' => $msg, 'code' => 'column_missing']);
        exit;
    }
}

$agg = aggregateSheetRows(
    $sheet['headers'],
    $sheet['rows'],
    $link['column_mapping'],
    $link['date_format'] ?: null
);

// ── Apply ────────────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Latest cost/price per product across the sheet, by sale date — same
    // last-write-wins rule the CSV importer uses.
    $latestByProduct = [];
    foreach ($agg['pairs'] as $r) {
        if ($r['cost'] === null && $r['price'] === null) continue;
        $pn = $r['product'];
        if (!isset($latestByProduct[$pn])) {
            $latestByProduct[$pn] = ['cost' => null, 'cost_date' => '', 'price' => null, 'price_date' => ''];
        }
        if ($r['cost'] !== null && $r['date'] > $latestByProduct[$pn]['cost_date']) {
            $latestByProduct[$pn]['cost']      = $r['cost'];
            $latestByProduct[$pn]['cost_date'] = $r['date'];
        }
        if ($r['price'] !== null && $r['date'] > $latestByProduct[$pn]['price_date']) {
            $latestByProduct[$pn]['price']      = $r['price'];
            $latestByProduct[$pn]['price_date'] = $r['date'];
        }
    }

    $existing     = getExistingSalesWithIdsByName($pdo, $userId);
    $productCache = [];
    $insertBatch  = [];
    $updateBatch  = [];

    foreach ($agg['pairs'] as $key => $r) {
        // Days already stored with the same quantity are skipped before any
        // product lookup — on a steady sheet that's almost every row.
        if (isset($existing[$key]) && (int) $existing[$key]['qty'] === $r['qty']) continue;

        // Resolve the product for every row we're about to write, changed rows
        // included, so productCache ends up holding exactly the products whose
        // history moved — which is what the accuracy invalidation below needs.
        if (!isset($productCache[$r['product']])) {
            $productCache[$r['product']] = upsertProduct(
                $pdo, $userId, $r['product'], $r['sku'], $r['category'], $r['subcategory'],
                $latestByProduct[$r['product']]['cost']  ?? null,
                $latestByProduct[$r['product']]['price'] ?? null
            );
        }

        if (isset($existing[$key])) {
            $updateBatch[] = ['sale_id' => (int) $existing[$key]['id'], 'qty' => $r['qty']];
            continue;
        }

        $insertBatch[] = [
            'product_id'    => $productCache[$r['product']],
            'quantity_sold' => $r['qty'],
            'sale_date'     => $r['date'],
        ];
    }

    if ($insertBatch) insertSalesBatch($pdo, $insertBatch);
    if ($updateBatch) bulkUpdateSalesByPair($pdo, $updateBatch);

    if ($insertBatch || $updateBatch) {
        // Cached accuracy figures are computed from the sales history we just moved.
        invalidateProductAccuracy($pdo, array_values($productCache));
    }

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ProVendor sheets_sync] ' . $e->getMessage());
    recordSyncResult($pdo, $userId, 'error', 'Database error while saving the synced rows.');
    echo json_encode([
        'error' => 'Database error while saving the synced rows. Please try again.',
        'code'  => 'db_error',
    ]);
    exit;
}

recordSyncResult($pdo, $userId, 'ok', null, count($insertBatch), count($updateBatch));

echo json_encode([
    'success'         => true,
    'added'           => count($insertBatch),
    'updated'         => count($updateBatch),
    'dropped'         => $agg['dropped'],
    'dropped_samples' => $agg['dropped_samples'],
    'sheet_rows'      => $sheet['row_count'],
    'last_synced_at'  => date('Y-m-d H:i:s'),
]);
