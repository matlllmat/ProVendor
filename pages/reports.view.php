<?php
// pages/reports.view.php
// Two-tab reports page: Accuracy Check + Saved Forecasts (grouped by time period).

require_once __DIR__ . '/reports.logic.php';

$pageTitle = 'ProVendor — Reports';
$pageCss   = 'reports.css';
$extraCss  = 'chart_modal.css';
require_once __DIR__ . '/../includes/header.php';

// Renders one session row. Called in each of the three time-period loops below.
// Data attributes are read by the selection + summary JS.
function renderSessionRow(array $s): void { ?>
<div class="session-row"
     data-product-id="<?php echo $s['product_id']; ?>"
     data-product-name="<?php echo htmlspecialchars($s['product_name']); ?>"
     data-category="<?php echo htmlspecialchars($s['category'] ?? ''); ?>"
     data-generated-at="<?php echo htmlspecialchars($s['generated_at']); ?>"
     data-total-predicted="<?php echo (int) $s['total_predicted']; ?>"
     data-restock-qty="<?php echo (int) $s['restock_qty']; ?>"
     data-day-count="<?php echo (int) $s['day_count']; ?>"
     data-date-from="<?php echo htmlspecialchars($s['date_from']); ?>"
     data-date-to="<?php echo htmlspecialchars($s['date_to']); ?>"
     data-est-profit="<?php echo $s['est_profit'] !== null ? (float) $s['est_profit'] : ''; ?>">

    <label class="session-checkbox-wrap" onclick="event.stopPropagation()" title="Select">
        <input type="checkbox" class="session-checkbox" onchange="onRowCheckChange()">
        <span class="session-checkbox-box"></span>
    </label>

    <div class="session-info">
        <div class="session-product-line">
            <span class="session-product-name">
                <?php echo htmlspecialchars($s['product_name']); ?>
            </span>
            <?php if ($s['category']): ?>
            <span class="session-category">
                <?php echo htmlspecialchars($s['category']); ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="session-meta-line">
            <span class="session-date-range">
                <?php echo date('M j, Y', strtotime($s['date_from'])); ?>
                &rarr;
                <?php echo date('M j, Y', strtotime($s['date_to'])); ?>
                (<?php echo $s['day_count']; ?> days)
            </span>
            <span class="session-meta-sep">&middot;</span>
            <span class="session-stat">
                Forecast: <strong><?php echo number_format($s['total_predicted']); ?></strong> units
            </span>
            <span class="session-meta-sep">&middot;</span>
            <span class="session-stat">
                Order: <span class="session-stat-accent"><?php echo number_format($s['restock_qty']); ?></span> units
            </span>
        </div>
    </div>

    <div class="session-timestamp">
        Saved <?php echo date('M j, Y · g:i A', strtotime($s['generated_at'])); ?>
    </div>

    <div class="session-actions">
        <button class="session-view-btn"
                data-product-id="<?php echo $s['product_id']; ?>"
                data-product-name="<?php echo htmlspecialchars($s['product_name']); ?>"
                data-generated-at="<?php echo htmlspecialchars($s['generated_at']); ?>"
                data-total-predicted="<?php echo $s['total_predicted']; ?>"
                data-restock-qty="<?php echo $s['restock_qty']; ?>"
                data-day-count="<?php echo $s['day_count']; ?>"
                onclick="openDetailModal(this)">
            View
        </button>
        <button class="session-delete-btn"
                data-product-id="<?php echo $s['product_id']; ?>"
                data-product-name="<?php echo htmlspecialchars($s['product_name']); ?>"
                data-generated-at="<?php echo htmlspecialchars($s['generated_at']); ?>"
                onclick="confirmDeleteSession(this)"
                title="Delete this demand plan">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6"/><path d="M14 11v6"/>
                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
        </button>
    </div>

</div>
<?php }
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Reports</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Forecast accuracy and saved demand plans.
        </p>
    </div>

    <!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
    <div class="reports-tabs">
        <button class="reports-tab" data-tab="accuracy" onclick="switchTab('accuracy')">
            Accuracy Check
        </button>
        <button class="reports-tab reports-tab-active" data-tab="forecasts" onclick="switchTab('forecasts')">
            Saved Forecasts
            <?php if (!empty($sessions)): ?>
            <span class="reports-tab-count"><?php echo count($sessions); ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         TAB 1 — ACCURACY CHECK
    ══════════════════════════════════════════════════════════════════ -->
    <div id="tab-accuracy" style="display:none">

        <!-- ── Catalogue Accuracy Card ─────────────────────────────────────── -->
        <?php
            $_ca       = $catalogueAccuracy;
            $_evald    = (int) $_ca['evaluated_count'];
            $_total    = (int) $_ca['total_count'];
            $_accPct   = $_ca['weighted_accuracy_pct'];
            $_hasData  = $_accPct !== null;
            $_accTone  = $_hasData
                ? ($_accPct >= 80 ? 'good' : ($_accPct >= 60 ? 'okay' : 'low'))
                : 'note';
        ?>
        <div class="catalogue-accuracy catalogue-accuracy-<?php echo $_accTone; ?>">
            <div class="catalogue-accuracy-head">
                <div>
                    <p class="catalogue-accuracy-eyebrow">Catalogue Accuracy</p>
                    <h2 class="catalogue-accuracy-title">
                        <?php if ($_hasData): ?>
                            <strong><?php echo number_format($_accPct, 1); ?>%</strong>
                            <span class="catalogue-accuracy-title-sub">average forecast accuracy</span>
                        <?php else: ?>
                            <span class="catalogue-accuracy-title-sub">No products evaluated yet</span>
                        <?php endif; ?>
                    </h2>
                    <p class="catalogue-accuracy-sub">
                        <?php if ($_hasData): ?>
                            Volume-weighted across <strong><?php echo $_evald; ?></strong>
                            of <strong><?php echo $_total; ?></strong>
                            product<?php echo $_total !== 1 ? 's' : ''; ?>. High-volume products contribute more to this number.
                        <?php else: ?>
                            Open the Forecast page and run a forecast for any product — its accuracy will be backtested automatically
                            and counted here.
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($_hasData && $_ca['oldest_computed_at']): ?>
                <div class="catalogue-accuracy-stamp">
                    Oldest test:<br>
                    <?php echo date('M j, Y', strtotime($_ca['oldest_computed_at'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($_hasData): ?>
            <div class="catalogue-accuracy-grid">
                <div class="catalogue-accuracy-card">
                    <p class="catalogue-accuracy-label">MAPE</p>
                    <p class="catalogue-accuracy-value"><?php echo number_format($_ca['weighted_mape'], 1); ?>%</p>
                    <p class="catalogue-accuracy-unit">avg % error</p>
                </div>
                <div class="catalogue-accuracy-card">
                    <p class="catalogue-accuracy-label">MAE</p>
                    <p class="catalogue-accuracy-value"><?php echo number_format($_ca['weighted_mae'], 1); ?></p>
                    <p class="catalogue-accuracy-unit">units/day off</p>
                </div>
                <div class="catalogue-accuracy-card">
                    <p class="catalogue-accuracy-label">RMSE</p>
                    <p class="catalogue-accuracy-value"><?php echo number_format($_ca['weighted_rmse'], 1); ?></p>
                    <p class="catalogue-accuracy-unit">units/day off</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($_total > 0 && $_evald < $_total): ?>
            <p class="catalogue-accuracy-coverage">
                <?php echo $_total - $_evald; ?> product<?php echo ($_total - $_evald) !== 1 ? 's' : ''; ?>
                without a backtest yet — open the forecast modal for each to populate.
            </p>
            <?php endif; ?>
        </div>

        <!-- ── Per-Product Accuracy Breakdown ─────────────────────────────── -->
        <?php if (!empty($productBreakdown)): ?>
        <div class="breakdown-panel">
            <div class="breakdown-head">
                <div>
                    <p class="breakdown-eyebrow">Per-Product Accuracy</p>
                    <h2 class="breakdown-title">Which products affect the catalogue number most?</h2>
                    <p class="breakdown-sub">
                        Sorted by impact on the catalogue weighted MAE (volume &times; absolute error).
                        Hit <span class="breakdown-refresh-glyph">&#x21BB;</span> on any row to re-run that product&rsquo;s backtest.
                    </p>
                </div>
            </div>

            <div class="breakdown-table-wrap">
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th class="bk-col-product">Product</th>
                            <th class="bk-col-num" title="Average units sold per day (lifetime).">Avg/day</th>
                            <th class="bk-col-num" title="Mean Absolute Percentage Error — unreliable on low-volume products.">MAPE</th>
                            <th class="bk-col-num" title="Mean Absolute Error — units off per day on average.">MAE</th>
                            <th class="bk-col-num" title="Root Mean Square Error — penalizes large misses.">RMSE</th>
                            <th class="bk-col-status">Status</th>
                            <th class="bk-col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productBreakdown as $p): ?>
                        <?php
                            $totalUnits = (int) $p['total_units'];
                            $saleDays   = (int) $p['sale_days'];
                            $avgDaily   = $saleDays > 0 ? $totalUnits / $saleDays : null;

                            $pct      = $p['accuracy_pct'];
                            $evald    = $pct !== null;
                            $tone     = !$evald
                                ? 'untested'
                                : ((float) $pct >= 80 ? 'good' : ((float) $pct >= 60 ? 'okay' : 'low'));
                            $toneLabel = [
                                'good'     => 'Good',
                                'okay'     => 'Fair',
                                'low'      => 'Poor',
                                'untested' => 'Untested',
                            ][$tone];
                        ?>
                        <tr class="bk-row bk-row-<?php echo $tone; ?>" data-product-id="<?php echo (int) $p['id']; ?>">
                            <td class="bk-col-product">
                                <span class="bk-product-name"><?php echo htmlspecialchars($p['name']); ?></span>
                                <?php if (!empty($p['category'])): ?>
                                <span class="bk-product-category"><?php echo htmlspecialchars($p['category']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="bk-col-num">
                                <?php echo $avgDaily !== null ? number_format($avgDaily, 1) : '—'; ?>
                            </td>
                            <td class="bk-col-num bk-cell-mape">
                                <?php echo $p['accuracy_mape'] !== null ? number_format((float) $p['accuracy_mape'], 1) . '%' : '—'; ?>
                            </td>
                            <td class="bk-col-num bk-cell-mae">
                                <?php echo $p['accuracy_mae']  !== null ? number_format((float) $p['accuracy_mae'],  1) : '—'; ?>
                            </td>
                            <td class="bk-col-num bk-cell-rmse">
                                <?php echo $p['accuracy_rmse'] !== null ? number_format((float) $p['accuracy_rmse'], 1) : '—'; ?>
                            </td>
                            <td class="bk-col-status">
                                <span class="bk-status bk-status-<?php echo $tone; ?>"><?php echo $toneLabel; ?></span>
                            </td>
                            <td class="bk-col-actions">
                                <button type="button" class="bk-refresh"
                                        title="Re-run backtest for this product"
                                        onclick="bkRefreshRow(this, <?php echo (int) $p['id']; ?>)">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /#tab-accuracy -->

    <!-- ══════════════════════════════════════════════════════════════════
         TAB 2 — SAVED FORECASTS
         Sessions grouped by: Active Now → Upcoming → Completed (collapsed)
    ══════════════════════════════════════════════════════════════════ -->
    <div id="tab-forecasts">

        <?php if (empty($sessions)): ?>

        <div class="session-list">
            <div class="session-empty">
                <svg class="session-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <p class="session-empty-title">No saved forecasts yet</p>
                <p class="session-empty-sub">Go to the Forecast page, select a product, and run a forecast to get started.</p>
            </div>
        </div>

        <?php else: ?>

        <!-- Global controls: select all + summary hint -->
        <div class="session-controls">
            <button type="button" class="session-select-all-global" onclick="selectAll()">Select All</button>
        </div>

            <!-- ── Active Now: today is inside the forecast window ────────── -->
            <?php if (!empty($sessionsCurrent)): ?>
            <div class="session-group" id="group-current">
                <div class="session-group-header">
                    <span class="session-group-badge session-group-badge-current">Active Now</span>
                    <span class="session-group-desc">Today falls within the forecast window</span>
                    <span class="session-group-count"><?php echo count($sessionsCurrent); ?></span>
                    <button type="button" class="session-group-select-all"
                            onclick="selectGroup('group-current')">Select all</button>
                </div>
                <div class="session-list">
                    <?php foreach ($sessionsCurrent as $s): renderSessionRow($s); endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Upcoming: forecast window hasn't started yet ───────────── -->
            <?php if (!empty($sessionsFuture)): ?>
            <div class="session-group" id="group-future">
                <div class="session-group-header">
                    <span class="session-group-badge session-group-badge-future">Upcoming</span>
                    <span class="session-group-desc">Forecast window starts in the future</span>
                    <span class="session-group-count"><?php echo count($sessionsFuture); ?></span>
                    <button type="button" class="session-group-select-all"
                            onclick="selectGroup('group-future')">Select all</button>
                </div>
                <div class="session-list">
                    <?php foreach ($sessionsFuture as $s): renderSessionRow($s); endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Completed: entire window has passed — collapsed by default ── -->
            <?php if (!empty($sessionsPast)): ?>
            <div class="session-group session-group-collapsed" id="group-past">
                <div class="session-group-header session-group-header-toggle"
                     onclick="toggleGroup('group-past')">
                    <svg class="session-group-chevron" width="12" height="12" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                    <span class="session-group-badge session-group-badge-past">Completed</span>
                    <span class="session-group-desc">Forecast window has passed</span>
                    <span class="session-group-count"><?php echo count($sessionsPast); ?></span>
                    <button type="button" class="session-group-select-all"
                            onclick="event.stopPropagation(); selectGroup('group-past')">Select all</button>
                </div>
                <div class="session-list">
                    <?php foreach ($sessionsPast as $s): renderSessionRow($s); endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php endif; ?>

    </div><!-- /#tab-forecasts -->

</main>


<!-- ════════════════════════════════════════════
     FLOATING SELECTION TOOLBAR
     Appears when at least one forecast is checked.
════════════════════════════════════════════ -->
<div id="selection-toolbar" class="selection-toolbar" style="display:none">
    <span id="selection-count" class="selection-count">0 selected</span>
    <button type="button" class="selection-clear-btn" onclick="deselectAll()">Clear</button>
    <button type="button" class="selection-generate-btn" onclick="generateSummary()">
        Generate Summary &rarr;
    </button>
</div>


<!-- ════════════════════════════════════════════
     SUMMARY MODAL
     Built dynamically by generateSummary().
════════════════════════════════════════════ -->
<div id="summary-modal" class="summary-modal-overlay" style="display:none"
     onclick="closeSummaryOnBackdrop(event)">
    <div class="summary-modal-card">

        <div class="summary-modal-header">
            <div>
                <p class="summary-modal-eyebrow">Summary Report</p>
                <h2 class="summary-modal-title" id="summary-modal-title">Combined Demand Summary</h2>
            </div>
            <button type="button" class="summary-modal-close" onclick="closeSummary()" title="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div id="summary-modal-body" class="summary-modal-body"></div>

        <div class="summary-modal-footer">
            <button type="button" class="summary-print-btn" onclick="printSummary()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Print Summary
            </button>
        </div>

    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
<script>
const CHART_EVENTS = <?php echo json_encode($chartEvents); ?>;
const EVENT_COLOR  = '#FF5722';
</script>
<script src="<?php echo BASE_URL; ?>/pages/js/chart.shared.js"></script>
<script src="<?php echo BASE_URL; ?>/pages/js/chart_modal.js"></script>
<script>
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.reports-tab').forEach(function(btn) {
        btn.classList.toggle('reports-tab-active', btn.dataset.tab === tab);
    });
    document.getElementById('tab-accuracy').style.display  = tab === 'accuracy'  ? '' : 'none';
    document.getElementById('tab-forecasts').style.display = tab === 'forecasts' ? '' : 'none';
    window.location.hash = tab;
}

document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.replace('#', '');
    if (hash === 'forecasts' || hash === 'accuracy') switchTab(hash);
});

