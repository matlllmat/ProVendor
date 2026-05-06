<?php
// pages/forecast.view.php
// Presentation only — demand chart + product list.

require_once __DIR__ . '/forecast.logic.php';

$pageTitle = 'ProVendor — Forecast';
$pageCss   = 'forecast.css';
$extraCss  = 'chart_modal.css';
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
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Demand Forecast</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Historical sales + demand forecast. Select a product, then run a forecast.
        </p>
    </div>

    <!-- ── Chart Card ─────────────────────────────────────────────────────── -->
    <div class="chart-card">

        <div class="flex items-center justify-between mb-0">
            <p class="chart-title" style="margin-bottom:0">Demand Analysis</p>
            <div id="demand-chart-btns" class="fc-chart-btns"></div>
        </div>

        <!-- Category tabs -->
        <div class="category-tabs" style="margin-top:1rem">
            <button class="category-tab active" data-category="">All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="category-tab" data-category="<?php echo htmlspecialchars($cat); ?>">
                <?php echo htmlspecialchars($cat); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Year overlay selector -->
        <div class="year-selector" id="year-selector"></div>

        <!-- Selected product indicator -->
        <div id="chart-selected-product" class="chart-selected-product" style="display:none">
            <span class="chart-selected-dot"></span>
            <span id="chart-selected-name" class="chart-selected-name"></span>
            <button class="chart-deselect-btn" onclick="deselectProduct()">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
                Deselect
            </button>
            <button class="fc-run-btn" onclick="openForecastModal()">
                Run Forecast →
            </button>
        </div>

        <!-- Chart canvas -->
        <div id="chart-loading" class="chart-status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 style="animation:spin 1s linear infinite">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
            </svg>
            Loading sales data…
        </div>
        <div id="chart-error" class="chart-error" style="display:none"></div>
        <canvas id="demand-chart" style="display:none; max-height:300px;"></canvas>

    </div>

    <!-- ── Product List ───────────────────────────────────────────────────── -->
    <div>

        <form method="GET" action="<?php echo BASE_URL; ?>/pages/forecast.view.php">
            <div class="search-bar">
                <input type="text" name="search" class="search-input"
                    placeholder="Search by name, SKU, or ID…"
                    value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">Search</button>
                <?php if ($search): ?>
                <a href="<?php echo BASE_URL; ?>/pages/forecast.view.php" class="search-clear-btn">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="product-list">
            <?php if (empty($products)): ?>
            <div class="product-empty">No products found.</div>
            <?php else: ?>

                <?php foreach ($products as $product): ?>
                <button class="product-row"
                        data-product-id="<?php echo $product['id']; ?>"
                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                        data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>">

                    <div class="product-row-info">
                        <span class="product-row-name"><?php echo htmlspecialchars($product['name']); ?></span>
                        <div class="product-row-meta">
                            <span class="product-row-id">ID&nbsp;<?php echo $product['id']; ?></span>
                            <?php if ($product['sku']): ?>
                            <span class="product-row-meta-sep">·</span>
                            <span class="product-row-sku"><?php echo htmlspecialchars($product['sku']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="product-row-badges">
                        <?php if ($product['subcategory']): ?>
                        <span class="product-row-subcategory"><?php echo htmlspecialchars($product['subcategory']); ?></span>
                        <?php endif; ?>
                        <?php if ($product['category']): ?>
                        <span class="product-row-category"><?php echo htmlspecialchars($product['category']); ?></span>
                        <?php endif; ?>
                    </div>

                </button>
                <?php endforeach; ?>

                <div id="product-list-empty-filter" class="product-empty" style="display:none">
                    No products in this category.
                </div>

            <?php endif; ?>
        </div>

    </div>

</main>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
<script>
const CHART_EVENTS        = <?php echo json_encode($chartEvents); ?>;
const EVENT_COLOR         = '#FF5722';
const INITIAL_PRODUCT_ID  = <?php echo json_encode($initialProductId); ?>;
const INITIAL_EVENT_ID    = <?php echo json_encode($initialEventId); ?>;
</script>
<script src="<?php echo BASE_URL; ?>/pages/js/chart.shared.js"></script>
<script src="<?php echo BASE_URL; ?>/pages/js/chart_modal.js"></script>
<script>
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);

