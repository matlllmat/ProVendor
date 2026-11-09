<?php
// pages/dashboard.logic.php
// Auth guard + data loading/aggregation for the Dashboard (post-login landing).
// Pure read view over already-computed forecasts — no Flask calls, no writes.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/dashboard.query.php';   // getRestockOverview (+ forecast.query.php)
require_once __DIR__ . '/../queries/events.query.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/job.query.php';
require_once __DIR__ . '/../includes/category_list.php';

$uid = (int) $_SESSION['user_id'];

$_dUser   = getUserById($pdo, $uid);
$userName = $_dUser ? $_dUser['name'] : 'Store Owner';

$horizon    = getForecastHorizon($pdo, $uid);
$restock    = getRestockOverview($pdo, $uid);
$series     = getForecastSeries($pdo, $uid, date('Y-m-d'));   // per-day demand from today on, for date-window filtering
$accuracy   = getCatalogueAccuracy($pdo, $uid);
$categories = getCategories($pdo, $uid);

// Overall forecast date span — seeds the custom-range date inputs.
$forecastFrom = null;
$forecastTo   = null;
foreach ($series as $days) {
    foreach ($days as $d) {
        if ($forecastFrom === null || $d['d'] < $forecastFrom) $forecastFrom = $d['d'];
        if ($forecastTo   === null || $d['d'] > $forecastTo)   $forecastTo   = $d['d'];
    }
}

// ── "Forecast window" stat: the REMAINING forecast period ─────────────────────
// Start = today (or the forecast's start if it hasn't begun yet); end = the
// forecast's fixed end. Server-rendered, so it advances on its own each day as
// elapsed days drop off — mirroring the "dim past / highlight remaining" model.
$today       = date('Y-m-d');
$windowLabel = '—';
$windowSub   = 'no forecast yet';
$daysShown   = $horizon;
$anyEnded    = (bool) array_filter($restock, fn($r) => $r['forecast_ended']);
if ($forecastFrom !== null) {
    $windowStart = max($today, $forecastFrom);
    $daysLeft    = (int) round((strtotime($forecastTo) - strtotime($windowStart)) / 86400) + 1;
    $sameMonth   = date('Y-m', strtotime($windowStart)) === date('Y-m', strtotime($forecastTo));
    $windowLabel = date('M j', strtotime($windowStart)) . ' – '
                 . ($sameMonth ? date('j', strtotime($forecastTo)) : date('M j', strtotime($forecastTo)));
    $windowSub   = $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left';
    $daysShown   = $daysLeft;
} elseif ($anyEnded) {
    $windowLabel = 'Ended';
    $windowSub   = 'forecast elapsed';
}
$windowPhrase = ($forecastFrom === null && $anyEnded)
    ? 'forecast ended'
    : 'next ' . $daysShown . ' day' . ($daysShown === 1 ? '' : 's');

// ── KPI totals + attention buckets (cheap PHP over $restock) ───────────────────
$kpi = [
    'total_demand'     => 0,
    'total_order_qty'  => 0,
    'total_order_cost' => 0.0,
    'total_profit'     => 0.0,
    'need_restock'     => 0,
    'no_price'         => 0,
    'not_forecast'     => 0,
    'product_count'    => count($restock),
];

$attention  = ['no_price' => [], 'not_forecast' => [], 'ended' => [], 'stale' => [], 'stockout' => []];
$byCategory = [];   // category => ['order_qty', 'order_cost', 'demand']
$anyMultiCategory = false;   // a product in several categories counts in each of them

// "Stale" = the forecast was generated more than one horizon window ago. Frozen
// forecasts don't auto-refresh, so this is an FYI nudge, not an error.
$staleBefore = date('Y-m-d H:i:s', strtotime("-{$horizon} days"));

foreach ($restock as $r) {
    if ($r['demand']     !== null) $kpi['total_demand'] += $r['demand'];
    if ($r['est_profit'] !== null) $kpi['total_profit'] += $r['est_profit'];

    if ($r['order_qty'] !== null && $r['order_qty'] > 0) {
        $kpi['total_order_qty'] += $r['order_qty'];
        $kpi['need_restock']    += 1;
        if ($r['order_cost'] !== null) $kpi['total_order_cost'] += $r['order_cost'];
    }

    if (!$r['has_price'])  { $kpi['no_price']++;     $attention['no_price'][]     = $r; }
    if ($r['forecast_ended']) {
        $attention['ended'][] = $r;
    } elseif (!$r['forecasted']) {
        $kpi['not_forecast']++;
        $attention['not_forecast'][] = $r;
    }
    if ($r['forecasted'] && $r['generated_at'] !== null && $r['generated_at'] < $staleBefore) {
        $attention['stale'][] = $r;
    }
    // Only meaningful once a real stock value was entered (defaults to 0).
    if ($r['stock'] !== null && $r['demand'] !== null && $r['stock'] > 0 && $r['stock'] < $r['demand']) {
        $attention['stockout'][] = $r;
    }

    $rowCategories = categoryList($r['category']) ?: ['Uncategorized'];
    if (count($rowCategories) > 1) $anyMultiCategory = true;

    foreach ($rowCategories as $cat) {
        if (!isset($byCategory[$cat])) $byCategory[$cat] = ['order_qty' => 0, 'order_cost' => 0.0, 'demand' => 0];
        if ($r['order_qty']  !== null) $byCategory[$cat]['order_qty']  += max(0, $r['order_qty']);
        if ($r['order_cost'] !== null) $byCategory[$cat]['order_cost'] += $r['order_cost'];
        if ($r['demand']     !== null) $byCategory[$cat]['demand']     += $r['demand'];
    }
}

