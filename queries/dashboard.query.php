<?php
// queries/dashboard.query.php
// Data for the Dashboard page. Reuses the tested forecast queries and merges each
// product with its current forecast summary into one "restock overview" row.
// The SQL itself lives in forecast.query.php — this file only orchestrates the
// join in PHP and derives a couple of fields, so there's no duplicated query.

require_once __DIR__ . '/forecast.query.php';

// One row per product: price info joined with its latest forecast summary
// (demand / suggested order / current stock / est. profit) plus derived fields
// (order_cost, has_price, forecasted). Sorted by suggested-order VALUE desc so the
// biggest spend surfaces first; rows without an order (unpriced / not forecast)
// sink to the bottom.
function getRestockOverview(PDO $pdo, int $userId): array
{
    $products        = getProducts($pdo, $userId, '', '');
    $latestForecasts = getLatestForecasts($pdo, $userId);

    $rows = [];
    foreach ($products as $p) {
        $pid   = (int) $p['id'];
        $fc    = $latestForecasts[$pid] ?? null;

        $cost  = $p['cost_price']    !== null ? (float) $p['cost_price']    : null;
        $price = $p['selling_price'] !== null ? (float) $p['selling_price'] : null;

        $demand   = ($fc && $fc['total_predicted'] !== null) ? (int)   $fc['total_predicted'] : null;
        $stock    = ($fc && $fc['current_stock']   !== null) ? (int)   $fc['current_stock']   : null;
        $orderQty = ($fc && $fc['restock_qty']     !== null) ? (int)   $fc['restock_qty']     : null;
        $profit   = ($fc && $fc['est_profit']      !== null) ? (float) $fc['est_profit']      : null;

        $orderCost = ($orderQty !== null && $cost !== null) ? $orderQty * $cost : null;

        $rows[] = [
            'id'            => $pid,
            'name'          => $p['name'],
            'category'      => $p['category'] ?? '',
            'cost_price'    => $cost,
            'selling_price' => $price,
            'demand'        => $demand,
            'stock'         => $stock,
            'order_qty'     => $orderQty,
            'est_profit'    => $profit,
            'order_cost'    => $orderCost,
            'has_price'     => $cost !== null && $price !== null,
            'forecasted'    => $fc !== null,
            'generated_at'  => $fc['generated_at'] ?? null,
        ];
    }

    // Default order: highest suggested-order value first; rows with no order last,
    // then alphabetical as a stable tie-break.
    usort($rows, function ($a, $b) {
        $av = $a['order_cost'] ?? -1;
        $bv = $b['order_cost'] ?? -1;
        if ($av === $bv) return strcasecmp($a['name'], $b['name']);
        return $bv <=> $av;
    });

    return $rows;
}

// Per-day forecast demand for every product, so the dashboard can scope the
// restock overview to a date window (today / next N days / a custom range).
// Returns [product_id => [ ['d' => 'YYYY-MM-DD', 'q' => float], ... ]] sorted by
// date. One flat query, grouped in PHP.
function getForecastSeries(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT f.product_id, f.forecast_date, f.predicted_demand
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE p.user_id = ?
         ORDER BY f.product_id, f.forecast_date'
    );
    $stmt->execute([$userId]);

    $byProduct = [];
    foreach ($stmt->fetchAll() as $row) {
        $byProduct[(int) $row['product_id']][] = [
            'd' => $row['forecast_date'],
            'q' => (float) $row['predicted_demand'],
        ];
    }
    return $byProduct;
}