// ── Collapse / expand group ───────────────────────────────────────────────────
function toggleGroup(groupId) {
    const group = document.getElementById(groupId);
    if (!group) return;
    group.classList.toggle('session-group-collapsed');
}

// ── Selection ─────────────────────────────────────────────────────────────────
function onRowCheckChange() {
    var checked = document.querySelectorAll('.session-checkbox:checked');
    var toolbar = document.getElementById('selection-toolbar');
    var countEl = document.getElementById('selection-count');

    toolbar.style.display = checked.length > 0 ? 'flex' : 'none';
    countEl.textContent   = checked.length + ' selected';

    document.querySelectorAll('.session-row').forEach(function(row) {
        var cb = row.querySelector('.session-checkbox');
        row.classList.toggle('session-selected', cb !== null && cb.checked);
    });
}

// Select all rows in one group. Expands the group first if it's collapsed.
function selectGroup(groupId) {
    var group = document.getElementById(groupId);
    if (!group) return;
    if (group.classList.contains('session-group-collapsed')) {
        toggleGroup(groupId);
    }
    group.querySelectorAll('.session-checkbox').forEach(function(cb) {
        cb.checked = true;
    });
    onRowCheckChange();
}

// Select all rows across all groups, expanding any that are collapsed.
function selectAll() {
    document.querySelectorAll('.session-group-collapsed').forEach(function(g) {
        if (g.id) toggleGroup(g.id);
    });
    document.querySelectorAll('.session-checkbox').forEach(function(cb) {
        cb.checked = true;
    });
    onRowCheckChange();
}

