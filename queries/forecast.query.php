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
                      orig_cost_price, orig_selling_price, forecast_horizon_days,
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

// Returns a product's effective forecast horizon in days: its own override if set,
// otherwise the user's global horizon. Ownership enforced via the user_id match.
function getProductHorizon(PDO $pdo, int $userId, int $productId): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(p.forecast_horizon_days, u.forecast_horizon_days) AS days
         FROM products p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = ? AND p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$productId, $userId]);
    $days = $stmt->fetchColumn();
    return $days !== false ? (int) $days : 30;
}

// Sets a product's per-product horizon override (NULL clears it → use the global
// horizon). Ownership enforced via user_id. Caller clamps to 1–60.
function setProductHorizon(PDO $pdo, int $userId, int $productId, ?int $days): void
{
    $pdo->prepare('UPDATE products SET forecast_horizon_days = ? WHERE id = ? AND user_id = ?')
        ->execute([$days, $productId, $userId]);
}

// Sets a product's EFFECTIVE cost/selling price (the values the forecast + Newsvendor
// use). Leaves orig_cost_price / orig_selling_price untouched so the imported price is
// still recoverable via "Reset to imported price". Ownership enforced via user_id.
// To reset, the caller passes the product's orig_* values back in.
function setProductPricing(PDO $pdo, int $userId, int $productId, float $cost, float $price): void
{
    $pdo->prepare('UPDATE products SET cost_price = ?, selling_price = ? WHERE id = ? AND user_id = ?')
        ->execute([$cost, $price, $productId, $userId]);
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

// Returns each product's current forecast summary, indexed by product_id.
//
// Since the app now auto-forecasts the whole catalogue and keeps only ONE
// forecast per product (saveForecastRows replaces, it no longer accumulates
// history), every product has at most one session here. We still MAX/MIN across
// its rows to collapse the per-day rows into a single summary the product cards
// can render without another query.
function getLatestForecasts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT f.product_id,
                MIN(f.forecast_date)              AS date_from,
                MAX(f.forecast_date)              AS date_to,
                ROUND(SUM(f.predicted_demand), 0) AS total_predicted,
                MAX(f.restock_qty)                AS restock_qty,
                MAX(f.current_stock)              AS current_stock,
                MAX(f.est_profit)                 AS est_profit,
                COUNT(*)                          AS day_count,
                MAX(f.generated_at)               AS generated_at
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE p.user_id = ?
         GROUP BY f.product_id'
    );
    $stmt->execute([$userId]);

    // Index by product_id so the view can look up a card's forecast in O(1).
    $byProduct = [];
    foreach ($stmt->fetchAll() as $row) {
        $byProduct[(int) $row['product_id']] = $row;
    }
    return $byProduct;
}