// ── State ─────────────────────────────────────────────────────────────────────
let demandChart       = null;
let demandHighlight   = false;
let activeCategory    = '';
let activeProductId   = null;
let activeProductName = '';
let fullHistorical    = [];
let activeYears       = new Set();

let fcForecastRows   = [];
let fcOptimizeResult = null;
let fcCurrentStock   = 0;
let fcCostPrice      = 0;
let fcSellingPrice   = 0;

function loadDisabledEvents() {
    const saved = localStorage.getItem('pv_disabled_events');
    return saved ? new Set(JSON.parse(saved)) : new Set();
}
let disabledEventIds = loadDisabledEvents();

function saveDisabledEvents() {
    localStorage.setItem('pv_disabled_events', JSON.stringify([...disabledEventIds]));
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.product-row[data-product-id]').forEach(function(row) {
        row.addEventListener('click', function() {
            selectProduct(parseInt(this.dataset.productId, 10), this.dataset.productName);
        });
    });

    if (INITIAL_PRODUCT_ID !== null) {
        const row = document.querySelector('.product-row[data-product-id="' + INITIAL_PRODUCT_ID + '"]');
        if (row) {
            selectProduct(INITIAL_PRODUCT_ID, row.dataset.productName);
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            loadSalesChart('', null);
        }
    } else {
        loadSalesChart('', null);
    }
});

// ── Category tabs ─────────────────────────────────────────────────────────────
document.querySelectorAll('.category-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.category-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCategory    = btn.dataset.category;
        activeProductId   = null;
        activeProductName = '';
        activeYears       = new Set();
        updateProductRows();
        updateChartContext();
        filterProductList(activeCategory);
        loadSalesChart(activeCategory, null);
    });
});

function filterProductList(category) {
    let visibleCount = 0;
    document.querySelectorAll('.product-row[data-product-id]').forEach(function(row) {
        const matches     = !category || (row.dataset.category || '') === category;
        row.style.display = matches ? '' : 'none';
        if (matches) visibleCount++;
    });
    const emptyEl = document.getElementById('product-list-empty-filter');
    if (emptyEl) emptyEl.style.display = visibleCount === 0 ? '' : 'none';
}

// ── Product selection ─────────────────────────────────────────────────────────
function selectProduct(productId, productName) {
    if (activeProductId === productId) { deselectProduct(); return; }
    activeProductId   = productId;
    activeProductName = productName;
    activeYears       = new Set();
    loadSalesChart(activeCategory, productId);
    updateProductRows();
    updateChartContext();
}

function deselectProduct() {
    activeProductId   = null;
    activeProductName = '';
    activeYears       = new Set();
    loadSalesChart(activeCategory, null);
    updateProductRows();
    updateChartContext();
}

function updateProductRows() {
    document.querySelectorAll('.product-row[data-product-id]').forEach(function(row) {
        row.classList.toggle('active', parseInt(row.dataset.productId, 10) === activeProductId);
    });
}

function updateChartContext() {
    const indicator = document.getElementById('chart-selected-product');
    const nameEl    = document.getElementById('chart-selected-name');
    if (!indicator || !nameEl) return;
    if (activeProductId) {
        nameEl.textContent      = activeProductName;
        indicator.style.display = 'flex';
    } else {
        indicator.style.display = 'none';
    }
}

// ── Load sales data ───────────────────────────────────────────────────────────
function loadSalesChart(category, productId) {
    showChartState('loading');
    const body = new FormData();
    if (productId) body.append('product_id', productId);
    else           body.append('category', category || '');
    fetch('<?php echo BASE_URL; ?>/api/get_sales_chart.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(function(data) {
            if (data.error) { showChartState('error', data.error); return; }
            fullHistorical = data.historical;
            buildYearSelector(fullHistorical);
            renderYearOverlay(fullHistorical);
        })
        .catch(() => showChartState('error', 'Network error. Please refresh.'));
}

