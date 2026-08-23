<?php
// pages/reports.view.php
// Forecast accuracy report: catalogue-level snapshot + per-product breakdown.

require_once __DIR__ . '/reports.logic.php';

$pageTitle = 'ProVendor — Reports';
$pageCss   = 'reports.css';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Reports</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            How accurately the model predicts demand for your products.
        </p>
    </div>

    <!-- ── Catalogue Accuracy Card ─────────────────────────────────────────── -->
    <?php
        $_ca      = $catalogueAccuracy;
        $_evald   = (int) $_ca['evaluated_count'];
        $_total   = (int) $_ca['total_count'];
        $_accPct  = $_ca['weighted_accuracy_pct'];
        $_hasData = $_accPct !== null;
        $_accTone = $_hasData
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
                        Run the catalogue forecast on the Forecast page — each product&rsquo;s accuracy is backtested
                        automatically and counted here.
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
            without a backtest yet — run the catalogue forecast, or refresh a row below, to populate.
        </p>
        <?php endif; ?>
    </div>

    <!-- ── Per-Product Accuracy Breakdown ──────────────────────────────────── -->
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
            <button type="button" id="bk-refresh-all-btn" class="bk-refresh-all-btn"
                    onclick="bkRefreshAll()">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <polyline points="1 20 1 14 7 14"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                Refresh All
            </button>
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

                        $pct   = $p['accuracy_pct'];
                        $evald = $pct !== null;
                        $tone  = !$evald
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

</main>

<script>
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);

// ── Per-product accuracy refresh (breakdown table) ────────────────────────────
function bkRefreshRow(btn, productId) {
    var row = btn.closest('.bk-row');
    if (!row || btn.disabled) return Promise.resolve();
    btn.disabled = true;
    btn.classList.add('bk-refresh-loading');

    var body = new FormData();
    body.append('product_id', productId);
    body.append('refresh',    '1');

    return fetch('<?php echo BASE_URL; ?>/api/run_product_accuracy.php', { method: 'POST', body: body })
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

function bkRefreshAll() {
    var buttons = Array.from(document.querySelectorAll('.bk-row .bk-refresh:not([disabled])'));
    if (!buttons.length) return;

    var allBtn = document.getElementById('bk-refresh-all-btn');
    if (allBtn) { allBtn.disabled = true; allBtn.textContent = 'Refreshing…'; }

    var chain = Promise.resolve();
    buttons.forEach(function(btn) {
        chain = chain.then(function() {
            var row = btn.closest('.bk-row');
            if (!row) return;
            return bkRefreshRow(btn, parseInt(row.dataset.productId, 10));
        });
    });
    chain.then(function() {
        if (allBtn) {
            allBtn.disabled  = false;
            allBtn.innerHTML =
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' +
                '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>' +
                '<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>' +
                '</svg> Refresh All';
        }
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
