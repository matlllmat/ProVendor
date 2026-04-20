<?php
// pages/reports.view.php
// Saved forecasts — lists every saved forecast session with a chart detail modal.

require_once __DIR__ . '/reports.logic.php';

$pageTitle = 'ProVendor — Reports';
$pageCss   = 'reports.css';
require_once __DIR__ . '/../includes/header.php';
?>
<body class="bg-[#F0E8D0] min-h-screen dot-pattern-light">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <!-- Page heading -->
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Demand Plans</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Every demand plan you've saved from the Forecast page. Click View to see the chart.
        </p>
    </div>

    <!-- Session list -->
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

                <!-- Left: product name + summary stats -->
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

                <!-- Centre: save timestamp -->
                <div class="session-timestamp">
                    Saved <?php echo date('M j, Y · g:i A', strtotime($s['generated_at'])); ?>
                </div>

                <!-- Right: actions -->
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


<!-- ════════════════════════════════════════════
     DETAIL MODAL
════════════════════════════════════════════ -->
<div id="rpt-modal" class="fixed inset-0 z-[1000] flex items-center justify-center hidden"
     role="dialog" aria-modal="true" aria-labelledby="rpt-modal-title">

    <!-- Backdrop -->
    <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)" onclick="closeDetailModal()"></div>

    <!-- Card -->
    <div class="rpt-modal-card">

        <!-- Header -->
        <div class="rpt-modal-header">
            <div style="min-width:0">
                <p class="rpt-modal-label">Demand Plan</p>
                <h2 id="rpt-modal-title" class="rpt-modal-title">—</h2>
            </div>
            <button class="rpt-modal-close" onclick="closeDetailModal()" title="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Loading -->
        <div id="rpt-loading" class="rpt-modal-loading">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" style="animation:spin 1s linear infinite">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
            </svg>
            Loading chart…
        </div>

        <!-- Error -->
        <div id="rpt-error" class="rpt-modal-error" style="display:none"></div>

        <!-- Content (hidden until loaded) -->
        <div id="rpt-content" style="display:none">

            <!-- Chart -->
            <div class="rpt-chart-wrap">
                <div class="rpt-chart-controls">
                    <div class="rpt-chart-legend">
                        <span class="rpt-legend-item">
                            <svg width="18" height="10" viewBox="0 0 18 10">
                                <line x1="0" y1="5" x2="18" y2="5" stroke="#1A6933" stroke-width="2"/>
                            </svg>
                            Historical
                            <span class="rpt-legend-info" data-tip="Actual units sold each day before this plan was created — the real demand pattern the model learned from.">ⓘ</span>
                        </span>
                        <span class="rpt-legend-item">
                            <svg width="18" height="10" viewBox="0 0 18 10">
                                <line x1="0" y1="5" x2="18" y2="5" stroke="#FF5722" stroke-width="2"
                                      stroke-dasharray="5 3"/>
                            </svg>
                            Projected Demand
                            <span class="rpt-legend-info" data-tip="Units the model predicts will be needed each day. This drives the recommended order quantity.">ⓘ</span>
                        </span>
                    </div>
                    <div class="rpt-chart-btns">
                        <button id="rpt-events-btn" class="rpt-events-btn" onclick="toggleRptEvents()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                            Events
                        </button>
                        <button class="rpt-zoom-reset-btn" onclick="if(rptChart) rptChart.resetZoom()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                <path d="M3 3v5h5"/>
                            </svg>
                            Reset Zoom
                        </button>
                    </div>
                </div>
                <canvas id="rpt-chart" style="max-height:280px"></canvas>
            </div>

            <!-- Stat cards -->
            <div class="rpt-stats-grid">
                <div class="rpt-stat-card">
                    <p class="rpt-stat-label">Total Forecast</p>
                    <p id="rpt-stat-demand" class="rpt-stat-value"></p>
                    <p class="rpt-stat-sub">units predicted</p>
                </div>
                <div class="rpt-stat-card">
                    <p class="rpt-stat-label">Current Stock</p>
                    <p id="rpt-stat-stock" class="rpt-stat-value"></p>
                    <p class="rpt-stat-sub">units on hand</p>
                </div>
                <div class="rpt-stat-card">
                    <p class="rpt-stat-label">Order Qty</p>
                    <p id="rpt-stat-order" class="rpt-stat-value rpt-stat-accent"></p>
                    <p class="rpt-stat-sub">units to order</p>
                </div>
                <div class="rpt-stat-card">
                    <p class="rpt-stat-label">Est. Profit</p>
                    <p id="rpt-stat-profit" class="rpt-stat-value rpt-stat-green"></p>
                    <p class="rpt-stat-sub">at forecast demand</p>
                </div>
            </div>

            <!-- Newsvendor explanation -->
            <div id="rpt-nv-section" class="rpt-nv-section">
                <button class="rpt-nv-header" onclick="toggleRptNvSection()">
                    <span class="rpt-nv-title">Newsvendor Model — How this was calculated</span>
                    <svg id="rpt-nv-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                         style="transition:transform 0.2s; flex-shrink:0">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div id="rpt-nv-body" class="rpt-nv-body">
                    <!-- Filled by renderDetailNvExplanation() -->
                </div>
            </div>

            <!-- Weekly forecast bar chart -->
            <div class="rpt-weekly-wrap">
                <p class="rpt-section-label">Weekly Demand Forecast</p>
                <canvas id="rpt-weekly-chart" style="max-height:170px"></canvas>
            </div>

        </div><!-- /rpt-content -->

    </div><!-- /rpt-modal-card -->
