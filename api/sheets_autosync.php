<?php
// api/sheets_autosync.php
// Turns the 5-minute automatic refresh on or off for the linked sheet. With it
// off, data only moves when the owner presses "Update Data".
//
// Input  (POST): enabled ('1' | '0')
// Output (JSON): { success, auto_sync }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/sheets.query.php';

$userId  = (int) $_SESSION['user_id'];
$enabled = ($_POST['enabled'] ?? '') === '1';

if (!getSheetLink($pdo, $userId)) {
    echo json_encode(['error' => 'No Google Sheet is linked to this account.']);
    exit;
}

try {
    setSheetAutoSync($pdo, $userId, $enabled);
    echo json_encode(['success' => true, 'auto_sync' => $enabled]);
} catch (PDOException $e) {
    error_log('[ProVendor sheets_autosync] ' . $e->getMessage());
    echo json_encode(['error' => 'Could not change the refresh setting. Please try again.']);
}