function showChartState(state, msg) {
    document.getElementById('chart-loading').style.display = state === 'loading' ? 'flex'  : 'none';
    document.getElementById('chart-error').style.display   = state === 'error'   ? 'flex'  : 'none';
    document.getElementById('demand-chart').style.display  = state === 'chart'   ? 'block' : 'none';
    if (state === 'error') document.getElementById('chart-error').textContent = msg;
}

// ── Year overlay: selector pills + action buttons ─────────────────────────────
function buildYearSelector(historical) {
    const container = document.getElementById('year-selector');
    const btnsRow   = document.getElementById('demand-chart-btns');
    if (!container) return;
    container.innerHTML = '';
    if (btnsRow) btnsRow.innerHTML = '';
    demandHighlight = false;

    const years = [...new Set(historical.map(function(r) { return r.date.slice(0, 4); }))].sort();

    if (years.length > 1) {
        years.forEach(function(year, i) {
            const btn = document.createElement('button');
            btn.className    = 'year-pill';
            btn.textContent  = year;
            btn.dataset.year = year;
            btn.style.setProperty('--yc', YEAR_COLORS[i % YEAR_COLORS.length]);
            btn.addEventListener('click', function() { toggleYear(year); });
            container.appendChild(btn);
        });
        updateYearPills();
    }

    if (btnsRow) {
        const eventsBtn = document.createElement('button');
        eventsBtn.id        = 'demand-events-btn';
        eventsBtn.className = 'fc-events-btn';
        eventsBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Events';
        eventsBtn.addEventListener('click', toggleDemandEvents);
        btnsRow.appendChild(eventsBtn);
        updateDemandEventsBtn();

        const resetBtn = document.createElement('button');
        resetBtn.className = 'fc-zoom-reset-btn';
        resetBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg> Reset Zoom';
        resetBtn.addEventListener('click', function() { if (demandChart) demandChart.resetZoom(); });
        btnsRow.appendChild(resetBtn);
    }
}

function toggleDemandEvents() {
    demandHighlight = !demandHighlight;
    updateDemandEventsBtn();
    if (!demandChart || !demandChart.scales.x) return;
    demandChart.options.plugins.annotation.annotations = demandHighlight
        ? buildChartAnnotations(tsToDateStr(demandChart.scales.x.min), tsToDateStr(demandChart.scales.x.max), true, disabledEventIds)
        : {};
    demandChart.update('none');
}

function updateDemandEventsBtn() {
    const btn = document.getElementById('demand-events-btn');
    if (!btn) return;
    btn.style.background  = demandHighlight ? '#261F0E' : 'transparent';
    btn.style.color       = demandHighlight ? '#F0E8D0' : '#261F0E';
    btn.style.borderColor = demandHighlight ? '#261F0E' : '#D2C8AE';
    btn.style.opacity     = demandHighlight ? '1'       : '0.5';
}

function updateDemandAnnotationsOnZoom({ chart }) {
    if (!demandHighlight || !chart.scales.x) return;
    chart.options.plugins.annotation.annotations = buildChartAnnotations(
        tsToDateStr(chart.scales.x.min), tsToDateStr(chart.scales.x.max), true, disabledEventIds
    );
    chart.update('none');
}

function toggleYear(year) {
    if (activeYears.has(year)) activeYears.delete(year); else activeYears.add(year);
    updateYearPills();
    if (!demandChart) return;
    const allActive = activeYears.size === 0;
    demandChart.data.datasets.forEach(function(ds) {
        ds.hidden = !(allActive || activeYears.has(ds.label));
    });
    demandChart.update();
}

function updateYearPills() {
    const allActive = activeYears.size === 0;
    document.querySelectorAll('.year-pill[data-year]').forEach(function(btn) {
        const selected = activeYears.has(btn.dataset.year);
        btn.classList.toggle('year-pill-active', allActive || selected);
        btn.classList.toggle('year-pill-muted',  !allActive && !selected);
    });
}

