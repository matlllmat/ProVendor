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
        <span class="db-horizon-chip">Next <?php echo (int) $horizon; ?> days</span>
    </div>

    <?php if (!$hasProducts): ?>
    <!-- No products at all -->
    <div class="db-empty">
        <p class="db-empty-title">No products yet</p>
        <p class="db-empty-sub">Import your sales data to start forecasting demand and restocks.</p>
        <a class="db-empty-cta" href="<?php echo BASE_URL; ?>/pages/import.view.php#import">Import sales data</a>
    </div>
    <?php else: ?>

    <!-- ── 1. KPI cards ─────────────────────────────────────────────────────── -->
    <section class="db-kpis">
        <div class="db-kpi">
            <span class="db-kpi-label">Forecast window</span>
            <span class="db-kpi-value db-kpi-window"><?php echo htmlspecialchars($windowLabel); ?></span>
            <span class="db-kpi-sub"><?php echo htmlspecialchars($windowSub); ?></span>
        </div>
        <div class="db-kpi">
            <span class="db-kpi-label">Forecast demand</span>
            <span class="db-kpi-value" id="kpi-demand"><?php echo $units($kpi['total_demand']); ?></span>
            <span class="db-kpi-sub" id="kpi-demand-sub">units · next <?php echo (int) $horizon; ?> days</span>
        </div>
        <div class="db-kpi">
            <span class="db-kpi-label">Units to restock</span>
            <span class="db-kpi-value" id="kpi-restock"><?php echo $units($kpi['total_order_qty']); ?></span>
            <span class="db-kpi-sub" id="kpi-restock-sub">across <?php echo (int) $kpi['need_restock']; ?> products</span>
        </div>
        <div class="db-kpi">
            <span class="db-kpi-label">To spend</span>
            <span class="db-kpi-value db-accent" id="kpi-spend"><?php echo $peso($kpi['total_order_cost'], 0); ?></span>
            <span class="db-kpi-sub">estimated order cost</span>
        </div>
        <div class="db-kpi">
            <span class="db-kpi-label">Est. profit</span>
            <span class="db-kpi-value db-green" id="kpi-profit"><?php echo $peso($kpi['total_profit'], 0); ?></span>
            <span class="db-kpi-sub">at forecast demand</span>
        </div>
        <div class="db-kpi">
            <span class="db-kpi-label">Needs restock</span>
            <span class="db-kpi-value"><span id="kpi-need"><?php echo (int) $kpi['need_restock']; ?></span><span class="db-kpi-of">/<?php echo (int) $kpi['product_count']; ?></span></span>
            <span class="db-kpi-sub">products with an order</span>
        </div>
        <?php if (SHOW_ACCURACY_FEATURES): ?>
        <div class="db-kpi">
            <span class="db-kpi-label">Accuracy</span>
            <span class="db-kpi-value"><?php echo $accuracy['weighted_accuracy_pct'] !== null ? number_format($accuracy['weighted_accuracy_pct'], 1) . '%' : '—'; ?></span>
            <span class="db-kpi-sub"><?php echo (int) $accuracy['evaluated_count']; ?>/<?php echo (int) $accuracy['total_count']; ?> evaluated</span>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!$hasForecasts): ?>
    <div class="db-banner">
        <span>Forecasts haven't been generated yet.</span>
        <a href="<?php echo BASE_URL; ?>/pages/forecast.view.php">Generate on the Forecast page →</a>
    </div>
    <?php endif; ?>

    <!-- ── 2. Restock overview (centerpiece) ────────────────────────────────── -->
    <section class="db-card db-restock">
        <div class="db-card-head">
            <div>
                <h2 class="db-card-title">Suggested Restock</h2>
                <p class="db-card-sub"><?php echo (int) $kpi['need_restock']; ?> of <?php echo (int) $kpi['product_count']; ?> products have a suggested order<span id="db-window-note"></span></p>
            </div>
            <div class="db-restock-tools">
                <select id="db-window" class="db-select" aria-label="Restock time window">
                    <option value="full">Whole forecast</option>
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
                <label class="db-check"><input type="checkbox" id="db-needs"> Needs restock only</label>
                <a class="db-btn" href="<?php echo BASE_URL; ?>/api/export_restock.php">Export CSV</a>
                <button type="button" class="db-btn" onclick="window.print()">Print</button>
            </div>
        </div>

        <!-- Print-only header (shopping list) -->
        <div class="db-print-head">
            <strong><?php echo htmlspecialchars($userName); ?> — Suggested Restock</strong>
            <span id="db-print-sub"><?php echo date('M j, Y'); ?> · whole forecast · <?php echo $peso($kpi['total_order_cost'], 2); ?> to spend</span>
        </div>

        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th class="db-t-left">Product</th>
                        <th class="db-t-left">Category</th>
                        <th>Forecast demand</th>
                        <th>Stock</th>
                        <th>Suggested order</th>
                        <th>Unit cost</th>
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
                        <td class="db-t-left db-t-name"><?php echo htmlspecialchars($r['name']); ?></td>
                        <td class="db-t-left db-t-muted db-t-cat"><?php echo $r['category'] !== '' ? htmlspecialchars($r['category']) : '—'; ?></td>
                        <td class="db-c-demand"><?php echo $r['demand'] !== null ? $units($r['demand']) : '<span class="db-t-dim">not forecast</span>'; ?></td>
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
                        <td class="db-t-muted"><?php echo $r['cost_price'] !== null ? $peso($r['cost_price']) : '—'; ?></td>
                        <td class="db-c-ordercost"><?php echo $r['order_cost'] !== null ? $peso($r['order_cost']) : '—'; ?></td>
                        <td class="db-t-green db-c-profit"><?php echo $r['est_profit'] !== null ? $peso($r['est_profit']) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="db-rows-empty" class="db-rows-empty" style="display:none">No products match this filter.</div>
        </div>
    </section>

    <!-- ── Lower grid: attention + events ───────────────────────────────────── -->
    <div class="db-lower">

        <!-- 3. Needs attention -->
        <section class="db-card">
            <h2 class="db-card-title">Needs attention</h2>
            <?php
            $anyAttention = $attention['no_price'] || $attention['not_forecast'] || $attention['stale'] || $attention['stockout'];
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
                $attnBlock($attention['stockout'],     'Stock below demand', 'danger','Review these products',           BASE_URL . '/pages/forecast.view.php');
                $attnBlock($attention['stale'],        'Forecasts are stale','muted', 'Re-check on the Forecast page',    BASE_URL . '/pages/forecast.view.php');
                ?>
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
                <?php foreach (array_slice($byCategory, 0, 7, true) as $cat => $c): ?>
                <div class="db-catrow">
                    <span class="db-catrow-label"><?php echo htmlspecialchars($cat); ?></span>
                    <div class="db-catrow-bar"><span style="width:<?php echo $maxCatCost > 0 ? round($c['order_cost'] / $maxCatCost * 100) : 0; ?>%"></span></div>
                    <span class="db-catrow-val"><?php echo $peso($c['order_cost'], 0); ?></span>
                </div>
                <?php endforeach; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Per-product forecast series (for date-window filtering). Only forecast products
// appear; each has its full-window order/cost/profit + per-day [date, demand].
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
    var catSel  = document.getElementById('db-cat');
    var sortSel = document.getElementById('db-sort');
    var needsCb = document.getElementById('db-needs');
    var emptyEl = document.getElementById('db-rows-empty');

    function applyFilter() {
        var cat = catSel.value;
        var needsOnly = needsCb.checked;
        var visible = 0;
        rows.forEach(function (r) {
            var show = (!cat || r.dataset.category === cat) && (!needsOnly || r.dataset.needs === '1');
            r.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        emptyEl.style.display = visible === 0 ? '' : 'none';
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
    var FULL_HORIZON = <?php echo (int) $horizon; ?>;
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
        if (mode === 'full')   return 'whole forecast';
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
        updateKpis(mode === 'full' ? ('next ' + FULL_HORIZON + ' days') : label);
        if (winNote) winNote.textContent = (mode === 'full') ? '' : ' · showing ' + label;
        applySort();
        applyFilter();
    }

    catSel.addEventListener('change', applyFilter);
    needsCb.addEventListener('change', applyFilter);
    sortSel.addEventListener('change', applySort);
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
