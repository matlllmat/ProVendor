<?php
// api/delete_forecast.php
// AJAX: deletes all rows of a saved forecast session.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/reports.query.php';

$productId   = isset($_POST['product_id'])   ? (int)  $_POST['product_id']   : null;
$generatedAt = isset($_POST['generated_at']) ? trim($_POST['generated_at'])  : null;

if (!$productId || !$generatedAt) {
    echo json_encode(['error' => 'Missing parameters.']);
    exit;
}

$deleted = deleteForecastSession($pdo, $productId, $generatedAt, (int) $_SESSION['user_id']);

if (!$deleted) {
    echo json_encode(['error' => 'Forecast not found or already deleted.']);
    exit;
}

echo json_encode(['success' => true]);
