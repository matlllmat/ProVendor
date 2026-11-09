<?php
// queries/version.query.php
// All SQL for the dataset version history — snapshotting the sales table after
// each import and restoring it on demand. One row per import = one version,
// capped at MAX_VERSIONS_PER_USER (oldest pruned first).

const MAX_VERSIONS_PER_USER = 10;

// Snapshots the user's CURRENT sales rows into sales_snapshots and inserts a
// matching dataset_versions row. Returns the new version id.
// Caller decides the label, note, and the rows_added/rows_changed metadata —
// this function just persists the snapshot.
function saveDatasetVersion(
    PDO    $pdo,
    int    $userId,
    string $label,
    int    $rowsAdded,
    int    $rowsChanged,
    bool   $isPreRestoreSnapshot = false,
    ?string $note = null
): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM sales s JOIN products p ON p.id = s.product_id WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    $totalRows = (int) $stmt->fetchColumn();

    $pdo->prepare(
        'INSERT INTO dataset_versions
            (user_id, label, note, rows_added, rows_changed, total_rows, is_pre_restore_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $label, $note, $rowsAdded, $rowsChanged, $totalRows, $isPreRestoreSnapshot ? 1 : 0]);

    $versionId = (int) $pdo->lastInsertId();

    // Copy current sales rows into the snapshot table. Batched via INSERT ... SELECT
    // so we don't ship the whole sales table through PHP.
    $pdo->prepare(
        'INSERT INTO sales_snapshots (version_id, product_id, sale_date, quantity_sold)
         SELECT ?, s.product_id, s.sale_date, s.quantity_sold
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    )->execute([$versionId, $userId]);

    // Prices live on the product, so they're copied too for a restore to bring back.
    $pdo->prepare(
        'INSERT INTO version_product_snapshots
            (version_id, product_id, cost_price, selling_price, orig_cost_price, orig_selling_price)
         SELECT ?, id, cost_price, selling_price, orig_cost_price, orig_selling_price
         FROM products WHERE user_id = ?'
    )->execute([$versionId, $userId]);

    pruneOldVersions($pdo, $userId);

    return $versionId;
}

// True when the live sales table already matches the newest version's snapshot.
//
// Every path that writes sales snapshots itself afterwards (import) or before
// (restore), so the outgoing dataset is normally already in History and
// re-snapshotting it on replace would just burn a version slot and show the
// owner two identical entries. The exception is a Google Sheet sync, which
// rewrites rows on a timer without versioning — so this is checked rather than
// assumed. Compared on (row count, total quantity), which is cheap and catches
// any realistic drift, and on prices, which the owner can edit after an import.
function datasetMatchesNewestVersion(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n, COALESCE(SUM(s.quantity_sold), 0) AS q
         FROM sales s JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    $live = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n, COALESCE(SUM(ss.quantity_sold), 0) AS q
         FROM sales_snapshots ss
         WHERE ss.version_id = (
             SELECT id FROM dataset_versions WHERE user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 1
         )'
    );
    $stmt->execute([$userId]);
    $newest = $stmt->fetch();

    if ((int) $newest['n'] === 0) return false;   // no version yet — must snapshot

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM version_product_snapshots v
         JOIN products p ON p.id = v.product_id
         WHERE v.version_id = (
             SELECT id FROM dataset_versions WHERE user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 1
         )
         AND NOT (p.cost_price <=> v.cost_price AND p.selling_price <=> v.selling_price)'
    );
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) return false;

    return (int) $live['n'] === (int) $newest['n']
        && (int) $live['q'] === (int) $newest['q'];
}

// Puts back the prices saved with a version. Returns false for older versions,
// saved before prices were kept, and leaves current prices alone in that case.
function restoreProductPrices(PDO $pdo, int $userId, int $versionId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM version_product_snapshots WHERE version_id = ?');
    $stmt->execute([$versionId]);
    if ((int) $stmt->fetchColumn() === 0) return false;

    $pdo->prepare(
        'UPDATE products p
         JOIN version_product_snapshots v ON v.product_id = p.id
         SET p.cost_price = v.cost_price, p.selling_price = v.selling_price,
             p.orig_cost_price = v.orig_cost_price, p.orig_selling_price = v.orig_selling_price
         WHERE v.version_id = ? AND p.user_id = ?'
    )->execute([$versionId, $userId]);
    return true;
}

