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
    $sql    = 'SELECT id, name, sku, category, subcategory, cost_price, selling_price,
                      accuracy_pct, accuracy_mape, accuracy_mae, accuracy_rmse,
                      accuracy_horizon_days, accuracy_residual_rho, accuracy_computed_at
               FROM products WHERE user_id = ?';
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

// Returns the cached forecast-accuracy stats for a single product (or null fields
// if the backtest has never been run / was invalidated by a new import).
function getProductAccuracy(PDO $pdo, int $userId, int $productId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT accuracy_pct, accuracy_mape, accuracy_mae, accuracy_rmse,
                accuracy_horizon_days, accuracy_residual_rho, accuracy_computed_at
         FROM products
         WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$productId, $userId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

// Persists the result of a backtest. Called by api/run_product_accuracy.php
// after Flask returns. Stamps accuracy_computed_at = NOW().
function saveProductAccuracy(
    PDO $pdo, int $userId, int $productId,
    float $accuracyPct, float $mape, float $mae, float $rmse,
    int $horizonDays, float $residualRho
): void {
    $stmt = $pdo->prepare(
        'UPDATE products
         SET accuracy_pct          = ?,
             accuracy_mape         = ?,
             accuracy_mae          = ?,
             accuracy_rmse         = ?,
             accuracy_horizon_days = ?,
             accuracy_residual_rho = ?,
             accuracy_computed_at  = CURRENT_TIMESTAMP
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$accuracyPct, $mape, $mae, $rmse, $horizonDays, $residualRho, $productId, $userId]);
}

// Aggregates per-product accuracy into a single catalogue-level snapshot.
// All three error metrics (MAPE, MAE, RMSE) are weighted by each product's
// total historical sales volume — high-volume products dominate the headline
// because they're what the business actually depends on.
//
// Returns:
//   evaluated_count  — products with cached accuracy
//   total_count      — total products owned by this user
//   weighted_mape    — Σ(mape × units) / Σ(units) over evaluated products
//   weighted_mae     — same weighting
//   weighted_rmse    — same weighting
//   weighted_accuracy_pct — 100 − weighted_mape, floored at 0 (panel headline)
//   oldest_computed_at — staleness indicator
function getCatalogueAccuracy(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
             p.id,
             p.accuracy_mape, p.accuracy_mae, p.accuracy_rmse,
             p.accuracy_computed_at,
             COALESCE((SELECT SUM(s.quantity_sold)
                       FROM sales s
                       WHERE s.product_id = p.id), 0) AS total_units
         FROM products p
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $totalCount     = count($rows);
    $evaluated      = array_values(array_filter($rows, fn($r) => $r['accuracy_mape'] !== null));
    $evaluatedCount = count($evaluated);

    // Skip products with zero historical sales — they have no weight and would
    // also (typically) have no meaningful accuracy. Sum is over the rest.
    $weightSum = 0.0;
    $mapeNum   = 0.0;
    $maeNum    = 0.0;
    $rmseNum   = 0.0;
    $oldest    = null;

    foreach ($evaluated as $row) {
        $w = (float) $row['total_units'];
        if ($w <= 0) continue;
        $weightSum += $w;
        $mapeNum   += $w * (float) $row['accuracy_mape'];
        $maeNum    += $w * (float) $row['accuracy_mae'];
        $rmseNum   += $w * (float) $row['accuracy_rmse'];

        $ts = $row['accuracy_computed_at'];
        if ($ts && ($oldest === null || $ts < $oldest)) $oldest = $ts;
    }

    if ($weightSum > 0) {
        $wMape = $mapeNum / $weightSum;
        $wMae  = $maeNum  / $weightSum;
        $wRmse = $rmseNum / $weightSum;
        $wAcc  = max(0.0, 100.0 - $wMape);
    } else {
        $wMape = $wMae = $wRmse = $wAcc = null;
    }

    return [
        'evaluated_count'       => $evaluatedCount,
        'total_count'           => $totalCount,
        'total_units_weighted'  => (int) $weightSum,
        'weighted_mape'         => $wMape !== null ? round($wMape, 2) : null,
        'weighted_mae'          => $wMae  !== null ? round($wMae,  2) : null,
        'weighted_rmse'         => $wRmse !== null ? round($wRmse, 2) : null,
        'weighted_accuracy_pct' => $wAcc  !== null ? round($wAcc,  2) : null,
        'oldest_computed_at'    => $oldest,
    ];
}

