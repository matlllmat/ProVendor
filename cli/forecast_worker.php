<?php
// cli/forecast_worker.php
// Background catalogue-forecast worker. Spawned detached by
// api/start_forecast_job.php and runs entirely outside the request that started
// it, so the owner can use the app (or close the browser) while it works.
//
//   php cli/forecast_worker.php <job_id>
//
// Per product it mirrors what the old browser-driven loop did:
//   Flask /forecast/product  → Prophet demand + confidence band (+ event coefficients)
//   Flask /optimize          → Newsvendor restock qty + est. profit
//   saveForecastRows()       → replaces that product's saved forecast
//
// One bad product never halts the run — it's counted in `failed` and the loop
// continues, matching the old best-effort behaviour.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$jobId = (int) ($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php forecast_worker.php <job_id>\n");
    exit(1);
}

// No time limit — a few hundred products can legitimately run for a long time.
set_time_limit(0);
ignore_user_abort(true);

// The worker doesn't load bootstrap.php, so it pins the same clock itself —
// otherwise it would compute window starts in PHP's ini timezone and disagree
// with the rest of the app about which day "today" is.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/events.query.php';
require_once __DIR__ . '/../queries/job.query.php';

const FLASK_BASE = 'http://localhost:5000';

// The worker is spawned with no attached console (writing to STDOUT/STDERR would
// keep the spawning pipe open and block the request that launched it), so all
// diagnostics go to this file instead.
function wlog(string $line): void
{
    @file_put_contents(
        __DIR__ . '/worker.log',
        date('Y-m-d H:i:s') . '  ' . $line . PHP_EOL,
        FILE_APPEND
    );
}

// ── Load the job ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM forecast_jobs WHERE id = ?');
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job) {
    wlog("Job $jobId not found.");
    exit(1);
}
if (!in_array($job['status'], ['queued', 'running'], true)) {
    exit(0);   // already finished / failed — nothing to do
}

$userId        = (int) $job['user_id'];
$globalHorizon = (int) $job['horizon_days'];
// 'full'   — refit Prophet for every product (import / horizon change / manual run)
// 'extend' — only forecast the days the saved window is missing, reusing the days
//            already stored. Nothing is recomputed, and a product whose window
//            already reaches far enough is skipped without touching Flask at all.
$jobMode       = ($job['mode'] ?? 'full') === 'extend' ? 'extend' : 'full';

markForecastJobRunning($pdo, $jobId);

try {
    // ── Products to forecast ─────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, name, cost_price, selling_price, forecast_horizon_days
         FROM products WHERE user_id = ? ORDER BY name'
    );
    $stmt->execute([$userId]);
    $products = $stmt->fetchAll();

    if (empty($products)) {
        finishForecastJob($pdo, $jobId);
        exit(0);
    }

    // Prophet only projects past the last actual sale, so the window start is
    // clamped to the day after it (mirrors computeDayRange in autorun_forecast.js).
    $range        = getUserSaleDateRange($pdo, $userId);
    $lastSaleDate = $range['latest'] ?? null;

    $pdo->prepare('UPDATE forecast_jobs SET total = ? WHERE id = ?')
        ->execute([count($products), $jobId]);

    $done = 0;
    $failed = 0;

    foreach ($products as $product) {
        $productId = (int) $product['id'];

        // Show which product is in flight before starting it, so a slow product
        // doesn't leave the pill looking stuck on the previous name.
        updateForecastJobProgress($pdo, $jobId, $done, $failed, $product['name']);

        try {
            $horizon = $product['forecast_horizon_days'] !== null
                ? (int) $product['forecast_horizon_days']
                : $globalHorizon;

            [$fromDate, $toDate] = computeForecastWindow($horizon, $lastSaleDate);

            if ($jobMode === 'extend') {
                $forecastRows = extendForecast($pdo, $userId, $productId, $fromDate, $toDate);
                // Already covers the window (or isn't extendable) — nothing to do.
                // Progress is written here too, otherwise skipping the last product
                // would leave the job showing one short of its total.
                if ($forecastRows === null) {
                    $done++;
                    updateForecastJobProgress($pdo, $jobId, $done, $failed, $product['name']);
                    continue;
                }
            } else {
                $forecastRows = runProphet($pdo, $userId, $productId, $fromDate, $toDate);
            }

            runNewsvendorAndSave($pdo, $userId, $productId, $product, $forecastRows);
        } catch (Throwable $e) {
            $failed++;
            wlog("job $jobId: product $productId failed: " . $e->getMessage());
        }

        $done++;
        updateForecastJobProgress($pdo, $jobId, $done, $failed, $product['name']);
    }

    finishForecastJob($pdo, $jobId);
    exit(0);

} catch (Throwable $e) {
    // Only a whole-run failure lands here (DB gone, etc.) — per-product errors
    // are swallowed above so one bad product can't kill the batch.
    failForecastJob($pdo, $jobId, $e->getMessage());
    wlog("job $jobId FAILED: " . $e->getMessage());
    exit(1);
}


// ── Helpers ──────────────────────────────────────────────────────────────────