</div><!-- /rpt-modal -->


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
<script>
const CHART_EVENTS = <?php echo json_encode($chartEvents); ?>;
const EVENT_COLOR  = '#FF5722';
</script>
<script>
// Spin animation for loading icons
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);

let rptChart        = null;
let rptWeeklyChart  = null;
let rptHighlight    = false;
let rptNvOpen       = true;

// ── Open detail modal ─────────────────────────────────────────────────────────
function openDetailModal(btn) {
    const productId     = btn.dataset.productId;
    const productName   = btn.dataset.productName;
    const generatedAt   = btn.dataset.generatedAt;
    const totalPredicted = btn.dataset.totalPredicted;
    const restockQty    = btn.dataset.restockQty;
    const dayCount      = btn.dataset.dayCount;

    // Reset modal state
    document.getElementById('rpt-modal-title').textContent    = productName;
    document.getElementById('rpt-loading').style.display      = '';
    document.getElementById('rpt-error').style.display        = 'none';
    document.getElementById('rpt-content').style.display      = 'none';
    document.getElementById('rpt-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    if (rptChart)       { rptChart.destroy();       rptChart       = null; }
    if (rptWeeklyChart) { rptWeeklyChart.destroy();  rptWeeklyChart = null; }
    rptHighlight = false;
    updateRptEventsBtn();

    // Fetch chart data + session metadata
    const body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/get_forecast_detail.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('rpt-loading').style.display = 'none';
            if (data.error) {
                const errEl       = document.getElementById('rpt-error');
                errEl.textContent = data.error;
                errEl.style.display = '';
                return;
            }

            const meta = data.meta || {};

            // Stat cards
            document.getElementById('rpt-stat-demand').textContent =
                Number(totalPredicted).toLocaleString() + ' units';
            document.getElementById('rpt-stat-stock').textContent =
                (meta.current_stock !== null && meta.current_stock !== undefined)
                    ? meta.current_stock + ' units' : '—';
            document.getElementById('rpt-stat-order').textContent =
                Number(restockQty).toLocaleString() + ' units';
            document.getElementById('rpt-stat-profit').textContent =
                (meta.est_profit !== null && meta.est_profit !== undefined)
                    ? '₱' + meta.est_profit.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    : '—';

            document.getElementById('rpt-content').style.display = '';
            renderDetailChart(data.historical, data.forecast);
            renderDetailWeeklyChart(data.forecast);
            if (meta.cost_price) {
                meta.total_predicted = Number(totalPredicted);
                renderDetailNvExplanation(meta);
            }
            else document.getElementById('rpt-nv-section').style.display = 'none';
        })
        .catch(function () {
            document.getElementById('rpt-loading').style.display = 'none';
            const errEl       = document.getElementById('rpt-error');
            errEl.textContent = 'Network error. Could not load chart.';
            errEl.style.display = '';
        });
}

