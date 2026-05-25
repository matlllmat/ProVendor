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

    pruneOldVersions($pdo, $userId);

    return $versionId;
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
                is_pre_restore_snapshot, created_at
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