function deselectAll() {
    document.querySelectorAll('.session-checkbox').forEach(function(cb) {
        cb.checked = false;
    });
    document.querySelectorAll('.session-row').forEach(function(row) {
        row.classList.remove('session-selected');
    });
    document.getElementById('selection-toolbar').style.display = 'none';
}

// ── Generate Summary ──────────────────────────────────────────────────────────
// Reads data attributes from selected rows, deduplicates to the latest session
// per product, then renders the summary modal.
function generateSummary() {
    var checked = document.querySelectorAll('.session-checkbox:checked');
    if (checked.length === 0) return;

    var rows = Array.from(checked).map(function(cb) { return cb.closest('.session-row'); });

    // Keep only the latest generated_at per product_id.
    var productMap = {};
    rows.forEach(function(row) {
        var pid = row.dataset.productId;
        var gen = row.dataset.generatedAt;
        if (!productMap[pid] || gen > productMap[pid].generatedAt) {
            productMap[pid] = {
                productId:      pid,
                productName:    row.dataset.productName,
                category:       row.dataset.category || '',
                generatedAt:    gen,
                totalPredicted: Number(row.dataset.totalPredicted),
                restockQty:     Number(row.dataset.restockQty),
                dayCount:       Number(row.dataset.dayCount),
                dateFrom:       row.dataset.dateFrom,
                dateTo:         row.dataset.dateTo,
                estProfit:      row.dataset.estProfit !== '' ? Number(row.dataset.estProfit) : null,
            };
        }
    });

    // Sort by restock qty descending so the biggest orders appear first.
    var products = Object.values(productMap).sort(function(a, b) {
        return b.restockQty - a.restockQty;
    });

    var selectedCount = rows.length;
    var productCount  = products.length;
    var skipped       = selectedCount - productCount;

    var totalRestock   = products.reduce(function(s, p) { return s + p.restockQty;     }, 0);
    var totalDemand    = products.reduce(function(s, p) { return s + p.totalPredicted; }, 0);
    var hasProfit      = products.some(function(p) { return p.estProfit !== null; });
    var totalProfit    = hasProfit
        ? products.filter(function(p) { return p.estProfit !== null; })
                  .reduce(function(s, p) { return s + p.estProfit; }, 0)
        : null;

    function fmtDate(d) {
        if (!d) return '—';
        var dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function fmtNum(n) {
        return Number(n).toLocaleString();
    }
    function fmtMoney(n) {
        return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Totals summary cards ──────────────────────────────────────────────────
    var profitCard = '';
    if (totalProfit !== null) {
        profitCard =
            '<div class="sr-total-card sr-total-card-green">' +
                '<p class="sr-total-label">Est. Combined Profit</p>' +
                '<p class="sr-total-value">' + fmtMoney(totalProfit) + '</p>' +
            '</div>';
    }

    var totalsBar =
        '<div class="sr-totals-bar">' +
            '<div class="sr-total-card">' +
                '<p class="sr-total-label">Products</p>' +
                '<p class="sr-total-value">' + productCount + '</p>' +
            '</div>' +
            '<div class="sr-total-card">' +
                '<p class="sr-total-label">Total Forecast</p>' +
                '<p class="sr-total-value">' + fmtNum(totalDemand) + '</p>' +
                '<p class="sr-total-unit">units</p>' +
            '</div>' +
            '<div class="sr-total-card sr-total-card-accent">' +
                '<p class="sr-total-label">Total to Order</p>' +
                '<p class="sr-total-value">' + fmtNum(totalRestock) + '</p>' +
                '<p class="sr-total-unit">units</p>' +
            '</div>' +
            profitCard +
        '</div>';

    // ── Detail table ──────────────────────────────────────────────────────────
    var profitHeader = hasProfit ? '<th class="sr-th-num">Est. Profit</th>' : '';
    var headerRow =
        '<tr>' +
            '<th class="sr-th-product">Product</th>' +
            '<th class="sr-th-window">Forecast Window</th>' +
            '<th class="sr-th-num">Demand</th>' +
            '<th class="sr-th-num">Order Qty</th>' +
            profitHeader +
        '</tr>';

    var tableRows = '';
    products.forEach(function(p) {
        var profitCell = hasProfit
            ? '<td class="sr-cell-num">' + (p.estProfit !== null ? fmtMoney(p.estProfit) : '—') + '</td>'
            : '';
        tableRows +=
            '<tr class="sr-row sr-row-clickable"' +
            ' data-product-id="'      + esc(p.productId)      + '"' +
            ' data-product-name="'    + esc(p.productName)    + '"' +
            ' data-generated-at="'    + esc(p.generatedAt)    + '"' +
            ' data-total-predicted="' + p.totalPredicted       + '"' +
            ' data-restock-qty="'     + p.restockQty           + '"' +
            ' title="Click to view full forecast chart"' +
            ' onclick="openFromSummaryRow(this)">' +
                '<td class="sr-cell-product">' +
                    '<span class="sr-product-name">' + esc(p.productName) + '</span>' +
                    (p.category ? '<span class="sr-product-cat">' + esc(p.category) + '</span>' : '') +
                    '<span class="sr-row-view-hint">View &rarr;</span>' +
                '</td>' +
                '<td class="sr-cell-window">' +
                    fmtDate(p.dateFrom) + ' &rarr; ' + fmtDate(p.dateTo) +
                    '<br><span class="sr-days">' + p.dayCount + ' days</span>' +
                '</td>' +
                '<td class="sr-cell-num">' + fmtNum(p.totalPredicted) + '</td>' +
                '<td class="sr-cell-num sr-cell-restock">' + fmtNum(p.restockQty) + '</td>' +
                profitCell +
            '</tr>';
    });

    var profitTotals = hasProfit
        ? '<td class="sr-cell-num">' + (totalProfit !== null ? '<strong>' + fmtMoney(totalProfit) + '</strong>' : '—') + '</td>'
        : '';
    var totalsRow =
        '<tr class="sr-totals">' +
            '<td class="sr-cell-product"><strong>' + productCount + ' product' + (productCount !== 1 ? 's' : '') + '</strong></td>' +
            '<td class="sr-cell-window"></td>' +
            '<td class="sr-cell-num"><strong>' + fmtNum(totalDemand) + '</strong></td>' +
            '<td class="sr-cell-num sr-cell-restock"><strong>' + fmtNum(totalRestock) + '</strong></td>' +
            profitTotals +
        '</tr>';

    var dedupeNote = skipped > 0
        ? '<p class="sr-dedup-note">' + skipped + ' duplicate session' + (skipped !== 1 ? 's' : '') + ' removed — only the most recent forecast per product is shown.</p>'
        : '';

    var tableHtml =
        '<div class="sr-table-wrap">' +
            '<table class="sr-table">' +
                '<thead>' + headerRow + '</thead>' +
                '<tbody>' + tableRows + totalsRow + '</tbody>' +
            '</table>' +
        '</div>' + dedupeNote;

    document.getElementById('summary-modal-title').textContent = 'Combined Demand Summary';
    document.getElementById('summary-modal-body').innerHTML    = totalsBar + tableHtml;
    document.getElementById('summary-modal').style.display     = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeSummary() {
    document.getElementById('summary-modal').style.display = 'none';
    document.body.style.overflow = '';
}

function closeSummaryOnBackdrop(event) {
    if (event.target === document.getElementById('summary-modal')) closeSummary();
}

function printSummary() {
    window.print();
}

// Opens the full forecast chart for a product row in the summary table.
// Chart modal sits at z-1200, above the summary (z-1100), so summary stays visible behind it.
function openFromSummaryRow(tr) {
    var productId      = tr.dataset.productId;
    var productName    = tr.dataset.productName;
    var generatedAt    = tr.dataset.generatedAt;
    var totalPredicted = tr.dataset.totalPredicted;
    var restockQty     = tr.dataset.restockQty;

    ChartModal.openLoading('Demand Plan', productName);

    var body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/get_forecast_detail.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                ChartModal.showResults({
                    label: 'Demand Plan', title: productName,
                    historical: [], forecast: [], hasBand: false, meta: null,
                    disabledEventIds: new Set(),
                });
                return;
            }
            var meta = data.meta || {};
            meta.total_predicted = Number(totalPredicted);
            meta.restock_qty     = Number(restockQty);
            ChartModal.showResults({
                label:            'Demand Plan',
                title:            productName,
                historical:       data.historical,
                forecast:         data.forecast,
                hasBand:          false,
                meta:             meta,
                disabledEventIds: new Set(),
            });
        })
        .catch(function() {
            ChartModal.showResults({
                label: 'Demand Plan', title: productName,
                historical: [], forecast: [], hasBand: false, meta: null,
                disabledEventIds: new Set(),
            });
        });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('summary-modal').style.display !== 'none') {
        closeSummary();
    }
});