// Per-product accuracy breakdown for the Reports page. Joins each product
// with its lifetime sales volume so the table can show context (a 100-MAPE
// product matters very differently if it sells 5 units/day vs 500).
//
// Sort order is deliberate:
//   1. Evaluated products first (NULL accuracy sinks to the bottom).
//   2. Highest "impact" first — MAE × total_units, i.e. how much each product
//      contributes to the catalogue-weighted MAE. The worst offenders surface
//      at the top so the panelist can drill in immediately.
//   3. Tie-break by raw sales volume so big products still float up.
function getProductAccuracyBreakdown(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
             p.id, p.name, p.category,
             p.accuracy_pct, p.accuracy_mape, p.accuracy_mae, p.accuracy_rmse,
             p.accuracy_horizon_days, p.accuracy_residual_rho, p.accuracy_computed_at,
             COALESCE(stats.total_units, 0) AS total_units,
             COALESCE(stats.sale_days,   0) AS sale_days
         FROM products p
         LEFT JOIN (
             SELECT product_id,
                    SUM(quantity_sold)        AS total_units,
                    COUNT(DISTINCT sale_date) AS sale_days
             FROM sales
             GROUP BY product_id
         ) stats ON stats.product_id = p.id
         WHERE p.user_id = ?
         ORDER BY
             CASE WHEN p.accuracy_mae IS NULL THEN 1 ELSE 0 END,
             (p.accuracy_mae * COALESCE(stats.total_units, 0)) DESC,
             COALESCE(stats.total_units, 0) DESC,
             p.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Wipes the cached accuracy for any products that received new sales during
// an import — the cached number is stale once the underlying data changes.
// `$productIds` is the list of product IDs touched by the current import.
function invalidateProductAccuracy(PDO $pdo, array $productIds): void
{
    if (empty($productIds)) return;
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $pdo->prepare(
        "UPDATE products
         SET accuracy_pct          = NULL,
             accuracy_mape         = NULL,
             accuracy_mae          = NULL,
             accuracy_rmse         = NULL,
             accuracy_horizon_days = NULL,
             accuracy_residual_rho = NULL,
             accuracy_computed_at  = NULL
         WHERE id IN ($placeholders)"
    )->execute(array_values($productIds));
}

// Saves a set of forecast rows to the forecasts table.
// Each row: {date, predicted, lower, upper}. restock_qty is the same for all rows
// (the Newsvendor total-order recommendation for the full horizon).
// Each save creates a new snapshot — use generated_at to identify sessions.
function saveForecastRows(
    PDO $pdo, int $productId, array $forecastRows, int $restockQty,
    float $costPrice, float $sellingPrice, int $currentStock,
    float $totalStd, int $optimalTotal, float $estProfit,
    ?float $rhoUsed = null, ?float $stdInflationFactor = null
): void {
    // One fixed timestamp so all rows in the batch share the same generated_at.
    // Without this, per-row DEFAULT CURRENT_TIMESTAMP can split a batch across
    // two seconds, breaking GROUP BY product_id, generated_at session grouping.
    $generatedAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO forecasts
             (product_id, forecast_date, predicted_demand, restock_qty,
              cost_price, selling_price, current_stock, total_std,
              optimal_total, est_profit, rho_used, std_inflation_factor,
              components, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($forecastRows as $row) {
        $components = isset($row['components']) && is_array($row['components'])
            ? json_encode($row['components'])
            : null;
        $stmt->execute([
            $productId, $row['date'], $row['predicted'], $restockQty,
            $costPrice, $sellingPrice, $currentStock, $totalStd,
            $optimalTotal, $estProfit, $rhoUsed, $stdInflationFactor,
            $components, $generatedAt,
        ]);
    }
}
