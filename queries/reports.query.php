<?php
// queries/reports.query.php
// SQL queries for the saved forecasts (Reports) page.

// Returns all saved forecast sessions for this user, newest first.
// A "session" is one save action: all rows with the same product_id + generated_at.
function getForecastSessions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
             f.product_id,
             p.name          AS product_name,
             p.category,
             p.sku,
             MIN(f.forecast_date)               AS date_from,
             MAX(f.forecast_date)               AS date_to,
             ROUND(SUM(f.predicted_demand), 0)  AS total_predicted,
             MAX(f.restock_qty)                 AS restock_qty,
             MAX(f.cost_price)                  AS cost_price,
             MAX(f.selling_price)               AS selling_price,
             MAX(f.current_stock)               AS current_stock,
             MAX(f.total_std)                   AS total_std,
             MAX(f.optimal_total)               AS optimal_total,
             MAX(f.est_profit)                  AS est_profit,
             f.generated_at,
             COUNT(f.id)                        AS day_count
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE p.user_id = ?
         GROUP BY f.product_id, f.generated_at
         ORDER BY f.generated_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Returns the saved forecast rows for one session (for chart rendering).
function getForecastSessionRows(PDO $pdo, int $productId, string $generatedAt, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT f.forecast_date AS date, f.predicted_demand AS predicted,
                f.restock_qty, f.cost_price, f.selling_price, f.current_stock,
                f.total_std, f.optimal_total, f.est_profit
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE f.product_id = ? AND f.generated_at = ? AND p.user_id = ?
         ORDER BY f.forecast_date'
    );
    $stmt->execute([$productId, $generatedAt, $userId]);
    return $stmt->fetchAll();
}

// Returns historical sales for a product, up to (and including) the given cutoff date.
function getProductSalesUpTo(PDO $pdo, int $productId, string $cutoffDate, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.sale_date AS date, s.quantity_sold AS actual
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE s.product_id = ? AND s.sale_date < ? AND p.user_id = ?
         ORDER BY s.sale_date'
    );
    $stmt->execute([$productId, $cutoffDate, $userId]);
    return $stmt->fetchAll();
}

// Deletes all rows belonging to a forecast session.
// Ownership is verified by joining products on user_id.
function deleteForecastSession(PDO $pdo, int $productId, string $generatedAt, int $userId): bool
{
    $stmt = $pdo->prepare(
        'DELETE f FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE f.product_id = ? AND f.generated_at = ? AND p.user_id = ?'
    );
    $stmt->execute([$productId, $generatedAt, $userId]);
    return $stmt->rowCount() > 0;
}