// ── Per-product accuracy refresh (breakdown table) ────────────────────────────
function bkRefreshRow(btn, productId) {
    var row = btn.closest('.bk-row');
    if (!row || btn.disabled) return;
    btn.disabled = true;
    btn.classList.add('bk-refresh-loading');

    var body = new FormData();
    body.append('product_id', productId);
    body.append('refresh',    '1');

    fetch('<?php echo BASE_URL; ?>/api/run_product_accuracy.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                row.querySelector('.bk-cell-mape').textContent = '—';
                row.querySelector('.bk-cell-mae').textContent  = '—';
                row.querySelector('.bk-cell-rmse').textContent = '—';
                bkSetStatus(row, 'untested', 'Untested');
                btn.title = data.error;
                return;
            }
            row.querySelector('.bk-cell-mape').textContent =
                data.mape != null ? data.mape.toFixed(1) + '%' : '—';
            row.querySelector('.bk-cell-mae').textContent =
                data.mae  != null ? data.mae.toFixed(1)  : '—';
            row.querySelector('.bk-cell-rmse').textContent =
                data.rmse != null ? data.rmse.toFixed(1) : '—';

            var pct  = data.accuracy_pct;
            var tone = pct >= 80 ? 'good' : pct >= 60 ? 'okay' : 'low';
            var lbl  = tone === 'good' ? 'Good' : tone === 'okay' ? 'Fair' : 'Poor';
            bkSetStatus(row, tone, lbl);
        })
        .catch(function() { btn.title = 'Refresh failed — try again.'; })
        .finally(function() {
            btn.disabled = false;
            btn.classList.remove('bk-refresh-loading');
        });
}

