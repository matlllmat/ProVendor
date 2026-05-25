<?php
// api/restore_version.php
// Restores a dataset_versions snapshot into the user's current sales table.
// Takes a safety snapshot of the current state first so the restore itself is
// undoable from the version history.
//
// Input  (POST): { version_id: int }
// Output (JSON): { success: true, pre_restore_version_id: int } or { error: ... }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$versionId = isset($_POST['version_id']) ? (int) $_POST['version_id'] : 0;
if ($versionId <= 0) {
    echo json_encode(['error' => 'Invalid version ID.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/version.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';

try {
    $version = getDatasetVersion($pdo, $versionId, $_SESSION['user_id']);
    if (!$version) {
        echo json_encode(['error' => 'Version not found or access denied.']);
        exit;
    }

    $pdo->beginTransaction();

    // Step 1: snapshot the current state so the restore is reversible.
    // The label tells the user what this snapshot is for at a glance.
    $preLabel = 'Auto-saved before restoring "' . $version['label'] . '"';
    $preRestoreId = saveDatasetVersion(
        $pdo,
        $_SESSION['user_id'],
        $preLabel,
        0,  // rows_added — N/A for a restore
        0,  // rows_changed
        true
    );

    // Step 2: wipe + repopulate sales from the target snapshot.
    restoreSalesFromSnapshot($pdo, $_SESSION['user_id'], $versionId);

    // Step 3: every product's accuracy cache is now stale relative to its
    // (different) sales history — clear it so the next forecast recomputes.
    $stmt = $pdo->prepare('SELECT id FROM products WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    invalidateProductAccuracy($pdo, $productIds);

    $pdo->commit();

    echo json_encode([
        'success'                => true,
        'pre_restore_version_id' => $preRestoreId,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ProVendor restore_version] ' . $e->getMessage());
    echo json_encode(['error' => 'Database error during restore. Please try again.']);
}