// Saves a product's forecast, REPLACING any forecast it already had.
// Each row: {date, predicted, lower, upper}. restock_qty is the same for all rows
// (the Newsvendor total-order recommendation for the full horizon).
//
// The old app kept every forecast run as a separate "session" (grouped by
// generated_at) for the Reports history view. That view is gone — the forecast
// page only ever shows a product's current forecast — so we delete the previous
// rows first and keep exactly one forecast per product. This keeps the table
// lean and getLatestForecasts() trivial.
function saveForecastRows(
    PDO $pdo, int $productId, array $forecastRows, int $restockQty,
    float $costPrice, float $sellingPrice, int $currentStock,
    float $totalStd, int $optimalTotal, float $estProfit,
    ?float $rhoUsed = null, ?float $stdInflationFactor = null
): void {
    // Drop the product's previous forecast before writing the new one.
    $del = $pdo->prepare('DELETE FROM forecasts WHERE product_id = ?');
    $del->execute([$productId]);

    // One fixed timestamp so all rows in this write share the same generated_at.
    $generatedAt = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO forecasts
             (product_id, forecast_date, predicted_demand, predicted_lower, predicted_upper,
              restock_qty, cost_price, selling_price, current_stock, total_std,
              optimal_total, est_profit, rho_used, std_inflation_factor,
              components, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($forecastRows as $row) {
        $components = isset($row['components']) && is_array($row['components'])
            ? json_encode($row['components'])
            : null;
        $stmt->execute([
            $productId, $row['date'], $row['predicted'],
            $row['lower'] ?? null, $row['upper'] ?? null,
            $restockQty, $costPrice, $sellingPrice, $currentStock, $totalStd,
            $optimalTotal, $estProfit, $rhoUsed, $stdInflationFactor,
            $components, $generatedAt,
        ]);
    }
}

// Returns one product's saved forecast for the inline chart on the forecast page:
// per-day rows (date, predicted, lower, upper, components) plus the single
// restock "meta" (order qty, profit, inputs, AR(1) disclosure). Null if the
// product has no saved forecast yet. Ownership is enforced via the products join.
function getProductForecast(PDO $pdo, int $userId, int $productId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT f.forecast_date AS date, f.predicted_demand AS predicted,
                f.predicted_lower AS lower, f.predicted_upper AS upper, f.components,
                f.restock_qty, f.cost_price, f.selling_price, f.current_stock,
                f.total_std, f.optimal_total, f.est_profit,
                f.rho_used, f.std_inflation_factor
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE f.product_id = ? AND p.user_id = ?
         ORDER BY f.forecast_date'
    );
    $stmt->execute([$productId, $userId]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return null;

    // Per-day series for the chart.
    $forecast = array_map(function ($r) {
        $row = [
            'date'      => $r['date'],
            'predicted' => (float) $r['predicted'],
            'lower'     => $r['lower'] !== null ? (float) $r['lower'] : (float) $r['predicted'],
            'upper'     => $r['upper'] !== null ? (float) $r['upper'] : (float) $r['predicted'],
        ];
        if (!empty($r['components'])) {
            $decoded = json_decode($r['components'], true);
            if (is_array($decoded)) $row['components'] = $decoded;
        }
        return $row;
    }, $rows);

    // Restock meta is identical on every row — read it off the first.
    $first = $rows[0];
    $meta  = null;
    if ($first['restock_qty'] !== null) {
        $totalPredicted = 0.0;
        foreach ($forecast as $fr) { $totalPredicted += $fr['predicted']; }
        $meta = [
            'total_predicted'      => (int) round($totalPredicted),
            'restock_qty'          => (int)   $first['restock_qty'],
            'current_stock'        => $first['current_stock'] !== null ? (int)   $first['current_stock'] : 0,
            'cost_price'           => $first['cost_price']    !== null ? (float) $first['cost_price']    : null,
            'selling_price'        => $first['selling_price'] !== null ? (float) $first['selling_price'] : null,
            'total_std'            => $first['total_std']     !== null ? (float) $first['total_std']     : null,
            'optimal_total'        => $first['optimal_total'] !== null ? (int)   $first['optimal_total'] : null,
            'est_profit'           => $first['est_profit']    !== null ? (float) $first['est_profit']    : null,
            'rho_used'             => $first['rho_used']             !== null ? (float) $first['rho_used']             : null,
            'std_inflation_factor' => $first['std_inflation_factor'] !== null ? (float) $first['std_inflation_factor'] : null,
        ];
    }

    return ['forecast' => $forecast, 'meta' => $meta];
}

// ── Calendar view data ───────────────────────────────────────────────────────
// Daily demand for the calendar view, merged into ONE date-keyed map so each
// calendar cell is a single O(1) lookup:
//     [ 'YYYY-MM-DD' => ['a' => actual|null, 'p' => predicted|null], ... ]
//
// Actuals come from `sales`, the forecast from `forecasts` — different tables,
// and in the aggregate scopes the forecast has to be summed across products.
// Doing that merge here (rather than reconciling two arrays in the browser)
// keeps the client simple.
//
// Scope mirrors api/get_sales_chart.php exactly: a single product, else a
// category, else every product the user owns. Ownership is enforced by the
// p.user_id filter in both queries.
function getDemandCalendarData(PDO $pdo, int $userId, ?int $productId = null, string $category = ''): array
{
    if ($productId !== null) {
        $where  = 'p.user_id = ? AND p.id = ?';
        $params = [$userId, $productId];
    } elseif ($category !== '') {
        $where  = 'p.user_id = ? AND p.category = ?';
        $params = [$userId, $category];
    } else {
        $where  = 'p.user_id = ?';
        $params = [$userId];
    }

    $days = [];

    // Actual sales per day.
    $stmt = $pdo->prepare(
        "SELECT s.sale_date AS date, SUM(s.quantity_sold) AS total
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE $where
         GROUP BY s.sale_date"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $days[$row['date']] = ['a' => (float) $row['total'], 'p' => null];
    }

    // Forecast demand per day. In the aggregate scopes this sums every product
    // that has a forecast covering the day (see forecast_products below — the
    // view captions the count so a partly-covered day isn't read as a real dip).
    $stmt = $pdo->prepare(
        "SELECT f.forecast_date AS date,
                SUM(f.predicted_demand)      AS total,
                COUNT(DISTINCT f.product_id) AS products
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE $where
         GROUP BY f.forecast_date"
    );
    $stmt->execute($params);
    $forecastProducts = 0;
    foreach ($stmt->fetchAll() as $row) {
        $d = $row['date'];
        if (!isset($days[$d])) $days[$d] = ['a' => null, 'p' => null];
        $days[$d]['p'] = (float) $row['total'];
        $forecastProducts = max($forecastProducts, (int) $row['products']);
    }

    // ── Per-day "why this number" breakdown + event contributions ────────────
    // Prophet stores each forecast day as trend + weekly + yearly + Σ events in
    // forecasts.components. Summing those across products is only valid when a
    // row's own components actually add up to its predicted value: Prophet clips
    // yhat at 0, so a degenerate product (hugely negative trend) would otherwise
    // poison the whole day's breakdown. Non-reconciling rows are excluded and
    // reported via `bt` (the total the breakdown actually accounts for), which the
    // UI compares against the day's real total before showing a decomposition.
    $stmt = $pdo->prepare(
        "SELECT f.forecast_date AS date, f.predicted_demand AS predicted, f.components
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE $where AND f.components IS NOT NULL"
    );
    $stmt->execute($params);

    $parts = [];   // date => ['t'=>,'w'=>,'y'=>,'ev'=>[name=>value],'bt'=>]
    foreach ($stmt->fetchAll() as $row) {
        $c = json_decode($row['components'], true);
        if (!is_array($c)) continue;

        $trend  = (float) ($c['trend']  ?? 0);
        $weekly = (float) ($c['weekly'] ?? 0);
        $yearly = (float) ($c['yearly'] ?? 0);
        $events = is_array($c['events'] ?? null) ? $c['events'] : [];

        $sum = $trend + $weekly + $yearly;
        foreach ($events as $e) $sum += (float) ($e['value'] ?? 0);

        $predicted = (float) $row['predicted'];
        // Skip rows whose decomposition doesn't match what was actually predicted.
        if (abs($sum - $predicted) > max(1.0, $predicted * 0.02)) continue;

        $d = $row['date'];
        if (!isset($parts[$d])) $parts[$d] = ['t' => 0.0, 'w' => 0.0, 'y' => 0.0, 'ev' => [], 'bt' => 0.0];
        $parts[$d]['t']  += $trend;
        $parts[$d]['w']  += $weekly;
        $parts[$d]['y']  += $yearly;
        $parts[$d]['bt'] += $predicted;
        foreach ($events as $e) {
            $name = (string) ($e['name'] ?? 'Event');
            $parts[$d]['ev'][$name] = ($parts[$d]['ev'][$name] ?? 0) + (float) ($e['value'] ?? 0);
        }
    }

    foreach ($parts as $d => $pt) {
        if (!isset($days[$d])) continue;
        $ev = [];
        foreach ($pt['ev'] as $name => $val) $ev[] = [$name, round($val, 2)];
        $days[$d]['c'] = [
            't'  => round($pt['t'], 2),
            'w'  => round($pt['w'], 2),
            'y'  => round($pt['y'], 2),
            'bt' => round($pt['bt'], 2),
            'ev' => $ev,
        ];
    }

    if (empty($days)) {
        return ['days' => (object) [], 'min_date' => null, 'max_date' => null, 'forecast_products' => 0];
    }

    $dates = array_keys($days);
    sort($dates);

    return [
        'days'              => $days,
        'min_date'          => $dates[0],
        'max_date'          => $dates[count($dates) - 1],
        'forecast_products' => $forecastProducts,
    ];
}

// ── Forecast coverage ────────────────────────────────────────────────────────
// How far ahead each product is actually forecast, versus how far it should be.
// Drives the "up to date" indicator and tells the extend worker which products
// have nothing to do — a product whose window already reaches the required end
// is skipped entirely, so no Prophet call is made for it.
//
// Returns:
//   required_end  — the last day the window should cover (today + horizon - 1)
//   covered       — products already reaching required_end
//   behind        — products whose window stops short (includes never-forecast)
//   never         — products with no saved forecast at all
//   products      — [product_id => ['name','last_date'|null]] for the behind ones
function getForecastCoverage(PDO $pdo, int $userId, int $horizonDays): array
{
    $requiredEnd = date('Y-m-d', strtotime('+' . max(0, $horizonDays - 1) . ' days'));

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.forecast_horizon_days, MAX(f.forecast_date) AS last_date
         FROM products p
         LEFT JOIN forecasts f ON f.product_id = p.id
         WHERE p.user_id = ?
         GROUP BY p.id, p.name, p.forecast_horizon_days
         ORDER BY p.name'
    );
    $stmt->execute([$userId]);

    $covered = 0; $behind = 0; $never = 0; $products = [];

    foreach ($stmt->fetchAll() as $row) {
        // A product with its own horizon override is measured against that.
        $end = $row['forecast_horizon_days'] !== null
            ? date('Y-m-d', strtotime('+' . max(0, (int) $row['forecast_horizon_days'] - 1) . ' days'))
            : $requiredEnd;

        $last = $row['last_date'];

        if ($last === null) {
            // Never forecast. Deliberately NOT counted as "behind": in practice
            // these are products Prophet can't fit (too little sales history), and
            // counting them would leave the catalogue permanently "not up to date"
            // AND make automatic upkeep retry them every single day forever. They
            // are reported separately so the UI can still mention them, and a full
            // run (import / manual re-forecast) is what gives them a first forecast.
            $never++;
        } elseif ($last < $end) {
            $behind++;
            $products[(int) $row['id']] = ['name' => $row['name'], 'last_date' => $last];
        } else {
            $covered++;
        }
    }

    return [
        'required_end' => $requiredEnd,
        'covered'      => $covered,
        'behind'       => $behind,      // extendable: has a forecast, window too short
        'never'        => $never,       // informational only
        'total'        => $covered + $behind,
        'products'     => $products,
        'up_to_date'   => $behind === 0,
    ];
}
