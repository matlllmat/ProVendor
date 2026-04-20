<?php
// api/get_sales_chart.php
// AJAX: returns aggregated historical sales for the chart (no Flask needed).
// POST params: product_id (optional) OR category (optional, empty = all products).

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$userId    = (int) $_SESSION['user_id'];
$productId = (isset($_POST['product_id']) && $_POST['product_id'] !== '')
    ? (int) $_POST['product_id']
    : null;
$category  = trim($_POST['category'] ?? '');

if ($productId !== null) {
    // Single product — verify ownership, then return its daily sales.
    $stmt = $pdo->prepare(
        'SELECT s.sale_date AS date, s.quantity_sold AS actual
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE s.product_id = ? AND p.user_id = ?
         ORDER BY s.sale_date'
    );
    $stmt->execute([$productId, $userId]);

} elseif ($category !== '') {
    // Category aggregate — sum all products in this category per day.
    $stmt = $pdo->prepare(
        'SELECT s.sale_date AS date, SUM(s.quantity_sold) AS actual
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ? AND p.category = ?
         GROUP BY s.sale_date
         ORDER BY s.sale_date'
    );
    $stmt->execute([$userId, $category]);

} else {
    // All products — sum everything per day.
    $stmt = $pdo->prepare(
        'SELECT s.sale_date AS date, SUM(s.quantity_sold) AS actual
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?
         GROUP BY s.sale_date
         ORDER BY s.sale_date'
    );
    $stmt->execute([$userId]);
}

$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row['actual'] = (float) $row['actual'];
}
unset($row);

echo json_encode(['historical' => $rows]);
