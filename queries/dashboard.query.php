<?php
// queries/dashboard.query.php
// Data for the Dashboard page. Reuses the tested forecast queries and merges each
// product with its current forecast summary into one "restock overview" row.
// The SQL itself lives in forecast.query.php — this file only orchestrates the
// join in PHP and derives a couple of fields, so there's no duplicated query.

require_once __DIR__ . '/forecast.query.php';

// A product needs this many days of history to be forecast. Keep in step with
// MIN_FORECAST_DAYS in python/app.py.
const MIN_FORECAST_DAYS = 30;

// Days of history each product has, as [product_id => days]: the days the store
// sold anything since the product's first sale, which is what the forecast trains
// on (see add_no_sale_days in python/app.py).
function getHistoryDayCounts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT firsts.product_id, COUNT(*)
         FROM (SELECT s.product_id, MIN(s.sale_date) AS first_sale
               FROM sales s JOIN products p ON p.id = s.product_id
               WHERE p.user_id = ?
               GROUP BY s.product_id) firsts
         JOIN (SELECT DISTINCT s.sale_date
               FROM sales s JOIN products p ON p.id = s.product_id
               WHERE p.user_id = ?) days ON days.sale_date >= firsts.first_sale
         GROUP BY firsts.product_id'
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// One row per product: price info joined with its latest forecast summary
// (demand / suggested order / current stock / est. profit) plus derived fields
// (order_cost, has_price, forecasted). Sorted by suggested-order VALUE desc so the
// biggest spend surfaces first; rows without an order (unpriced / not forecast)
// sink to the bottom.
function getRestockOverview(PDO $pdo, int $userId): array
{
    $products        = getProducts($pdo, $userId, '', '');
    $latestForecasts = getLatestForecasts($pdo, $userId);
    $series          = getForecastSeries($pdo, $userId);
    $historyDays     = getHistoryDayCounts($pdo, $userId);
    $today           = date('Y-m-d');

    $rows = [];
    foreach ($products as $p) {
        $pid   = (int) $p['id'];
        $shortHistory = (int) ($historyDays[$pid] ?? 0) < MIN_FORECAST_DAYS;
        // A saved forecast from before the data shrank is no longer trustworthy.
        $fc    = $shortHistory ? null : ($latestForecasts[$pid] ?? null);

        $cost  = $p['cost_price']    !== null ? (float) $p['cost_price']    : null;
        $price = $p['selling_price'] !== null ? (float) $p['selling_price'] : null;

        $demand   = ($fc && $fc['total_predicted'] !== null) ? (int)   $fc['total_predicted'] : null;
        $stock    = ($fc && $fc['current_stock']   !== null) ? (int)   $fc['current_stock']   : null;
        $orderQty = ($fc && $fc['restock_qty']     !== null) ? (int)   $fc['restock_qty']     : null;
        $profit   = ($fc && $fc['est_profit']      !== null) ? (float) $fc['est_profit']      : null;

        // Only today onward counts. The saved order and profit cover the whole
        // forecast, so they're scaled by the share of its demand still ahead —
        // the same proration the dashboard's date-window filter uses.
        $ended = false;
        if ($fc) {
            $total = $remaining = 0.0;
            $daysLeft = 0;
            foreach ($series[$pid] ?? [] as $day) {
                $total += $day['q'];
                if ($day['d'] >= $today) {
                    $remaining += $day['q'];
                    $daysLeft++;
                }
            }
            $ended = $daysLeft === 0;
            $share = $total > 0 ? $remaining / $total : 0.0;

            $demand   = $ended ? null : (int) round($remaining);
            $orderQty = ($ended || $orderQty === null) ? null : (int) round($orderQty * $share);
            $profit   = ($ended || $profit === null)   ? null : $profit * $share;
        }

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
            'forecasted'    => $fc !== null && !$ended,
            'forecast_ended'=> $ended,
            'short_history' => $shortHistory,
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
// date, leaving out days before $fromDate. One flat query, grouped in PHP.
function getForecastSeries(PDO $pdo, int $userId, string $fromDate = '1000-01-01'): array
{
    $stmt = $pdo->prepare(
        'SELECT f.product_id, f.forecast_date, f.predicted_demand
         FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE p.user_id = ? AND f.forecast_date >= ?
         ORDER BY f.product_id, f.forecast_date'
    );
    $stmt->execute([$userId, $fromDate]);

    $byProduct = [];
    foreach ($stmt->fetchAll() as $row) {
        $byProduct[(int) $row['product_id']][] = [
            'd' => $row['forecast_date'],
            'q' => (float) $row['predicted_demand'],
        ];
    }
    return $byProduct;
}
