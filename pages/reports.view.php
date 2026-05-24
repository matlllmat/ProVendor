<?php
// pages/reports.view.php
// Saved forecasts — lists every saved forecast session with a chart detail modal.

require_once __DIR__ . '/reports.logic.php';

$pageTitle = 'ProVendor — Reports';
$pageCss   = 'reports.css';
$extraCss  = 'chart_modal.css';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="max-w-5xl mx-auto px-6 py-8">

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Demand Plans</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Every demand plan you've saved from the Forecast page. Click View to see the chart.
        </p>
    </div>

    <!-- ════════════════════════════════════════════
         CATALOGUE ACCURACY (system-wide snapshot)
         Volume-weighted across all evaluated products.
    ════════════════════════════════════════════ -->
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

    <!-- ════════════════════════════════════════════
         PER-PRODUCT ACCURACY BREAKDOWN
         Surfaces which products drag the catalogue average down so the
         panelist can drill into specifics rather than guessing from aggregates.
    ════════════════════════════════════════════ -->
    <?php if (!empty($productBreakdown)): ?>
    <div class="breakdown-panel">
        <div class="breakdown-head">
            <div>
                <p class="breakdown-eyebrow">Per-Product Accuracy</p>
                <h2 class="breakdown-title">Which products affect the catalogue number most?</h2>
                <p class="breakdown-sub">
                    Sorted by impact on the catalogue weighted MAE (volume &times; absolute error).
                    Hit <span class="breakdown-refresh-glyph">&#x21BB;</span> on any row to re-run that product's backtest.
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

    <div class="session-list">

        <?php if (empty($sessions)): ?>

        <div class="session-empty">
            <svg class="session-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <p class="session-empty-title">No saved forecasts yet</p>
            <p class="session-empty-sub">Go to the Forecast page, select a product, and run a forecast to get started.</p>
        </div>

        <?php else: ?>

            <?php foreach ($sessions as $s): ?>
            <div class="session-row">

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
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</main>


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

// ── Per-product accuracy refresh (breakdown table) ────────────────────────────
// Re-runs the backtest for one product via the same endpoint the chart-modal
// chip uses, then patches the three metric cells + status badge in place.
// On success, also bumps the page so the catalogue card recomputes — we just
// re-fetch the whole page rather than redo the weighted aggregation in JS.
function bkRefreshRow(btn, productId) {
    const row = btn.closest('.bk-row');
    if (!row || btn.disabled) return;
    btn.disabled = true;
    btn.classList.add('bk-refresh-loading');

    const body = new FormData();
    body.append('product_id', productId);
    body.append('refresh',    '1');

    fetch('<?php echo BASE_URL; ?>/api/run_product_accuracy.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(function (data) {
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

            const pct  = data.accuracy_pct;
            const tone = pct >= 80 ? 'good' : pct >= 60 ? 'okay' : 'low';
            const lbl  = tone === 'good' ? 'Good' : tone === 'okay' ? 'Fair' : 'Poor';
            bkSetStatus(row, tone, lbl);
        })
        .catch(function () { btn.title = 'Refresh failed — try again.'; })
        .finally(function () {
            btn.disabled = false;
            btn.classList.remove('bk-refresh-loading');
        });
}

function bkSetStatus(row, tone, label) {
    // Reset row tone class
    row.className = row.className.replace(/\bbk-row-\w+\b/g, '').trim() + ' bk-row-' + tone;
    const badge = row.querySelector('.bk-status');
    if (badge) {
        badge.className   = 'bk-status bk-status-' + tone;
        badge.textContent = label;
    }
}

// ── Open detail modal ─────────────────────────────────────────────────────────
function openDetailModal(btn) {
    const productId      = btn.dataset.productId;
    const productName    = btn.dataset.productName;
    const generatedAt    = btn.dataset.generatedAt;
    const totalPredicted = btn.dataset.totalPredicted;
    const restockQty     = btn.dataset.restockQty;

    ChartModal.openLoading('Demand Plan', productName);

    const body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/get_forecast_detail.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(function (data) {
            if (data.error) {
                ChartModal.showResults({
                    label: 'Demand Plan', title: productName,
                    historical: [], forecast: [], hasBand: false, meta: null,
                    disabledEventIds: new Set(),
                });
                return;
            }

            const meta = data.meta || {};
            meta.total_predicted = Number(totalPredicted);
            meta.restock_qty     = Number(restockQty);

            ChartModal.showResults({
                label:           'Demand Plan',
                title:           productName,
                historical:      data.historical,
                forecast:        data.forecast,
                hasBand:         false,
                meta:            meta,
                disabledEventIds: new Set(),
            });
        })
        .catch(function () {
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
        onConfirm:    function () { deleteSession(btn, btn.dataset.productId, btn.dataset.generatedAt); },
    });
}

function deleteSession(btn, productId, generatedAt) {
    const body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/delete_forecast.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(function (data) {
            if (data.error) { alert(data.error); return; }
            const row = btn.closest('.session-row');
            if (row) row.remove();
            const list = document.querySelector('.session-list');
            if (list && list.querySelectorAll('.session-row').length === 0) {
                list.innerHTML = '<div class="session-empty">' +
                    '<p class="session-empty-title">No saved forecasts yet</p>' +
                    '<p class="session-empty-sub">Go to the Forecast page, select a product, and run a forecast.</p>' +
                    '</div>';
            }
        })
        .catch(function () { alert('Network error. Could not delete.'); });
}
</script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