// Marks the version the store's sales now come from.
function setCurrentVersion(PDO $pdo, int $userId, int $versionId): void
{
    $pdo->prepare('UPDATE dataset_versions SET is_current = (id = ?) WHERE user_id = ?')
        ->execute([$versionId, $userId]);
}

// After a restore, a product is active exactly when the restored data has sales for it.
function syncProductsActiveToSales(PDO $pdo, int $userId): void
{
    $pdo->prepare(
        'UPDATE products p
         LEFT JOIN (SELECT DISTINCT product_id FROM sales) s ON s.product_id = p.id
         SET p.deactivated_at = CASE
                 WHEN s.product_id IS NOT NULL THEN NULL
                 WHEN p.is_active = 1 THEN NOW()
                 ELSE p.deactivated_at
             END,
             p.is_active = (s.product_id IS NOT NULL)
         WHERE p.user_id = ?'
    )->execute([$userId]);
}

// Keeps the most recent MAX_VERSIONS_PER_USER versions and deletes older ones.
// sales_snapshots rows are removed via ON DELETE CASCADE.
function pruneOldVersions(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'SELECT id FROM dataset_versions
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 100 OFFSET ' . (int) MAX_VERSIONS_PER_USER
    );
    $stmt->execute([$userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($ids)) return;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM dataset_versions WHERE id IN ($placeholders)")->execute($ids);
}

// Returns the user's versions ordered newest-first. Used by the History UI.
function listDatasetVersions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, label, note, rows_added, rows_changed, total_rows,
                is_pre_restore_snapshot, is_current, created_at
         FROM dataset_versions
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Returns a single version row, or null if not found / not owned by this user.
function getDatasetVersion(PDO $pdo, int $versionId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, label, note, rows_added, rows_changed, total_rows,
                is_pre_restore_snapshot, created_at
         FROM dataset_versions
         WHERE id = ? AND user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$versionId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Replaces the user's CURRENT sales rows with the snapshot from $versionId.
// Caller must have already taken a pre-restore snapshot (so this action itself
// is undoable). Wrapped in the caller's transaction.
function restoreSalesFromSnapshot(PDO $pdo, int $userId, int $versionId): void
{
    // Wipe current sales for this user
    $pdo->prepare(
        'DELETE s FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    )->execute([$userId]);

    // Copy snapshot rows back. The unique (product_id, sale_date) constraint
    // guarantees the snapshot can't contain duplicates.
    $pdo->prepare(
        'INSERT INTO sales (product_id, quantity_sold, sale_date)
         SELECT ss.product_id, ss.quantity_sold, ss.sale_date
         FROM sales_snapshots ss
         JOIN dataset_versions v ON v.id = ss.version_id
         WHERE ss.version_id = ? AND v.user_id = ?'
    )->execute([$versionId, $userId]);
}

// Returns a version's snapshotted sales in the same CSV-ready shape as
// getSalesForExport(). The snapshot only stores (product_id, sale_date,
// quantity), so category and sku come from the CURRENT products table. Prices come
// from the version when it saved them. Ownership is
// enforced via the dataset_versions join. Used by api/export_version.php.
function getVersionSalesForExport(PDO $pdo, int $userId, int $versionId): array
{
    $stmt = $pdo->prepare(
        'SELECT ss.sale_date, p.name AS product_name, ss.quantity_sold,
                ' . PRODUCT_CATEGORIES_SQL . ' AS category, p.sku,
                IF(vp.version_id IS NULL, p.cost_price, vp.cost_price) AS cost_price,
                IF(vp.version_id IS NULL, p.selling_price, vp.selling_price) AS selling_price
         FROM sales_snapshots ss
         JOIN dataset_versions v ON v.id = ss.version_id
         JOIN products p ON p.id = ss.product_id
         LEFT JOIN version_product_snapshots vp ON vp.version_id = ss.version_id AND vp.product_id = ss.product_id
         WHERE ss.version_id = ? AND v.user_id = ?
         -- Same ordering as the live export (newest first) so both downloads read alike.
         ORDER BY ss.sale_date DESC, p.name'
    );
    $stmt->execute([$versionId, $userId]);
    return $stmt->fetchAll();
}

// Deletes a single version after verifying ownership. Returns true on success.
function deleteDatasetVersion(PDO $pdo, int $versionId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM dataset_versions WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$versionId, $userId]);
    if (!$stmt->fetchColumn()) return false;

    // sales_snapshots rows cascade
    $pdo->prepare('DELETE FROM dataset_versions WHERE id = ?')->execute([$versionId]);
    return true;
}
