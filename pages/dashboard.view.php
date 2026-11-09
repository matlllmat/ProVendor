<?php
// pages/dashboard.view.php
// Post-login landing: restock command center + store overview.
// Read-only view over already-computed forecasts (see dashboard.logic.php).

require_once __DIR__ . '/dashboard.logic.php';

$pageTitle = 'ProVendor — Dashboard';
$pageCss   = 'dashboard.css';
require_once __DIR__ . '/../includes/header.php';

// Small inline formatters (view-local).
$peso  = fn($v, $dec = 2) => '₱' . number_format((float) $v, $dec);
$units = fn($v) => number_format((int) $v);
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <div class="db-head mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Dashboard</h1>
            <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
                What to restock and how your store is tracking — at a glance.
            </p>
        </div>
        <span class="db-horizon-chip"><?php echo ucfirst($windowPhrase); ?></span>
    </div>

    <?php if (!$hasProducts): ?>
    <!-- No products at all -->
    <div class="db-empty">
        <p class="db-empty-title">No products yet</p>
        <p class="db-empty-sub">Import your sales data to start forecasting demand and restocks.</p>
        <a class="db-empty-cta" href="<?php echo BASE_URL; ?>/pages/import.view.php#import">Import sales data</a>
    </div>
    <?php else: ?>

    <?php if ($showReforecast): ?>
    <!-- Above the summary because every figure below depends on this forecast. -->
    <section class="db-reforecast<?php echo $reforecastDue ? ' is-due' : ''; ?>" role="status">
        <div class="db-reforecast-text">
            <p class="db-reforecast-title"><?php echo htmlspecialchars($reforecastTitle); ?></p>
            <p class="db-reforecast-body" id="db-reforecast-body"><?php echo htmlspecialchars($reforecastText); ?></p>
        </div>
        <div class="db-reforecast-actions">
            <?php if ($noNewSales): ?>
            <a class="db-reforecast-link" href="<?php echo BASE_URL; ?>/pages/import.view.php#import">Update dataset</a>
            <?php endif; ?>
            <button type="button" class="db-reforecast-btn" id="db-reforecast-btn"<?php echo $reforecastRunning ? ' disabled' : ''; ?>>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/>
                </svg>
                <span id="db-reforecast-label"><?php echo $reforecastRunning ? 'Re-forecasting…' : 'Re-forecast now'; ?></span>
            </button>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── 1. Store summary ─────────────────────────────────────────────────
         One card rather than six tiles. The six figures were never peers: the
         forecast window is context for the others, and the rest form a sentence —
         this much demand is coming, so order this much, at this cost, for this
         profit. Six identically-styled boxes flattened all of that into a wall of
         equal-weight numbers where nothing led.
    -->
    <section class="db-summary">

        <!-- Context strip: what window these figures describe, and how much of the
             catalogue they touch. Framing, not metrics, so it is sized as prose. -->
        <div class="db-sum-context">
            <span class="db-ctx-item">
                <span class="db-ctx-label">Forecast window</span>
                <span class="db-ctx-value"><?php echo htmlspecialchars($windowLabel); ?></span>
            </span>
            <span class="db-ctx-dot">·</span>
            <span class="db-ctx-note"><?php echo htmlspecialchars($windowSub); ?></span>

            <span class="db-ctx-fill"></span>

            <span class="db-ctx-note db-ctx-need">
                <strong id="kpi-need"><?php echo (int) $kpi['need_restock']; ?></strong>
                of <?php echo (int) $kpi['product_count']; ?> products need restocking
            </span>
            <?php if (SHOW_ACCURACY_FEATURES): ?>
            <span class="db-ctx-dot">·</span>
            <span class="db-ctx-note">
                <?php echo $accuracy['weighted_accuracy_pct'] !== null ? number_format($accuracy['weighted_accuracy_pct'], 1) . '%' : '—'; ?>
                accurate (<?php echo (int) $accuracy['evaluated_count']; ?>/<?php echo (int) $accuracy['total_count']; ?> evaluated)
            </span>
            <?php endif; ?>
        </div>

        <div class="db-sum-body">

            <!-- What is coming -->
            <div class="db-sum-group">
                <p class="db-sum-label">Expected demand</p>
                <div class="db-sum-figs">
                    <div class="db-fig">
                        <span class="db-fig-value" id="kpi-demand"><?php echo $units($kpi['total_demand']); ?></span>
                        <span class="db-fig-sub" id="kpi-demand-sub">units · <?php echo $windowPhrase; ?></span>
                    </div>
                </div>
            </div>

            <!-- What to do about it. Cost and profit are shown as a pair because that
                 is the decision: spend this, get back that. -->
            <div class="db-sum-group db-sum-plan">
                <p class="db-sum-label">Suggested restock plan</p>
                <div class="db-sum-figs">
                    <div class="db-fig">
                        <span class="db-fig-value" id="kpi-restock"><?php echo $units($kpi['total_order_qty']); ?></span>
                        <span class="db-fig-sub" id="kpi-restock-sub">across <?php echo (int) $kpi['need_restock']; ?> products</span>
                    </div>
                    <div class="db-money">
                        <div class="db-fig">
                            <span class="db-fig-value db-accent" id="kpi-spend"><?php echo $peso($kpi['total_order_cost'], 0); ?></span>
                            <span class="db-fig-sub">to spend</span>
                        </div>
                        <span class="db-fig-arrow" aria-hidden="true">&rarr;</span>
                        <div class="db-fig">
                            <span class="db-fig-value db-green" id="kpi-profit"><?php echo $peso($kpi['total_profit'], 0); ?></span>
                            <span class="db-fig-sub">est. profit</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <?php if (!$hasForecasts && !$showReforecast): ?>
    <div class="db-banner">
        <span>Forecasts haven't been generated yet.</span>
        <a href="<?php echo BASE_URL; ?>/pages/forecast.view.php">Generate on the Forecast page →</a>
    </div>
    <?php endif; ?>

    <!-- ── 2. Restock overview (centerpiece) ────────────────────────────────── -->
    <section class="db-card db-restock is-simple">
        <div class="db-card-head">
            <div>
                <h2 class="db-card-title">Suggested Restock</h2>
                <p class="db-card-sub"><?php echo (int) $kpi['need_restock']; ?> of <?php echo (int) $kpi['product_count']; ?> products have a suggested order<span id="db-window-note"></span></p>
            </div>
            <!-- Section-level actions live next to the title — the toolbar below is filtering only. -->
            <div class="db-card-actions">
                <div class="db-movers-toggle" role="group" aria-label="Table view">
                    <button type="button" class="db-view-btn active" data-view="simple">Simple</button>
                    <button type="button" class="db-view-btn" data-view="detailed">Detailed</button>
                </div>
                <div class="db-export" id="db-export">
                    <button type="button" class="db-btn" id="db-export-toggle" aria-haspopup="true" aria-expanded="false">Export ▾</button>
                    <div class="db-export-menu" id="db-export-menu" style="display:none">
                        <div class="db-export-group-label">All products</div>
                        <button type="button" class="db-export-item" id="db-export-all-csv">Export as CSV</button>
                        <button type="button" class="db-export-item" id="db-export-all-pdf">Export as PDF</button>
                        <div class="db-export-divider"></div>
                        <button type="button" class="db-export-item" id="db-export-pick">Export specific products…</button>
                    </div>
                </div>
                <!-- Only shown once "Export specific products…" is chosen — lets the owner
                     tick products (across any filter/page) then export just those. -->
                <div class="db-select-bar" id="db-select-bar" style="display:none">
                    <span class="db-select-count" id="db-select-count">0 selected</span>
                    <button type="button" class="db-btn" id="db-select-export-csv">Export as CSV</button>
                    <button type="button" class="db-btn" id="db-select-export-pdf">Export as PDF</button>
                    <button type="button" class="db-btn" id="db-select-done">Done</button>
                </div>
                <!-- Fill in cost / price / stock for every product without leaving the dashboard. -->
                <button type="button" class="db-btn db-btn-primary" onclick="bpOpen()"
                        title="Set cost price, selling price and stock for all products">Batch edit</button>
            </div>
        </div>

        <div class="db-restock-tools">
            <select id="db-window" class="db-select" aria-label="Restock time window">
                <option value="full">Remaining forecast</option>
                <option value="today">Today</option>
                <option value="7">Next 7 days</option>
                <option value="14">Next 14 days</option>
                <option value="30">Next 30 days</option>
                <option value="custom">Custom range…</option>
            </select>
            <?php
            // Hard-bound the pickers to the dates the forecast actually covers,
            // so a range outside the forecast window can't be chosen at all.
            $rangeBounds = ($forecastFrom && $forecastTo)
                ? ' min="' . htmlspecialchars($forecastFrom) . '" max="' . htmlspecialchars($forecastTo) . '"'
                : '';
            ?>
            <span id="db-range" class="db-range" style="display:none">
                <input type="date" id="db-from" class="db-date" aria-label="From date"<?php echo $rangeBounds; ?>>
                <span class="db-range-sep">→</span>
                <input type="date" id="db-to" class="db-date" aria-label="To date"<?php echo $rangeBounds; ?>>
            </span>
            <select id="db-cat" class="db-select" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="db-sort" class="db-select" aria-label="Sort by">
                <option value="order_cost">Sort: Order value</option>
                <option value="demand">Sort: Demand</option>
                <option value="profit">Sort: Est. profit</option>
                <option value="name">Sort: Name</option>
            </select>
        </div>

        <!-- Print-only header (shopping list) -->
        <div class="db-print-head">
            <strong><?php echo htmlspecialchars($userName); ?> — Suggested Restock</strong>
            <span id="db-print-sub"><?php echo date('M j, Y'); ?> · remaining forecast · <?php echo $peso($kpi['total_order_cost'], 2); ?> to spend</span>
        </div>

        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th class="db-t-check"><input type="checkbox" id="db-select-all" aria-label="Select all products"></th>
                        <th class="db-t-left">Product</th>
                        <th class="db-t-left db-t-detail">Category</th>
                        <th class="db-t-detail">Forecast demand</th>
                        <th>Stock</th>
                        <th>Suggested order</th>
                        <th class="db-t-detail">Unit cost</th>
                        <th>Order cost</th>
                        <th>Est. profit</th>
                    </tr>
                </thead>
                <tbody id="db-rows">
                    <?php foreach ($restock as $r): ?>
                    <tr class="db-row"
                        data-id="<?php echo (int) $r['id']; ?>"
                        data-name="<?php echo htmlspecialchars(strtolower($r['name'])); ?>"
                        data-category="<?php echo htmlspecialchars($r['category']); ?>"
                        data-demand="<?php echo $r['demand'] !== null ? (int) $r['demand'] : 0; ?>"
                        data-order-qty="<?php echo $r['order_qty'] !== null ? (int) $r['order_qty'] : 0; ?>"
                        data-order-cost="<?php echo $r['order_cost'] !== null ? $r['order_cost'] : -1; ?>"
                        data-profit="<?php echo $r['est_profit'] !== null ? $r['est_profit'] : 0; ?>"
                        data-needs="<?php echo ($r['order_qty'] !== null && $r['order_qty'] > 0) ? 1 : 0; ?>"
                        onclick="goToProduct(<?php echo (int) $r['id']; ?>)"
                        tabindex="0">
                        <td class="db-t-check"><input type="checkbox" class="db-row-check" onclick="event.stopPropagation()" aria-label="Select <?php echo htmlspecialchars($r['name']); ?>"></td>
                        <td class="db-t-left db-t-name"><?php echo htmlspecialchars($r['name']); ?></td>
                        <td class="db-t-left db-t-muted db-t-cat db-t-detail"><?php if ($r['category'] !== ''): ?><?php echo renderCategoryList(categoryList($r['category'])); ?><span class="db-cat-print"><?php echo htmlspecialchars($r['category']); ?></span><?php else: ?>—<?php endif; ?></td>
                        <td class="db-c-demand db-t-detail"><?php echo $r['demand'] !== null ? $units($r['demand']) : '<span class="db-t-dim">' . ($r['forecast_ended'] ? 'forecast ended' : 'not forecast') . '</span>'; ?></td>
                        <td class="db-t-muted"><?php echo $r['stock'] !== null ? $units($r['stock']) : '—'; ?></td>
                        <td class="db-c-order">
                            <?php if (!$r['has_price']): ?>
                                <span class="db-t-warn">No price</span>
                            <?php elseif ($r['order_qty'] !== null && $r['order_qty'] > 0): ?>
                                <span class="db-t-order"><?php echo $units($r['order_qty']); ?></span>
                            <?php else: ?>
                                <span class="db-t-dim">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="db-t-muted db-t-detail"><?php echo $r['cost_price'] !== null ? $peso($r['cost_price']) : '—'; ?></td>
                        <td class="db-c-ordercost"><?php echo $r['order_cost'] !== null ? $peso($r['order_cost']) : '—'; ?></td>
                        <td class="db-t-green db-c-profit"><?php echo $r['est_profit'] !== null ? $peso($r['est_profit']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="db-rows-empty" class="db-rows-empty" style="display:none">No products match this filter.</div>
        </div>
        <div id="db-pager" class="db-pager">
            <button type="button" id="db-pager-prev" class="db-btn">‹ Prev</button>
            <span id="db-pager-info" class="db-pager-info"></span>
            <button type="button" id="db-pager-next" class="db-btn">Next ›</button>
            <select id="db-pagesize" class="db-select" aria-label="Rows per page">
                <option value="10">10 rows</option>
                <option value="15" selected>15 rows</option>
                <option value="25">25 rows</option>
                <option value="50">50 rows</option>
                <option value="100">100 rows</option>
            </select>
        </div>
    </section>

    <!-- ── Lower grid: attention + events ───────────────────────────────────── -->
    <div class="db-lower">

        <!-- 3. Needs attention -->
        <section class="db-card">
            <h2 class="db-card-title">Needs attention</h2>
            <?php
            $anyAttention = $attention['no_price'] || $attention['not_forecast'] || $attention['ended'] || $attention['stale'] || $attention['stockout'] || $eventsBehind > 0;
            // Renders one attention block: label, count, product chips, CTA.
            $attnBlock = function (array $rows, string $label, string $tone, string $cta, string $href) {
                if (!$rows) return;
                echo '<div class="db-attn db-attn-' . $tone . '">';
                echo '<div class="db-attn-head"><span class="db-attn-label">' . htmlspecialchars($label) . '</span><span class="db-attn-count">' . count($rows) . '</span></div>';
                echo '<div class="db-attn-chips">';
                foreach (array_slice($rows, 0, 6) as $r) {
                    echo '<a class="db-chip" href="' . BASE_URL . '/pages/forecast.view.php?product_id=' . (int) $r['id'] . '">' . htmlspecialchars($r['name']) . '</a>';
                }
                if (count($rows) > 6) echo '<span class="db-chip db-chip-more">+' . (count($rows) - 6) . ' more</span>';
                echo '</div>';
                echo '<a class="db-attn-cta" href="' . $href . '">' . htmlspecialchars($cta) . ' →</a>';
                echo '</div>';
            };
            ?>
            <?php if (!$anyAttention): ?>
            <p class="db-muted-msg">All good — nothing needs your attention right now.</p>
            <?php else: ?>
                <?php
                $attnBlock($attention['no_price'],     'No price set',       'warn',  'Set prices on the Forecast page', BASE_URL . '/pages/forecast.view.php');
                $attnBlock($attention['not_forecast'], 'Not forecast yet',   'muted', 'Generate forecasts',              BASE_URL . '/pages/forecast.view.php');
                $attnBlock($attention['ended'],        'Forecast ended',     'muted', 'Generate new forecasts',          BASE_URL . '/pages/forecast.view.php');
                $attnBlock($attention['stockout'],     'Stock below demand', 'danger','Review these products',           BASE_URL . '/pages/forecast.view.php');
                $attnBlock($attention['stale'],        'Forecasts are stale','muted', 'Re-check on the Forecast page',    BASE_URL . '/pages/forecast.view.php');
                ?>
                <?php if ($eventsBehind > 0): ?>
                <div class="db-attn db-attn-warn">
                    <div class="db-attn-head"><span class="db-attn-label">Events changed since your last forecast</span><span class="db-attn-count"><?php echo $eventsBehind; ?></span></div>
                    <p class="db-attn-note">A re-forecast may be needed to include your recent event changes.</p>
                    <a class="db-attn-cta" href="<?php echo BASE_URL; ?>/pages/import.view.php#forecast">Re-forecast in Settings →</a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- 4. Upcoming events + affected products -->
        <section class="db-card">
            <h2 class="db-card-title">Upcoming events</h2>
            <?php if (!$upcoming): ?>
            <p class="db-muted-msg">No events in the next 30 days.</p>
            <?php else: ?>
                <?php foreach ($upcoming as $ev): ?>
                <div class="db-event">
                    <div class="db-event-head">
                        <span class="db-event-dot" style="background:<?php echo htmlspecialchars($ev['color']); ?>"></span>
                        <span class="db-event-name"><?php echo htmlspecialchars($ev['name']); ?></span>
                        <span class="db-event-when">
                            <?php echo date('M j', strtotime($ev['date'])); ?>
                            · <?php echo $ev['days_until'] === 0 ? 'today' : ($ev['days_until'] === 1 ? 'tomorrow' : 'in ' . $ev['days_until'] . ' days'); ?>
                        </span>
                        <?php if ($ev['avg_impact'] !== null): ?>
                        <span class="db-event-impact"><?php echo sprintf('%+.0f%%', $ev['avg_impact']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($ev['top_products']): ?>
                    <div class="db-event-products">
                        <?php foreach ($ev['top_products'] as $tp): ?>
                        <a class="db-chip" href="<?php echo BASE_URL; ?>/pages/forecast.view.php?product_id=<?php echo (int) $tp['product_id']; ?>">
                            <?php echo htmlspecialchars($tp['product_name']); ?>
                            <span class="db-chip-pct"><?php echo $tp['impact_pct'] !== null ? sprintf('%+.0f%%', $tp['impact_pct']) : ''; ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- ── 5. Category breakdown + top movers ───────────────────────────────── -->
    <section class="db-card db-breakdown">
        <div class="db-bd-col">
            <h2 class="db-card-title">By category</h2>
            <?php
            $maxCatCost = 0.0;
            foreach ($byCategory as $c) $maxCatCost = max($maxCatCost, $c['order_cost']);
            ?>
            <?php if ($maxCatCost <= 0): ?>
            <p class="db-muted-msg">No suggested orders to break down yet.</p>
            <?php else: ?>
                <div class="db-catlist">
                <?php foreach ($byCategory as $cat => $c): ?>
                <div class="db-catrow">
                    <span class="db-catrow-label" title="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></span>
                    <div class="db-catrow-bar"><span style="width:<?php echo $maxCatCost > 0 ? round($c['order_cost'] / $maxCatCost * 100) : 0; ?>%"></span></div>
                    <span class="db-catrow-val"><?php echo $peso($c['order_cost'], 0); ?></span>
                </div>
                <?php endforeach; ?>
                </div>
                <?php if ($anyMultiCategory): ?>
                <p class="db-catnote">A product in more than one category is counted in each.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="db-bd-col">
            <div class="db-movers-head">
                <h2 class="db-card-title">Top products</h2>
                <div class="db-movers-toggle">
                    <button type="button" class="db-mt active" data-mv="order">Order value</button>
                    <button type="button" class="db-mt" data-mv="demand">Demand</button>
                    <button type="button" class="db-mt" data-mv="profit">Profit</button>
                </div>
            </div>
            <?php
            // Renders a Top-5 list. $valFn formats the metric per row.
            $moverList = function (array $rows, string $key, callable $valFn) {
                echo '<ol class="db-movers db-movers-' . $key . '"' . ($key !== 'order' ? ' style="display:none"' : '') . '>';
                foreach ($rows as $r) {
                    echo '<li class="db-mover" onclick="goToProduct(' . (int) $r['id'] . ')" tabindex="0">';
                    echo '<span class="db-mover-name">' . htmlspecialchars($r['name']) . '</span>';
                    echo '<span class="db-mover-val">' . $valFn($r) . '</span>';
                    echo '</li>';
                }
                if (!$rows) echo '<li class="db-muted-msg">Nothing to show yet.</li>';
                echo '</ol>';
            };
            $moverList($topByOrder,  'order',  fn($r) => $r['order_cost'] !== null ? $peso($r['order_cost'], 0) : '—');
            $moverList($topByDemand, 'demand', fn($r) => $r['demand'] !== null ? $units($r['demand']) . ' u' : '—');
            $moverList($topByProfit, 'profit', fn($r) => $r['est_profit'] !== null ? $peso($r['est_profit'], 0) : '—');
            ?>
        </div>
    </section>

    <?php endif; ?>

</main>

<script>
const BP_BASE = '<?php echo BASE_URL; ?>';
const STOCK_WARNING = {
    title: 'Check your current stock before buying',
    message: 'Suggested orders only count the number in the Stock column, so if you have more on your shelves, order less.'
};
</script>
<?php require_once __DIR__ . '/../includes/batch_pricing_modal.php'; ?>
<?php if ($showStockNotice): ?>
<?php require_once __DIR__ . '/../includes/toast.php'; ?>
<script>showToast(STOCK_WARNING.title + '. ' + STOCK_WARNING.message, 'warning', { bottom: true, ms: 10000 });</script>
<?php endif; ?>
<?php if ($hasProducts && $showReforecast): ?>
<script>
(function () {
    var btn   = document.getElementById('db-reforecast-btn');
    var label = document.getElementById('db-reforecast-label');
    var body  = document.getElementById('db-reforecast-body');
    var watching = <?php echo $reforecastRunning ? 'true' : 'false'; ?>;

    function setIdle() {
        btn.disabled = false;
        label.textContent = 'Re-forecast now';
    }

    function startReforecast() {
        btn.disabled = true;
        label.textContent = 'Starting…';

        fetch('<?php echo BASE_URL; ?>/api/start_forecast_job.php', { method: 'POST', body: new FormData() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    setIdle();
                    body.textContent = data.error;
                    return;
                }
                watching = true;
                label.textContent = 'Re-forecasting…';
                if (window.pvWatchForecastJob) window.pvWatchForecastJob();
            })
            .catch(function () {
                setIdle();
                body.textContent = 'Network error. Please try again.';
            });
    }

    btn.addEventListener('click', function () {
        showConfirm({
            title:        'Re-forecast every product?',
            message:      'ProVendor will make a new forecast and restock suggestion for every product, replacing the current ones. This runs in the background, so you can keep using the app.',
            confirmText:  'Re-forecast all',
            confirmStyle: 'primary',
            onConfirm:    startReforecast,
        });
    });

    // Show the new numbers as soon as the run this page is waiting on finishes.
    window.addEventListener('pv:forecast-finished', function (e) {
        if (!watching) return;
        watching = false;
        if (e.detail.status === 'done') window.location.reload();
        else setIdle();
    });
}());
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Per-product forecast series (for date-window filtering). Only forecast products
// appear; each has its order/cost/profit from today on + per-day [date, demand].
const RESTOCK = <?php
    $restockJs = [];
    foreach ($restock as $r) {
        if (!$r['forecasted']) continue;
        $restockJs[(string) $r['id']] = [
            'order'    => (int) ($r['order_qty'] ?? 0),
            'cost'     => $r['cost_price'],
            'profit'   => $r['est_profit'],
            'hasPrice' => (bool) $r['has_price'],
            'days'     => array_map(fn($x) => [$x['d'], round((float) $x['q'], 2)], $series[$r['id']] ?? []),
        ];
    }
    echo json_encode($restockJs);
?>;
const FC_BOUNDS = { from: <?php echo json_encode($forecastFrom); ?>, to: <?php echo json_encode($forecastTo); ?> };

// Row / mover click-through → that product's forecast (reuses ?product_id preselect).
function goToProduct(id) {
    window.location.href = '<?php echo BASE_URL; ?>/pages/forecast.view.php?product_id=' + id;
}

(function () {
    var tbody   = document.getElementById('db-rows');
    if (!tbody) return;                     // empty-state page: nothing to wire
    var rows    = Array.prototype.slice.call(tbody.querySelectorAll('.db-row'));
    var catSel     = document.getElementById('db-cat');
    var sortSel    = document.getElementById('db-sort');
    var emptyEl    = document.getElementById('db-rows-empty');
    var pageSizeSel= document.getElementById('db-pagesize');
    var pagerInfo  = document.getElementById('db-pager-info');
    var pagerPrev  = document.getElementById('db-pager-prev');
    var pagerNext  = document.getElementById('db-pager-next');
    var page = 1;

    // Remembers the rows-per-page choice (and the page you were on) across page
    // navigation, since this is a per-viewer convenience, not data the server
    // needs to know about. Guarded with try/catch — localStorage can throw in a
    // private window or with site data blocked.
    var PAGINATION_KEY = 'provendor_dashboard_restock_pagination';
    function loadPaginationPrefs() {
        try {
            var raw = localStorage.getItem(PAGINATION_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }
    function savePaginationPrefs(size, pageNum) {
        try {
            localStorage.setItem(PAGINATION_KEY, JSON.stringify({ size: size, page: pageNum }));
        } catch (e) { /* ignore — not essential */ }
    }
    (function restorePaginationPrefs() {
        var prefs = loadPaginationPrefs();
        if (!prefs) return;
        var validSizes = ['10', '15', '25', '50', '100'];
        if (validSizes.indexOf(prefs.size) !== -1) pageSizeSel.value = prefs.size;
        if (typeof prefs.page === 'number' && prefs.page > 0) page = prefs.page;
    }());

    // Applies the category/needs-only filter, then shows only the current page's
    // slice of the matching rows. Rows that fail the filter are hidden via inline
    // style; rows that pass but fall outside the page are hidden via a class
    // instead, so the print stylesheet can reveal them again — printing a
    // shopping list shouldn't silently truncate to whatever page was on screen.
    function rowMatchesFilter(r) {
        var cat = catSel.value;
        return !cat || r.dataset.category.split(', ').indexOf(cat) !== -1;
    }

    function renderPage() {
        var ordered   = Array.prototype.slice.call(tbody.children);
        var matched   = [];
        ordered.forEach(function (r) {
            var show = rowMatchesFilter(r);
            r.style.display = show ? '' : 'none';
            r.classList.remove('db-row-hidden-page');
            if (show) matched.push(r);
        });
        updateSelectAllState(matched);

        var pageSize   = pageSizeSel.value;
        var totalPages = Math.max(1, Math.ceil(matched.length / parseInt(pageSize, 10)));
        if (page > totalPages) page = totalPages;

        var start = (page - 1) * parseInt(pageSize, 10);
        var end   = start + parseInt(pageSize, 10);
        matched.forEach(function (r, i) {
            if (i < start || i >= end) r.classList.add('db-row-hidden-page');
        });

        emptyEl.style.display = matched.length === 0 ? '' : 'none';

        pagerInfo.textContent = 'Page ' + page + ' of ' + totalPages + ' · ' + matched.length + ' product' + (matched.length === 1 ? '' : 's');
        pagerPrev.disabled = page <= 1;
        pagerNext.disabled = page >= totalPages;
        savePaginationPrefs(pageSize, page);
    }

    // ── Row selection (only while "Export specific products…" is active) ────
    // Picking it turns on a checkbox per row (+ a header select-all) and swaps
    // the Export button for a small "N selected / Export as.../Done" bar.
    // Selection itself is independent of the current filter/page — it's meant
    // to stay put ("ongoing") as the owner keeps filtering/paging to find more
    // products to add, until they hit Done.
    var selected   = {};   // product id (string) => true
    var selecting  = false;
    var restockSection = tbody.closest('.db-restock');
    var selectAllCb    = document.getElementById('db-select-all');
    var selectBar      = document.getElementById('db-select-bar');
    var selectCount     = document.getElementById('db-select-count');
    var selectExportCsv = document.getElementById('db-select-export-csv');
    var selectExportPdf = document.getElementById('db-select-export-pdf');

    function updateSelectBar() {
        var n = Object.keys(selected).length;
        selectCount.textContent = n + ' selected';
        selectExportCsv.disabled = n === 0;
        selectExportPdf.disabled = n === 0;
    }

    // Reflects how many of the currently-filtered rows are selected onto the
    // header checkbox: checked (all selected), indeterminate (some), or empty.
    function updateSelectAllState(matched) {
        if (!selectAllCb) return;
        var selectedCount = matched.filter(function (r) { return selected[r.dataset.id]; }).length;
        selectAllCb.checked = matched.length > 0 && selectedCount === matched.length;
        selectAllCb.indeterminate = selectedCount > 0 && selectedCount < matched.length;
    }

    function startSelecting() {
        selecting = true;
        if (restockSection) restockSection.classList.add('is-selecting');
        exportToggle.style.display = 'none';
        selectBar.style.display = '';
        updateSelectBar();
        updateSelectAllState(Array.prototype.slice.call(tbody.children).filter(rowMatchesFilter));
    }

    function stopSelecting() {
        selecting = false;
        selected = {};
        rows.forEach(function (r) {
            var cb = r.querySelector('.db-row-check');
            if (cb) cb.checked = false;
        });
        if (restockSection) restockSection.classList.remove('is-selecting');
        selectBar.style.display = 'none';
        exportToggle.style.display = '';
    }

    rows.forEach(function (r) {
        var cb = r.querySelector('.db-row-check');
        if (!cb) return;
        cb.addEventListener('change', function () {
            if (cb.checked) selected[r.dataset.id] = true;
            else delete selected[r.dataset.id];
            updateSelectAllState(Array.prototype.slice.call(tbody.children).filter(rowMatchesFilter));
            updateSelectBar();
        });
    });

    if (selectAllCb) {
        selectAllCb.addEventListener('change', function () {
            var matched = Array.prototype.slice.call(tbody.children).filter(rowMatchesFilter);
            matched.forEach(function (r) {
                var cb = r.querySelector('.db-row-check');
                if (cb) cb.checked = selectAllCb.checked;
                if (selectAllCb.checked) selected[r.dataset.id] = true;
                else delete selected[r.dataset.id];
            });
            updateSelectBar();
        });
    }

    function applyFilter() {
        page = 1;
        renderPage();
    }

    function applySort() {
        var key = sortSel.value;
        var sorted = rows.slice().sort(function (a, b) {
            if (key === 'name') return a.dataset.name.localeCompare(b.dataset.name);
            var av = parseFloat(a.dataset[key === 'order_cost' ? 'orderCost' : key]);
            var bv = parseFloat(b.dataset[key === 'order_cost' ? 'orderCost' : key]);
            return bv - av;                 // numeric metrics: high → low
        });
        sorted.forEach(function (r) { tbody.appendChild(r); });
        page = 1;
        renderPage();
    }

    // ── Date-window filtering: scope the restock to today / next N days / a range.
    // The Newsvendor order is a whole-horizon number, so we prorate it (and its
    // cost/profit) by the share of forecast demand that falls inside the window.
    var winSel    = document.getElementById('db-window');
    var rangeEl   = document.getElementById('db-range');
    var fromInp   = document.getElementById('db-from');
    var toInp     = document.getElementById('db-to');
    var winNote   = document.getElementById('db-window-note');
    var demandSub = document.getElementById('kpi-demand-sub');
    var restockSub= document.getElementById('kpi-restock-sub');
    var printSub  = document.getElementById('db-print-sub');
    var FULL_LABEL   = <?php echo json_encode($windowPhrase); ?>;
    var PRINT_DATE   = <?php echo json_encode(date('M j, Y')); ?>;

    function fmtUnits(n) { return Math.round(n).toLocaleString('en-US'); }
    function fmtPeso(n, dec) { return '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }); }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function isoLocal(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }

    // ── Custom-range guards ──────────────────────────────────────────────────
    // Two rules, enforced together:
    //   1. Both dates must sit inside the forecast's own window (FC_BOUNDS) —
    //      there is no forecast data outside it, so a wider range is meaningless.
    //   2. "From" must never be after "To".
    // The min/max attributes already grey out invalid days in the picker, but a
    // typed or pasted value can still land out of range, so we clamp here too.
    function clampToBounds(v) {
        if (!v) return v;
        if (FC_BOUNDS.from && v < FC_BOUNDS.from) return FC_BOUNDS.from;
        if (FC_BOUNDS.to   && v > FC_BOUNDS.to)   return FC_BOUNDS.to;
        return v;
    }

    // `changed` is whichever input the user just edited — the OTHER one gives way,
    // so their edit is always respected rather than silently reverted.
    function enforceRange(changed) {
        fromInp.value = clampToBounds(fromInp.value);
        toInp.value   = clampToBounds(toInp.value);

        if (fromInp.value && toInp.value && fromInp.value > toInp.value) {
            if (changed === 'to') fromInp.value = toInp.value;
            else                  toInp.value   = fromInp.value;
        }

        // Narrow each picker's own limits so the crossed-over range can't even be
        // selected next time: "to" can't go before "from", and vice versa.
        if (FC_BOUNDS.from) fromInp.min = FC_BOUNDS.from;
        if (FC_BOUNDS.to)   toInp.max   = FC_BOUNDS.to;
        toInp.min   = fromInp.value || FC_BOUNDS.from || '';
        fromInp.max = toInp.value   || FC_BOUNDS.to   || '';
    }

    // [fromStr, toStr] for the window, or null for the whole forecast.
    function windowRange(mode) {
        if (mode === 'full') return null;
        if (mode === 'custom') {
            var f = fromInp.value, t = toInp.value;
            if (!f || !t) return null;
            return (f <= t) ? [f, t] : [t, f];
        }
        var today = new Date(); today.setHours(0, 0, 0, 0);
        if (mode === 'today') { var s = isoLocal(today); return [s, s]; }
        var end = new Date(today); end.setDate(end.getDate() + parseInt(mode, 10) - 1);
        return [isoLocal(today), isoLocal(end)];
    }

    function windowLabel(mode, range) {
        if (mode === 'full')   return 'remaining forecast';
        if (mode === 'today')  return 'today';
        if (mode === 'custom') return range ? (range[0] + ' → ' + range[1]) : 'custom range';
        return 'next ' + mode + ' days';
    }

    // Rescale one forecast row to the window. Non-forecast rows aren't in RESTOCK
    // and are left as they were rendered.
    function recomputeRow(r, range) {
        var b = RESTOCK[r.dataset.id];
        if (!b) return;
        var total = 0, win = 0, i;
        for (i = 0; i < b.days.length; i++) {
            total += b.days[i][1];
            if (range && b.days[i][0] >= range[0] && b.days[i][0] <= range[1]) win += b.days[i][1];
        }
        if (!range) win = total;
        var share  = total > 0 ? win / total : 0;
        var order  = Math.round(b.order * share);
        var cost   = (b.cost   != null) ? order * b.cost   : null;
        var profit = (b.profit != null) ? b.profit * share : null;

        r.dataset.demand    = Math.round(win);
        r.dataset.orderQty  = order;
        r.dataset.orderCost = (cost != null) ? cost : -1;
        r.dataset.profit    = (profit != null) ? profit : 0;
        r.dataset.needs     = (b.hasPrice && order > 0) ? '1' : '0';

        var dc = r.querySelector('.db-c-demand');    if (dc) dc.textContent = fmtUnits(win);
        var oc = r.querySelector('.db-c-order');
        if (oc) {
            if (!b.hasPrice)    oc.innerHTML = '<span class="db-t-warn">No price</span>';
            else if (order > 0) oc.innerHTML = '<span class="db-t-order">' + fmtUnits(order) + '</span>';
            else                oc.innerHTML = '<span class="db-t-dim">—</span>';
        }
        var occ = r.querySelector('.db-c-ordercost'); if (occ) occ.textContent = (cost   != null) ? fmtPeso(cost, 2)   : '—';
        var pc  = r.querySelector('.db-c-profit');    if (pc)  pc.textContent  = (profit != null) ? fmtPeso(profit, 2) : '—';
    }

    function updateKpis(label) {
        var demand = 0, orderU = 0, spend = 0, profit = 0, need = 0;
        rows.forEach(function (r) {
            if (!RESTOCK[r.dataset.id]) return;
            demand += parseFloat(r.dataset.demand) || 0;
            profit += parseFloat(r.dataset.profit) || 0;
            if (r.dataset.needs === '1') {
                orderU += parseInt(r.dataset.orderQty, 10) || 0;
                need++;
                var wc = parseFloat(r.dataset.orderCost);
                if (wc > 0) spend += wc;
            }
        });
        setText('kpi-demand', fmtUnits(demand));
        setText('kpi-restock', fmtUnits(orderU));
        setText('kpi-spend', fmtPeso(spend, 0));
        setText('kpi-profit', fmtPeso(profit, 0));
        setText('kpi-need', String(need));
        if (restockSub) restockSub.textContent = 'across ' + need + ' products';
        if (demandSub)  demandSub.textContent  = 'units · ' + label;
        if (printSub)   printSub.textContent   = PRINT_DATE + ' · ' + label + ' · ' + fmtPeso(spend, 2) + ' to spend';
    }

    function applyWindow() {
        var mode  = winSel.value;
        rangeEl.style.display = (mode === 'custom') ? '' : 'none';
        var range = windowRange(mode);
        var label = windowLabel(mode, range);
        rows.forEach(function (r) { recomputeRow(r, range); });
        updateKpis(mode === 'full' ? FULL_LABEL : label);
        if (winNote) winNote.textContent = (mode === 'full') ? '' : ' · showing ' + label;
        applySort();   // reorders rows and re-renders the current page
    }

    catSel.addEventListener('change', applyFilter);
    // Switching categories mid-selection is confusing otherwise — ticks from the
    // previous category stayed checked (just out of view), so "3 selected" no
    // longer meant what it looked like. Category changes clear the selection
    // outright rather than only dropping the now-hidden ones.
    catSel.addEventListener('change', function () {
        if (!selecting) return;
        selected = {};
        rows.forEach(function (r) {
            var cb = r.querySelector('.db-row-check');
            if (cb) cb.checked = false;
        });
        updateSelectBar();
    });
    sortSel.addEventListener('change', applySort);
    if (pageSizeSel) pageSizeSel.addEventListener('change', applyFilter);
    if (pagerPrev) pagerPrev.addEventListener('click', function () { if (page > 1) { page--; renderPage(); } });
    if (pagerNext) pagerNext.addEventListener('click', function () { page++; renderPage(); });
    renderPage();   // apply the default page size on first load

    // ── Export dropdown (CSV / PDF) ──────────────────────────────────────────
    var exportToggle = document.getElementById('db-export-toggle');
    var exportMenu    = document.getElementById('db-export-menu');
    function closeExportMenu() {
        exportMenu.style.display = 'none';
        exportToggle.setAttribute('aria-expanded', 'false');
    }
    if (exportToggle) {
        exportToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = exportMenu.style.display !== 'none';
            if (open) closeExportMenu();
            else {
                exportMenu.style.display = '';
                exportToggle.setAttribute('aria-expanded', 'true');
            }
        });
        exportMenu.addEventListener('click', function (e) {
            if (e.target.closest('.db-export-item')) closeExportMenu();
        });
        document.addEventListener('click', closeExportMenu);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeExportMenu(); });
    }

    // Every restock export goes through this stock reminder first.
    function warnBeforeExport(doExport) {
        showConfirm({
            title: STOCK_WARNING.title,
            message: STOCK_WARNING.message,
            confirmText: 'Continue export',
            confirmStyle: 'warning',
            onConfirm: doExport
        });
    }

    var exportAllCsv = document.getElementById('db-export-all-csv');
    if (exportAllCsv) exportAllCsv.addEventListener('click', function () {
        warnBeforeExport(function () { window.location.href = restockCsvUrl(); });
    });

    // "Export all" PDF just prints the current filtered/paginated-but-print-reveals-all view.
    var exportAllPdf = document.getElementById('db-export-all-pdf');
    if (exportAllPdf) exportAllPdf.addEventListener('click', function () {
        warnBeforeExport(function () { window.print(); });
    });

    // "Export specific products…" turns on checkboxes + the select bar.
    var exportPick = document.getElementById('db-export-pick');
    if (exportPick) exportPick.addEventListener('click', function () { startSelecting(); });

    var selectDone = document.getElementById('db-select-done');
    if (selectDone) selectDone.addEventListener('click', function () { stopSelecting(); });

    // Export the selected rows as CSV: hand their ids to the same CSV endpoint.
    if (selectExportCsv) selectExportCsv.addEventListener('click', function () {
        var ids = Object.keys(selected);
        if (!ids.length) return;
        warnBeforeExport(function () {
            window.location.href = restockCsvUrl() + (restockSection.classList.contains('is-simple') ? '&' : '?') + 'ids=' + ids.join(',');
        });
    });

    // Export the selected rows as PDF: force only those rows visible (ignoring
    // the current filter/page entirely — selection is its own independent
    // scope), print, then restore the normal filtered/paginated view.
    if (selectExportPdf) selectExportPdf.addEventListener('click', function () {
        var ids = Object.keys(selected);
        if (!ids.length) return;
        warnBeforeExport(function () {
            rows.forEach(function (r) {
                r.classList.remove('db-row-hidden-page');
                r.style.display = selected[r.dataset.id] ? '' : 'none';
            });
            window.print();
            renderPage();
        });
    });

    if (winSel) {
        if (FC_BOUNDS.from) fromInp.value = FC_BOUNDS.from;
        if (FC_BOUNDS.to)   toInp.value   = FC_BOUNDS.to;
        enforceRange();   // seed each picker's min/max from the starting values
        winSel.addEventListener('change', applyWindow);
        fromInp.addEventListener('change', function () {
            enforceRange('from');
            if (winSel.value === 'custom') applyWindow();
        });
        toInp.addEventListener('change', function () {
            enforceRange('to');
            if (winSel.value === 'custom') applyWindow();
        });
    }

    // Keyboard: Enter on a focused row opens the product.
    tbody.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('db-row')) e.target.click();
    });

    // Simple / Detailed table view, remembered across visits. Guarded like the
    // pagination prefs because localStorage can throw when site data is blocked.
    var VIEW_KEY = 'provendor_dashboard_restock_view';
    var viewBtns = document.querySelectorAll('.db-view-btn');
    // The CSV is built on the server, so it has to be told which view is showing.
    // (The PDF needs nothing: it prints the columns that are on screen.)
    function restockCsvUrl() {
        return BP_BASE + '/api/export_restock.php' + (restockSection.classList.contains('is-simple') ? '?view=simple' : '');
    }
    function setView(view) {
        restockSection.classList.toggle('is-simple', view === 'simple');
        viewBtns.forEach(function (b) { b.classList.toggle('active', b.dataset.view === view); });
    }
    viewBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setView(btn.dataset.view);
            try { localStorage.setItem(VIEW_KEY, btn.dataset.view); } catch (e) {}
        });
    });
    try {
        if (localStorage.getItem(VIEW_KEY) === 'detailed') setView('detailed');
    } catch (e) {}

    // Top-movers toggle.
    document.querySelectorAll('.db-mt').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mv = btn.dataset.mv;
            document.querySelectorAll('.db-mt').forEach(function (b) { b.classList.toggle('active', b === btn); });
            document.querySelectorAll('.db-movers').forEach(function (ol) {
                ol.style.display = ol.classList.contains('db-movers-' + mv) ? '' : 'none';
            });
        });
    });
}());
</script>
</body>
</html>