function closeDetailModal() {
    document.getElementById('rpt-modal').classList.add('hidden');
    document.body.style.overflow = '';
    if (rptChart)       { rptChart.destroy();       rptChart       = null; }
    if (rptWeeklyChart) { rptWeeklyChart.destroy();  rptWeeklyChart = null; }
}

// ── Helpers shared with annotation logic ─────────────────────────────────────
function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return 'rgba('+r+','+g+','+b+','+alpha+')';
}
function tsToDateStr(ts) {
    const d = new Date(ts);
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}

// ── Event annotations (all events shown — no per-event filter in reports) ────
function buildRptAnnotations(visibleFrom, visibleTo) {
    const visible = CHART_EVENTS.filter(function (ev) {
        const evEnd = ev.instance_end || ev.instance_start;
        if (visibleFrom && evEnd             < visibleFrom) return false;
        if (visibleTo   && ev.instance_start > visibleTo)   return false;
        return true;
    });
    const compact = visible.length > 8;

    // ── Lane assignment — stagger labels so nearby events don't pile up ───────
    const totalDays     = (visibleFrom && visibleTo)
        ? Math.max(1, (new Date(visibleTo) - new Date(visibleFrom)) / 86400000)
        : 365;
    const proximityDays = Math.max(3, Math.floor(totalDays * 0.04));
    const laneH         = compact ? 18 : 26;
    const baseY         = compact ?  4 : -4;

    const sorted = visible.slice().sort(function (a, b) {
        return a.instance_start < b.instance_start ? -1 : 1;
    });
    const laneEnd = [];
    const yAdjOf  = [];

    sorted.forEach(function (ev) {
        const evEnd = ev.instance_end || ev.instance_start;
        let lane    = -1;
        for (let l = 0; l < laneEnd.length; l++) {
            const gap = (new Date(ev.instance_start) - new Date(laneEnd[l])) / 86400000;
            if (gap >= proximityDays) { lane = l; break; }
        }
        if (lane === -1) { lane = laneEnd.length; laneEnd.push(''); }
        laneEnd[lane] = evEnd;
        yAdjOf.push(baseY + lane * laneH);
    });

    const annotations = {};
    sorted.forEach(function (ev, i) {
        const color = ev.color || EVENT_COLOR;
        const yAdj  = yAdjOf[i];
        const key   = 'evt-' + i;
        if (compact) {
            annotations[key] = {
                type: 'line', scaleID: 'x', value: ev.instance_start,
                borderColor: hexToRgba(color, 0.25), borderWidth: 1, borderDash: [2,5],
                label: {
                    display: true, content: '●', position: 'start',
                    backgroundColor: color, color: '#fff',
                    font: { size: 7, weight: '700', family: 'Lora' },
                    padding: { x: 3, y: 2 }, borderRadius: 99, yAdjust: yAdj,
                },
            };
        } else if (ev.instance_end && ev.instance_end !== ev.instance_start) {
            annotations[key] = {
                type: 'box', xMin: ev.instance_start, xMax: ev.instance_end,
                backgroundColor: hexToRgba(color, 0.08), borderColor: color, borderWidth: 1,
                label: {
                    display: true, content: ev.name, position: { x: 'start', y: 'start' },
                    backgroundColor: hexToRgba(color, 0.88), color: '#fff',
                    font: { size: 9, family: 'Lora' }, padding: { x: 5, y: 3 }, borderRadius: 3,
                    yAdjust: yAdj,
                },
            };
        } else {
            annotations[key] = {
                type: 'line', scaleID: 'x', value: ev.instance_start,
                borderColor: color, borderWidth: 1.5, borderDash: [4,3],
                label: {
                    display: true, content: ev.name, position: 'start',
                    backgroundColor: hexToRgba(color, 0.88), color: '#fff',
                    font: { size: 9, family: 'Lora' }, padding: { x: 5, y: 3 }, borderRadius: 3,
                    yAdjust: yAdj,
                },
            };
        }
    });
    return annotations;
}

