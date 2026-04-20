<?php
// api/update_sale.php
// Updates the quantity_sold of a single sale record (inline edit from Import History).
// Only the owning user can edit their own sales records.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';

$saleId = (int) ($_POST['sale_id']  ?? 0);
$qty    = (int) ($_POST['quantity'] ?? 0);

if ($saleId <= 0) {
    echo json_encode(['error' => 'Invalid record ID.']);
    exit;
}

if ($qty <= 0) {
    echo json_encode(['error' => 'Quantity must be greater than 0.']);
    exit;
}

if (!updateSaleQty($pdo, $saleId, $_SESSION['user_id'], $qty)) {
    echo json_encode(['error' => 'Record not found or access denied.']);
    exit;
}

echo json_encode(['success' => true]);