$eventsBehind = countProductsBehindEventChange($pdo, $uid);

// ── Re-forecast prompt ────────────────────────────────────────────────────────
// Appears once the forecast is shorter than the owner's range, and becomes a firm
// reminder when the oldest forecast has run for the reminder period or any
// product's forecast has ended.
$coverage          = getForecastCoverage($pdo, $uid, $horizon);
$showReforecast    = $coverage['behind'] > 0;
$reforecastRunning = hasActiveForecastJob($pdo, $uid);
$reminderDays      = min(getForecastReminderDays($pdo, $uid), $horizon);
$oldestStart       = $coverage['oldest_start'];
$reminderDate      = $oldestStart !== null ? date('Y-m-d', strtotime("$oldestStart +$reminderDays days")) : null;
$reforecastDue     = $anyEnded || ($reminderDate !== null && $today >= $reminderDate);

// A sale dated on or after the forecast's first day is one that forecast never saw.
$lastSale   = getUserSaleDateRange($pdo, $uid)['latest'] ?? null;
$noNewSales = $lastSale !== null && $oldestStart !== null && $lastSale < $oldestStart;

if ($forecastFrom === null && $anyEnded) {
    $reforecastTitle = 'Your forecast has ended';
    $reforecastText  = 'Re-forecast to get new restock suggestions.';
} else {
    $reforecastTitle = $reforecastDue ? 'Time to re-forecast' : 'Your forecast is getting shorter';
    $reforecastText  = $daysShown < $horizon
        ? "Only $daysShown of your $horizon forecast days are left."
        : "{$coverage['behind']} of {$coverage['total']} products no longer cover your full $horizon days.";
    if ($oldestStart !== null) {
        $reforecastText .= $reforecastDue
            ? ' This forecast started on ' . date('M j', strtotime($oldestStart)) . '.'
            : " We'll remind you on " . date('M j', strtotime($reminderDate)) . '.';
    }
}
if ($noNewSales) {
    $reforecastText .= ' No new sales have been added since ' . date('M j, Y', strtotime($lastSale))
                     . ', so upload your full, updated sales file first for a better forecast.';
}

// Categories sorted by order value desc for the breakdown bars.
uasort($byCategory, fn($a, $b) => $b['order_cost'] <=> $a['order_cost']);

// ── Top movers (forecasted products only) ─────────────────────────────────────
$forecastedRows = array_values(array_filter($restock, fn($r) => $r['forecasted']));

$topByDemand = $forecastedRows;
usort($topByDemand, fn($a, $b) => ($b['demand'] ?? 0) <=> ($a['demand'] ?? 0));
$topByDemand = array_slice($topByDemand, 0, 5);

$topByProfit = $forecastedRows;
usort($topByProfit, fn($a, $b) => ($b['est_profit'] ?? 0) <=> ($a['est_profit'] ?? 0));
$topByProfit = array_slice($topByProfit, 0, 5);

$topByOrder = array_slice($restock, 0, 5);   // already sorted by order_cost desc

// ── Upcoming events (next ~30 days) + affected products ───────────────────────
$today      = date('Y-m-d');
$windowEnd  = date('Y-m-d', strtotime('+30 days'));
$rawEvents  = getRawEventsForUser($pdo, $uid);

$upcoming = [];
foreach ($rawEvents as $e) {
    $next = getNextOccurrenceDate($e, $today);
    if ($next === null || $next > $windowEnd) continue;

    $upcoming[] = [
        'name'         => $e['name'],
        'date'         => $next,
        'days_until'   => (int) floor((strtotime($next) - strtotime($today)) / 86400),
        'avg_impact'   => $e['avg_impact_pct'] !== null ? (float) $e['avg_impact_pct'] : null,
        'color'        => $e['color'] ?? '#FF5722',
        'top_products' => array_slice(getEventImpactCache($pdo, (int) $e['id'], $uid), 0, 3),
    ];
}
usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));
$upcoming = array_slice($upcoming, 0, 3);

// ── Page-state flags for the view's empty states ──────────────────────────────
$hasProducts  = $kpi['product_count'] > 0;
$hasForecasts = count($forecastedRows) > 0;

// Stock reminder shown once per login, as soon as there are suggested orders.
$showStockNotice = $hasForecasts && !empty($_SESSION['stock_notice_pending']);
if ($showStockNotice) unset($_SESSION['stock_notice_pending']);