function updateRptAnnotationsOnZoom({ chart }) {
    if (!rptHighlight || !chart.scales.x) return;
    const forecastStartDate = chart.data.datasets[1] && chart.data.datasets[1].data.length
        ? chart.data.datasets[1].data[0].x : null;
    const base = forecastStartDate ? { forecastStart: buildForecastStartAnnotation(forecastStartDate) } : {};
    chart.options.plugins.annotation.annotations = Object.assign(
        base, buildRptAnnotations(tsToDateStr(chart.scales.x.min), tsToDateStr(chart.scales.x.max))
    );
    chart.update('none');
}

function buildForecastStartAnnotation(startDate) {
    return {
        type: 'line', scaleID: 'x', value: startDate,
        borderColor: 'rgba(38,31,14,0.25)', borderWidth: 1, borderDash: [4,4],
        label: {
            display: true, content: 'Forecast', position: 'start',
            backgroundColor: 'rgba(38,31,14,0.72)', color: '#F0E8D0',
            font: { size: 9, family: 'Lora' }, padding: { x: 5, y: 3 }, borderRadius: 3, yAdjust: -4,
        },
    };
}

// ── Events toggle ─────────────────────────────────────────────────────────────
function toggleRptEvents() {
    rptHighlight = !rptHighlight;
    updateRptEventsBtn();
    if (!rptChart || !rptChart.scales.x) return;
    const forecastDs = rptChart.data.datasets[1];
    const fStart = forecastDs && forecastDs.data.length ? forecastDs.data[0].x : null;
    const base = fStart ? { forecastStart: buildForecastStartAnnotation(fStart) } : {};
    rptChart.options.plugins.annotation.annotations = rptHighlight
        ? Object.assign(base, buildRptAnnotations(
            tsToDateStr(rptChart.scales.x.min), tsToDateStr(rptChart.scales.x.max)))
        : base;
    rptChart.update('none');
}

function updateRptEventsBtn() {
    const btn = document.getElementById('rpt-events-btn');
    if (!btn) return;
    btn.style.background  = rptHighlight ? '#261F0E'  : 'transparent';
    btn.style.color       = rptHighlight ? '#F0E8D0'  : '#261F0E';
    btn.style.borderColor = rptHighlight ? '#261F0E'  : '#D2C8AE';
    btn.style.opacity     = rptHighlight ? '1'        : '0.5';
}

