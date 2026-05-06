<?php
// pages/reports.view.php
// Saved forecasts — lists every saved forecast session with a chart detail modal.

require_once __DIR__ . '/reports.logic.php';

$pageTitle = 'ProVendor — Reports';
$pageCss   = 'reports.css';
$extraCss  = 'chart_modal.css';
require_once __DIR__ . '/../includes/header.php';
?>
<body class="bg-[#F0E8D0] min-h-screen dot-pattern-light">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="max-w-5xl mx-auto px-6 py-8">

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Demand Plans</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Every demand plan you've saved from the Forecast page. Click View to see the chart.
        </p>
    </div>

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
</body>
</html>
