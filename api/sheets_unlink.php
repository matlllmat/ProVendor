<?php
// api/sheets_unlink.php
// Disconnects the linked Google Sheet. Sales already imported from it are kept —
// only future syncs stop — and CSV import becomes available again.
//
// Output (JSON): { success }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/sheets.query.php';

try {
    deleteSheetLink($pdo, (int) $_SESSION['user_id']);
    unset($_SESSION['pending_sheet']);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('[ProVendor sheets_unlink] ' . $e->getMessage());
    echo json_encode(['error' => 'Could not disconnect the sheet. Please try again.']);
}