// ── Render the detail chart ───────────────────────────────────────────────────
function renderDetailChart(historical, forecast) {
    if (rptChart) rptChart.destroy();

    const visibleFrom = historical.length ? historical[0].date : (forecast.length ? forecast[0].date : null);
    const visibleTo   = forecast.length   ? forecast[forecast.length - 1].date : null;
    const annotations = forecast.length ? { forecastStart: buildForecastStartAnnotation(forecast[0].date) } : {};

    // Default view: 6 months before forecast start so the chart opens on recent
    // history + the full forecast rather than years of old data on the left.
    let initialMin = visibleFrom;
    if (forecast.length) {
        const d = new Date(forecast[0].date);
        d.setMonth(d.getMonth() - 6);
        initialMin = d.toISOString().slice(0, 10);
    }

    rptChart = new Chart(document.getElementById('rpt-chart'), {
        type: 'line',
        data: {
            datasets: [
                {
                    label: 'Historical',
                    data: (function () {
                        const map = {};
                        historical.forEach(function (r) { map[r.date] = r.actual; });
                        return Object.keys(map).sort().map(function (d) { return { x: d, y: map[d] }; });
                    }()),
                    borderColor: '#1A6933', borderWidth: 2, backgroundColor: 'transparent',
                    pointRadius: 0, fill: false, tension: 0.3,
                },
                {
                    label: 'Projected Demand',
                    data: (function () {
                        // Guard against duplicate dates from the DB — keep last value per date.
                        const map = {};
                        forecast.forEach(function (r) { map[r.date] = r.predicted; });
                        return Object.keys(map).sort().map(function (d) { return { x: d, y: map[d] }; });
                    }()),
                    borderColor: '#FF5722', borderWidth: 2, borderDash: [6,3],
                    backgroundColor: 'transparent', pointRadius: 0, fill: false, tension: 0.3,
                },
            ],
        },
        options: {
            responsive: true,
            interaction: { mode: 'x', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x', onZoomComplete: updateRptAnnotationsOnZoom },
                    pan:  { enabled: true, mode: 'x', onPanComplete: updateRptAnnotationsOnZoom },
                },
                tooltip: {
                    backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10,
                    callbacks: {
                        label: function (ctx) {
                            if (ctx.parsed.y === null) return null;
                            return ' ' + ctx.dataset.label + ': ' + Math.round(ctx.parsed.y) + ' units';
                        },
                    },
                },
                annotation: { annotations: annotations },
            },
            scales: {
                x: {
                    type: 'time',
                    min: initialMin,
                    time: { minUnit: 'day', tooltipFormat: 'yyyy-MM-dd', displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM yyyy' } },
                    ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 }, maxTicksLimit: 10, maxRotation: 0 },
                    grid: { color: 'rgba(38,31,14,0.06)' },
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } },
                    grid: { color: 'rgba(38,31,14,0.06)' },
                },
            },
        },
    });
}

// ── Weekly forecast bar chart ─────────────────────────────────────────────────
function renderDetailWeeklyChart(forecastRows) {
    const weekMap = {};
    forecastRows.forEach(function (r) {
        const d = new Date(r.date), dow = d.getDay();
        const mon = new Date(d);
        mon.setDate(d.getDate() - (dow === 0 ? 6 : dow - 1));
        const key = mon.toISOString().slice(0, 10);
        weekMap[key] = (weekMap[key] || 0) + r.predicted;
    });
    const weeks  = Object.keys(weekMap).sort();
    const labels = weeks.map(function (w) {
        const d = new Date(w + 'T00:00:00');
        return d.toLocaleString('default', { month: 'short', day: 'numeric' });
    });
    const values = weeks.map(function (w) { return Math.round(weekMap[w]); });

    if (rptWeeklyChart) rptWeeklyChart.destroy();
    rptWeeklyChart = new Chart(document.getElementById('rpt-weekly-chart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Forecast', data: values,
                backgroundColor: 'rgba(255,87,34,0.65)', borderColor: '#FF5722',
                borderWidth: 1, borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10,
                    callbacks: {
                        title: function (items) { return 'Week of ' + items[0].label; },
                        label: function (ctx)   { return ' ' + ctx.parsed.y + ' units predicted'; },
                    },
                },
            },
            scales: {
                x: { ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } }, grid: { color: 'rgba(38,31,14,0.06)' } },
            },
        },
    });
}