function bkSetStatus(row, tone, label) {
    row.className = row.className.replace(/\bbk-row-\w+\b/g, '').trim() + ' bk-row-' + tone;
    var badge = row.querySelector('.bk-status');
    if (badge) {
        badge.className   = 'bk-status bk-status-' + tone;
        badge.textContent = label;
    }
}

// ── Open detail modal ─────────────────────────────────────────────────────────
function openDetailModal(btn) {
    var productId      = btn.dataset.productId;
    var productName    = btn.dataset.productName;
    var generatedAt    = btn.dataset.generatedAt;
    var totalPredicted = btn.dataset.totalPredicted;
    var restockQty     = btn.dataset.restockQty;

    ChartModal.openLoading('Demand Plan', productName);

    var body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/get_forecast_detail.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                ChartModal.showResults({
                    label: 'Demand Plan', title: productName,
                    historical: [], forecast: [], hasBand: false, meta: null,
                    disabledEventIds: new Set(),
                });
                return;
            }

            var meta = data.meta || {};
            meta.total_predicted = Number(totalPredicted);
            meta.restock_qty     = Number(restockQty);

            ChartModal.showResults({
                label:            'Demand Plan',
                title:            productName,
                historical:       data.historical,
                forecast:         data.forecast,
                hasBand:          false,
                meta:             meta,
                disabledEventIds: new Set(),
            });
        })
        .catch(function() {
            ChartModal.showResults({
                label: 'Demand Plan', title: productName,
                historical: [], forecast: [], hasBand: false, meta: null,
                disabledEventIds: new Set(),
            });
        });
}