// A rolling window of $days starting today, clamped to start after the last sale.
// Returns ['YYYY-MM-DD', 'YYYY-MM-DD'].
function computeForecastWindow(int $days, ?string $lastSaleDate): array
{
    $days = max(1, min(60, $days));

    $from = new DateTime('today');
    if ($lastSaleDate) {
        $min = new DateTime($lastSaleDate);
        $min->modify('+1 day');
        if ($from < $min) $from = $min;
    }

    $to = (clone $from)->modify('+' . ($days - 1) . ' days');
    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

// POSTs JSON to Flask and returns the decoded array, or throws.
function flaskPost(string $path, array $payload, int $timeout): array
{
    $ch = curl_init(FLASK_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw    = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException('Forecast server unreachable (' . $err . ').');
    }

    $data = json_decode((string) $raw, true);

    // Flask returns JSON {error: ...} for handled cases (e.g. no sales data), but
    // an unfittable series can raise inside Prophet and come back as a bare 500
    // with an empty body — so fall back to the status code for a usable message.
    if (!is_array($data)) {
        throw new RuntimeException(
            $status >= 400
                ? "Forecast server returned HTTP $status (the model could not fit this product)."
                : 'Malformed response from the forecast server.'
        );
    }
    if (!empty($data['error'])) throw new RuntimeException((string) $data['error']);

    return $data;
}

// Rolls one product's window forward to [$fromDate, $toDate] WITHOUT recomputing
// days it already has:
//   • keeps every saved day that still falls inside the window (elapsed days drop
//     off naturally, which is what keeps the window a constant length),
//   • asks Prophet only for the days the window is missing at the end,
//   • returns the merged series for re-optimization.
// Returns null when the saved window already covers the whole range — the caller
// then skips the product entirely, so an up-to-date catalogue costs zero Flask calls.
function extendForecast(PDO $pdo, int $userId, int $productId, string $fromDate, string $toDate): ?array
{
    $saved = getProductForecast($pdo, $userId, $productId);
    $existing = ($saved !== null && !empty($saved['forecast'])) ? $saved['forecast'] : [];

    // Days still inside the wanted window, in order.
    $keep = array_values(array_filter($existing, function ($r) use ($fromDate, $toDate) {
        return $r['date'] >= $fromDate && $r['date'] <= $toDate;
    }));

    $haveEnd = null;
    foreach ($keep as $r) {
        if ($haveEnd === null || $r['date'] > $haveEnd) $haveEnd = $r['date'];
    }

    // Nothing usable saved for this window. If the product has NO forecast at all,
    // skip it: extend runs happen daily, and retrying a product Prophet can't fit
    // (too little history) would burn a failed Flask call every day forever. A full
    // run — import, horizon change, or the manual button — is what gives a product
    // its first forecast.
    if ($haveEnd === null) {
        if (empty($existing)) return null;
        // It has a forecast, but entirely outside the wanted window (e.g. the
        // horizon moved) — rebuild the window from scratch.
        return runProphet($pdo, $userId, $productId, $fromDate, $toDate);
    }

    // Already reaches the end → nothing to do.
    if ($haveEnd >= $toDate) return null;

    $tailFrom = date('Y-m-d', strtotime($haveEnd . ' +1 day'));
    $tail     = runProphet($pdo, $userId, $productId, $tailFrom, $toDate);

    return array_merge($keep, $tail);
}

// Prophet forecast for one product; also persists its event regressor
// coefficients (the same side-effect api/run_batch_prophet.php has).
function runProphet(PDO $pdo, int $userId, int $productId, string $fromDate, string $toDate): array
{
    $data = flaskPost('/forecast/product', [
        'user_id'    => $userId,
        'product_id' => $productId,
        'from_date'  => $fromDate,
        'to_date'    => $toDate,
    ], 180);

    $forecastRows = $data['forecast'] ?? [];
    if (empty($forecastRows)) throw new RuntimeException('No forecast data returned.');

    if (!empty($data['event_coefficients'])) {
        upsertEventImpactCache($pdo, $productId, $data['event_coefficients']);
        foreach (array_unique(array_column($data['event_coefficients'], 'event_id')) as $eventId) {
            refreshEventAvgImpact($pdo, (int) $eventId);
        }
    }

    return $forecastRows;
}

// Newsvendor optimization + persistence. Unpriced products (no valid margin)
// still get their demand forecast saved, just with no restock quantity —
// matching api/run_batch_newsvendor.php.
function runNewsvendorAndSave(PDO $pdo, int $userId, int $productId, array $product, array $forecastRows): void
{
    $cost  = (float) ($product['cost_price']    ?? 0);
    $price = (float) ($product['selling_price'] ?? 0);
    $stock = 0;   // the catalogue run assumes no stock on hand; refined per product later

    if ($cost <= 0 || $price <= 0 || $price <= $cost) {
        saveForecastRows($pdo, $productId, $forecastRows, 0, $cost, $price, $stock, 0.0, 0, 0.0, null, null);
        return;
    }

    $residualRho = 0.0;
    $acc = getProductAccuracy($pdo, $userId, $productId);
    if ($acc && $acc['accuracy_residual_rho'] !== null) {
        $residualRho = (float) $acc['accuracy_residual_rho'];
    }

    $opt = flaskPost('/optimize', [
        'forecast'      => $forecastRows,
        'cost_price'    => $cost,
        'selling_price' => $price,
        'current_stock' => $stock,
        'residual_rho'  => $residualRho,
    ], 60);

    saveForecastRows(
        $pdo, $productId, $forecastRows,
        (int)   ($opt['restock_qty']   ?? 0),
        $cost, $price, $stock,
        (float) ($opt['total_std']     ?? 0.0),
        (int)   ($opt['optimal_total'] ?? 0),
        (float) ($opt['est_profit']    ?? 0.0),
        isset($opt['rho_used'])             ? (float) $opt['rho_used']             : null,
        isset($opt['std_inflation_factor']) ? (float) $opt['std_inflation_factor'] : null
    );
}