// ── Newsvendor explanation ────────────────────────────────────────────────────
function renderDetailNvExplanation(meta) {
    document.getElementById('rpt-nv-section').style.display = '';
    const body = document.getElementById('rpt-nv-body');
    if (!body) return;

    const price  = meta.selling_price, cost  = meta.cost_price;
    const margin = price - cost;
    const cr     = (margin / price * 100).toFixed(1);
    const low    = Math.max(0, Math.round(meta.total_std != null ? meta.total_predicted - 1.96 * meta.total_std : 0));
    const high   = Math.round(meta.total_std != null ? (meta.total_predicted || 0) + 1.96 * meta.total_std : 0);

    let strategy;
    if (parseFloat(cr) >= 70)      strategy = 'High margin — over-stocking is cheaper than a lost sale. Order aggressively.';
    else if (parseFloat(cr) >= 40) strategy = 'Balanced margin — order near expected demand.';
    else                           strategy = 'Tight margin — cost of unsold stock is high. Order conservatively.';

    const totalPredicted = meta.total_predicted || meta.optimal_total;

    body.innerHTML =
        '<div class="rpt-nv-row"><span class="rpt-nv-label">Price / Cost</span>'
        + '<span class="rpt-nv-val">₱' + price.toFixed(2) + ' selling &nbsp;·&nbsp; ₱' + cost.toFixed(2) + ' cost &nbsp;·&nbsp; ₱' + margin.toFixed(2) + ' margin (' + cr + '%)</span></div>'
        + '<div class="rpt-nv-row"><span class="rpt-nv-label">Critical ratio</span>'
        + '<span class="rpt-nv-val"><strong>' + cr + '%</strong> — ' + strategy + '</span></div>'
        + '<div class="rpt-nv-row"><span class="rpt-nv-label">Under-stock cost</span>'
        + '<span class="rpt-nv-val">₱' + margin.toFixed(2) + ' per unit — profit lost when you run out of stock</span></div>'
        + '<div class="rpt-nv-row"><span class="rpt-nv-label">Over-stock cost</span>'
        + '<span class="rpt-nv-val">₱' + cost.toFixed(2) + ' per unit — money tied up in unsold inventory</span></div>'
        + (meta.total_std != null ? '<div class="rpt-nv-row"><span class="rpt-nv-label">Demand range (95%)</span>'
        + '<span class="rpt-nv-val">' + low + ' – ' + high + ' units &nbsp;·&nbsp; avg ' + Math.round(totalPredicted) + ' units &nbsp;·&nbsp; σ = ' + Math.round(meta.total_std) + ' units</span></div>' : '')
        + '<div class="rpt-nv-row"><span class="rpt-nv-label">Optimal supply</span>'
        + '<span class="rpt-nv-val">' + meta.optimal_total + ' units total &nbsp;·&nbsp; ' + meta.current_stock + ' on hand + <strong>' + meta.restock_qty + ' to order</strong></span></div>';

    body.style.display = rptNvOpen ? '' : 'none';
    const chevron = document.getElementById('rpt-nv-chevron');
    if (chevron) chevron.style.transform = rptNvOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
}

function toggleRptNvSection() {
    rptNvOpen = !rptNvOpen;
    const body    = document.getElementById('rpt-nv-body');
    const chevron = document.getElementById('rpt-nv-chevron');
    if (body)    body.style.display      = rptNvOpen ? '' : 'none';
    if (chevron) chevron.style.transform = rptNvOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
}

// ── Delete ────────────────────────────────────────────────────────────────────
function confirmDeleteSession(btn) {
    const productName = btn.dataset.productName;
    const productId   = btn.dataset.productId;
    const generatedAt = btn.dataset.generatedAt;

    showConfirm({
        title:        'Delete this demand plan?',
        message:      'The demand plan for "' + productName + '" will be permanently removed.',
        confirmText:  'Delete',
        confirmStyle: 'danger',
        onConfirm:    function () { deleteSession(btn, productId, generatedAt); },
    });
}

function deleteSession(btn, productId, generatedAt) {
    const body = new FormData();
    body.append('product_id',   productId);
    body.append('generated_at', generatedAt);

    fetch('<?php echo BASE_URL; ?>/api/delete_forecast.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { alert(data.error); return; }
            // Remove the row from the DOM without a full page reload
            const row = btn.closest('.session-row');
            if (row) row.remove();
            // If no rows remain, show the empty state
            const list = document.querySelector('.session-list');
            if (list && list.querySelectorAll('.session-row').length === 0) {
                list.innerHTML = '<div class="session-empty">'
                    + '<p class="session-empty-title">No saved forecasts yet</p>'
                    + '<p class="session-empty-sub">Go to the Forecast page, select a product, and run a forecast.</p>'
                    + '</div>';
            }
        })
        .catch(function () { alert('Network error. Could not delete.'); });
}

// Close modal on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('rpt-modal');
        if (modal && !modal.classList.contains('hidden')) closeDetailModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>