// ── Year overlay: render ──────────────────────────────────────────────────────
function renderYearOverlay(historical) {
    if (!historical.length) { showChartState('error', 'No sales data for this selection.'); return; }
    showChartState('chart');

    const byYear    = groupByYearNorm(historical);
    const years     = Object.keys(byYear).sort();
    const allActive = activeYears.size === 0;

    const datasets = years.map(function(year, i) {
        const color = YEAR_COLORS[i % YEAR_COLORS.length];
        return {
            label: year, data: byYear[year], hidden: !(allActive || activeYears.has(year)),
            borderColor: color, backgroundColor: hexToRgba(color, 0.06),
            borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, pointBackgroundColor: color,
            fill: false, tension: 0.3,
        };
    });

    let minNorm = '2000-12-31', maxNorm = '2000-01-01';
    datasets.forEach(function(ds) {
        ds.data.forEach(function(pt) {
            if (pt.x < minNorm) minNorm = pt.x;
            if (pt.x > maxNorm) maxNorm = pt.x;
        });
    });
    const PAD   = 3 * 86400000;
    const minTs = new Date(minNorm).getTime() - PAD;
    const maxTs = new Date(maxNorm).getTime() + PAD;

    if (demandChart) demandChart.destroy();

    demandChart = new Chart(document.getElementById('demand-chart'), {
        type: 'line',
        data: { datasets: datasets },
        options: {
            responsive: true,
            interaction: { mode: 'x', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x', onZoomComplete: updateDemandAnnotationsOnZoom },
                    pan:  { enabled: true, mode: 'x', onPanComplete: updateDemandAnnotationsOnZoom },
                    limits: { x: { min: minTs, max: maxTs } },
                },
                tooltip: {
                    backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10,
                    callbacks: {
                        title: function(items) {
                            if (!items.length) return '';
                            return new Date(items[0].parsed.x).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
                        },
                        label: function(ctx) {
                            if (ctx.parsed.y === null) return null;
                            return ' ' + ctx.dataset.label + ': ' + Math.round(ctx.parsed.y) + ' units';
                        },
                    },
                },
                annotation: { annotations: {} },
            },
            scales: {
                x: {
                    type: 'time', min: minTs, max: maxTs,
                    time: { minUnit: 'day', tooltipFormat: 'MMM d', displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM', year: 'MMM' } },
                    ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 }, maxTicksLimit: 10, maxRotation: 0 },
                    grid: { color: 'rgba(38,31,14,0.06)' },
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } },
                    grid:  { color: 'rgba(38,31,14,0.06)' },
                },
            },
        },
    });
}

// ════════════════════════════════════════════════════════════════════
//  FORECAST MODAL — input + loading panels only
//  Results are rendered by ChartModal (chart_modal.js)
// ════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('fc-modal');
            if (modal && !modal.classList.contains('hidden')) closeForecastModal();
        }
    });
    const toEl = document.getElementById('fc-to-date');
    if (toEl) toEl.addEventListener('change', updateFcRangeWarning);
});

function updateFcRangeWarning() {
    const toEl     = document.getElementById('fc-to-date');
    const warnEl   = document.getElementById('fc-range-warning');
    const lastDate = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
    if (!warnEl || !toEl || !lastDate || !toEl.value) return;
    const daysOut = (new Date(toEl.value + 'T00:00:00') - new Date(lastDate + 'T00:00:00')) / 86400000;
    warnEl.style.display = daysOut > 365 ? '' : 'none';
}

