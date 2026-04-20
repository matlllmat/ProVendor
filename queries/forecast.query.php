<?php
// queries/forecast.query.php
// DB queries for the forecast page.

// Returns a list of distinct category names for this user.
function getCategories(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT category
         FROM products
         WHERE user_id = ? AND category IS NOT NULL AND category != ""
         ORDER BY category'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Returns all products for this user, with optional name search and category filter.
function getProducts(PDO $pdo, int $userId, string $search = '', string $category = ''): array
{
    $sql    = 'SELECT id, name, sku, category, subcategory FROM products WHERE user_id = ?';
    $params = [$userId];

    if ($search !== '') {
        $sql     .= ' AND (name LIKE ? OR sku LIKE ? OR id = ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = is_numeric($search) ? (int) $search : 0;
    }

    if ($category !== '') {
        $sql     .= ' AND category = ?';
        $params[] = $category;
    }

    $sql .= ' ORDER BY name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Saves a set of forecast rows to the forecasts table.
// Each row: {date, predicted, lower, upper}. restock_qty is the same for all rows
// (the Newsvendor total-order recommendation for the full horizon).
// Each save creates a new snapshot — use generated_at to identify sessions.
function saveForecastRows(
    PDO $pdo, int $productId, array $forecastRows, int $restockQty,
    float $costPrice, float $sellingPrice, int $currentStock,
    float $totalStd, int $optimalTotal, float $estProfit
): void {
    // One fixed timestamp so all rows in the batch share the same generated_at.
    // Without this, per-row DEFAULT CURRENT_TIMESTAMP can split a batch across
    // two seconds, breaking GROUP BY product_id, generated_at session grouping.
    $generatedAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO forecasts
             (product_id, forecast_date, predicted_demand, restock_qty,
              cost_price, selling_price, current_stock, total_std,
              optimal_total, est_profit, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($forecastRows as $row) {
        $stmt->execute([
            $productId, $row['date'], $row['predicted'], $restockQty,
            $costPrice, $sellingPrice, $currentStock, $totalStd,
            $optimalTotal, $estProfit, $generatedAt,
        ]);
    }
}
