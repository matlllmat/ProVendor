<?php
// api/delete_import.php
// Deletes an import session and all its associated sales rows.
// Input  (POST): { session_id: int }
// Output (JSON): { success: true } or { error: "..." }

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$sessionId = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;

if ($sessionId <= 0) {
    echo json_encode(['error' => 'Invalid session ID.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';

try {
    $deleted = deleteImportSession($pdo, $sessionId, $_SESSION['user_id']);
    if ($deleted) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Import session not found or access denied.']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