// ── Delete ────────────────────────────────────────────────────────────────────
function confirmDeleteSession(btn) {
    showConfirm({
        title:        'Delete this demand plan?',
        message:      'The demand plan for "' + btn.dataset.productName + '" will be permanently removed.',
        confirmText:  'Delete',
        confirmStyle: 'danger',
        onConfirm:    function() { deleteSession(btn, btn.dataset.productId, btn.dataset.generatedAt); },
    });
}

function deleteSession(btn, productId, generatedAt) {
    var body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/delete_forecast.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) { alert(data.error); return; }

            var row   = btn.closest('.session-row');
            var group = row ? row.closest('.session-group') : null;
            if (row) row.remove();

            // Uncheck any now-missing row from the selection count.
            onRowCheckChange();

            // Hide the group section if it now has no rows left.
            if (group && group.querySelectorAll('.session-row').length === 0) {
                group.style.display = 'none';
            }

            // Show the empty state if no sessions remain anywhere.
            if (document.querySelectorAll('#tab-forecasts .session-row').length === 0) {
                document.getElementById('tab-forecasts').innerHTML =
                    '<div class="session-list"><div class="session-empty">' +
                    '<p class="session-empty-title">No saved forecasts yet</p>' +
                    '<p class="session-empty-sub">Go to the Forecast page, select a product, and run a forecast.</p>' +
                    '</div></div>';
            }
        })
        .catch(function() { alert('Network error. Could not delete.'); });
}
</script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