function openForecastModal() {
    if (!activeProductId) return;
    document.getElementById('fc-modal-title').textContent = activeProductName;

    const lastDate = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
    if (lastDate) {
        const fromDt = new Date(lastDate + 'T00:00:00');
        fromDt.setDate(fromDt.getDate() + 1);
        const toDt   = new Date(fromDt);
        toDt.setDate(toDt.getDate() + 30);
        const fromStr = fromDt.toISOString().slice(0, 10);
        const toStr   = toDt.toISOString().slice(0, 10);
        const fromEl  = document.getElementById('fc-from-date');
        const toEl    = document.getElementById('fc-to-date');
        if (fromEl) { fromEl.min = fromStr; fromEl.value = fromStr; }
        if (toEl)   { toEl.min   = fromStr; toEl.value   = toStr;   }
    }
    document.getElementById('fc-range-warning').style.display = 'none';
    document.getElementById('fc-input-error').style.display   = 'none';

    fcForecastRows   = [];
    fcOptimizeResult = null;
    setFcPanel('input');
    document.getElementById('fc-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeForecastModal() {
    document.getElementById('fc-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function setFcPanel(panel) {
    document.getElementById('fc-input-panel').style.display   = panel === 'input'   ? '' : 'none';
    document.getElementById('fc-loading-panel').style.display = panel === 'loading' ? '' : 'none';
}

// ── Run forecast ──────────────────────────────────────────────────────────────
function runForecast() {
    const cost     = parseFloat(document.getElementById('fc-cost').value)  || 0;
    const price    = parseFloat(document.getElementById('fc-price').value) || 0;
    const stock    = parseInt(document.getElementById('fc-stock').value)   || 0;
    const fromDate = document.getElementById('fc-from-date').value;
    const toDate   = document.getElementById('fc-to-date').value;
    const errEl    = document.getElementById('fc-input-error');

    if (cost <= 0 || price <= 0)   { errEl.textContent = 'Please enter both cost price and selling price.'; errEl.style.display = ''; return; }
    if (price <= cost)             { errEl.textContent = 'Selling price must be greater than cost price.';  errEl.style.display = ''; return; }
    if (!fromDate || !toDate)      { errEl.textContent = 'Please select both a start and end date.';        errEl.style.display = ''; return; }
    if (fromDate >= toDate)        { errEl.textContent = 'End date must be after start date.';              errEl.style.display = ''; return; }
    const lastDate = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
    if (lastDate && fromDate <= lastDate) { errEl.textContent = 'Start date must be after your last sale date (' + lastDate + ').'; errEl.style.display = ''; return; }

    errEl.style.display = 'none';
    fcCurrentStock  = stock;
    fcCostPrice     = cost;
    fcSellingPrice  = price;

    setFcPanel('loading');

    const forecastBody = new FormData();
    forecastBody.append('product_id', activeProductId);
    forecastBody.append('from_date',  fromDate);
    forecastBody.append('to_date',    toDate);

    fetch('<?php echo BASE_URL; ?>/api/run_product_forecast.php', { method: 'POST', body: forecastBody })
        .then(r => r.json())
        .then(function (data) {
            if (data.error) { showFcInputError(data.error); return; }
            fcForecastRows = data.forecast;

            const optBody = new FormData();
            optBody.append('forecast',      JSON.stringify(data.forecast));
            optBody.append('cost_price',    cost);
            optBody.append('selling_price', price);
            optBody.append('current_stock', stock);

            return fetch('<?php echo BASE_URL; ?>/api/run_optimize.php', { method: 'POST', body: optBody })
                .then(r => r.json())
                .then(function (opt) {
                    if (opt.error) { showFcInputError(opt.error); return; }
                    fcOptimizeResult = opt;

                    // Close input modal, open ChartModal with results
                    closeForecastModal();
                    ChartModal.open({
                        label:            'Demand Forecast',
                        title:            activeProductName,
                        historical:       data.historical,
                        forecast:         data.forecast,
                        hasBand:          true,
                        meta: {
                            total_predicted: opt.total_predicted,
                            restock_qty:     opt.restock_qty,
                            current_stock:   fcCurrentStock,
                            cost_price:      fcCostPrice,
                            selling_price:   fcSellingPrice,
                            total_std:       opt.total_std,
                            optimal_total:   opt.optimal_total,
                            est_profit:      opt.est_profit,
                        },
                        disabledEventIds: disabledEventIds,
                        onRunAgain: function () {
                            ChartModal.close();
                            openForecastModal();
                        },
                        onSave: saveForecast,
                    });
                });
        })
        .catch(function () { showFcInputError('Network error. Please try again.'); });
}

function showFcInputError(msg) {
    setFcPanel('input');
    document.getElementById('fc-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    const errEl = document.getElementById('fc-input-error');
    errEl.textContent   = msg;
    errEl.style.display = '';
}

// ── Save forecast ─────────────────────────────────────────────────────────────
function saveForecast() {
    if (!fcForecastRows.length || !fcOptimizeResult) return;

    ChartModal.setSaveBtnState(true, 'Saving…');

    const body = new FormData();
    body.append('product_id',    activeProductId);
    body.append('forecast_data', JSON.stringify(fcForecastRows));
    body.append('restock_qty',   fcOptimizeResult.restock_qty);
    body.append('cost_price',    fcCostPrice);
    body.append('selling_price', fcSellingPrice);
    body.append('current_stock', fcCurrentStock);
    body.append('total_std',     fcOptimizeResult.total_std);
    body.append('optimal_total', fcOptimizeResult.optimal_total);
    body.append('est_profit',    fcOptimizeResult.est_profit);

    fetch('<?php echo BASE_URL; ?>/api/save_forecast.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(function (data) {
            if (data.error) {
                ChartModal.setSaveBtnState(false, 'Save Forecast');
                ChartModal.showSaveMsg('error', data.error);
                return;
            }
            ChartModal.setSaveBtnState(false, 'Saved ✓');
            ChartModal.showSaveMsg('success',
                'Forecast saved. <a href="<?php echo BASE_URL; ?>/pages/reports.view.php" style="font-weight:600;text-decoration:underline">View in Reports →</a>'
            );
        })
        .catch(function () {
            ChartModal.setSaveBtnState(false, 'Save Forecast');
            ChartModal.showSaveMsg('error', 'Network error. Could not save.');
        });
}
</script>

<!-- ════════════════════════════════════════════
     FORECAST INPUT MODAL
════════════════════════════════════════════ -->
<div id="fc-modal" class="fixed inset-0 z-[1000] flex items-center justify-center hidden"
     role="dialog" aria-modal="true" aria-labelledby="fc-modal-title">

    <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)" onclick="closeForecastModal()"></div>

    <div class="fc-modal-card">

        <!-- Header -->
        <div class="fc-modal-header">
            <div style="min-width:0">
                <p class="fc-modal-label">Demand Forecast</p>
                <h2 id="fc-modal-title" class="fc-modal-title">—</h2>
            </div>
            <button class="fc-modal-close" onclick="closeForecastModal()" title="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Input panel -->
        <div id="fc-input-panel">
            <div class="fc-form">

                <div class="fc-form-group">
                    <label class="fc-form-label">Forecast Date Range</label>
                    <div class="fc-form-row">
                        <div class="fc-form-group">
                            <label class="fc-form-label" for="fc-from-date">From</label>
                            <div class="fc-input-wrap">
                                <input type="date" id="fc-from-date" class="fc-input">
                            </div>
                        </div>
                        <div class="fc-form-group">
                            <label class="fc-form-label" for="fc-to-date">To</label>
                            <div class="fc-input-wrap">
                                <input type="date" id="fc-to-date" class="fc-input">
                            </div>
                        </div>
                    </div>
                    <div id="fc-range-warning" class="fc-range-warning" style="display:none">
                        Forecasting this far beyond your last sale date — seasonal patterns will be captured
                        but exact unit numbers are less reliable. Use as directional guidance only.
                    </div>
                </div>

                <div class="fc-form-row">
                    <div class="fc-form-group">
                        <label class="fc-form-label" for="fc-cost">Cost Price</label>
                        <div class="fc-input-wrap">
                            <span class="fc-input-affix">₱</span>
                            <input type="number" id="fc-cost" class="fc-input" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-form-label" for="fc-price">Selling Price</label>
                        <div class="fc-input-wrap">
                            <span class="fc-input-affix">₱</span>
                            <input type="number" id="fc-price" class="fc-input" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                </div>

                <div class="fc-form-group">
                    <label class="fc-form-label" for="fc-stock">Current Stock on Hand</label>
                    <div class="fc-input-wrap" style="max-width:180px">
                        <input type="number" id="fc-stock" class="fc-input" min="0" step="1" placeholder="0" value="0">
                        <span class="fc-input-affix fc-input-affix-right">units</span>
                    </div>
                </div>

                <div id="fc-input-error" class="fc-msg fc-msg-error" style="display:none"></div>

                <button id="fc-run-btn" class="fc-primary-btn" onclick="runForecast()">
                    Run Forecast
                </button>

            </div>
        </div>

        <!-- Loading panel -->
        <div id="fc-loading-panel" style="display:none">
            <div class="fc-loading-state">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" style="animation:spin 1s linear infinite">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                </svg>
                Running Prophet model&hellip;
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>
