<?php
// pages/forecast.view.php
// Presentation only — demand chart + product list.

require_once __DIR__ . '/forecast.logic.php';

$pageTitle = 'ProVendor — Forecast';
$pageCss   = 'forecast.css';
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
        </div>

        <!-- Category tabs — filter both the chart AND the product list below -->
        <div class="category-tabs" style="margin-top:1rem">
            <button class="category-tab active" data-category="">All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="category-tab" data-category="<?php echo htmlspecialchars($cat); ?>">
                <?php echo htmlspecialchars($cat); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Year overlay selector — pills built by buildYearSelector() after data loads -->
        <div class="year-selector" id="year-selector"></div>

        <!-- Selected product indicator — replaces the Chart.js legend.
             Hidden until a product row is clicked. Includes a deselect button. -->
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

        <!-- Text search only — category is filtered by the chart tabs above -->
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

        <!-- Product rows — name stored in data-product-name (not inline onclick)
             to avoid double-quote breakage from json_encode. -->
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
<script>
// Spin animation for the loading icon
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);

// ── State ─────────────────────────────────────────────────────────────────────
let demandChart       = null;
let activeCategory    = '';
let activeProductId   = null;
let activeProductName = '';
let fullHistorical    = [];
let activeYears       = new Set(); // empty = all years active

// Per-event filter: stores IDs of DISABLED events (used by forecast modal annotations).
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
            selectProduct(
                parseInt(this.dataset.productId, 10),
                this.dataset.productName
            );
        });
    });

    if (INITIAL_PRODUCT_ID !== null) {
        const row = document.querySelector(
            '.product-row[data-product-id="' + INITIAL_PRODUCT_ID + '"]'
        );
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

// ── Product list filter (client-side by category) ─────────────────────────────
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
    if (activeProductId === productId) {
        deselectProduct();
        return;
    }
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
        nameEl.textContent     = activeProductName;
        indicator.style.display = 'flex';
    } else {
        indicator.style.display = 'none';
    }
}

// ── Load sales data ───────────────────────────────────────────────────────────
function loadSalesChart(category, productId) {
    showChartState('loading');
    const body = new FormData();
    if (productId) {
        body.append('product_id', productId);
    } else {
        body.append('category', category || '');
    }
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

// ── Year overlay: selector pills ──────────────────────────────────────────────
function buildYearSelector(historical) {
    const container = document.getElementById('year-selector');
    if (!container) return;
    container.innerHTML = '';

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

    const resetBtn = document.createElement('button');
    resetBtn.className   = 'chart-zoom-reset';
    resetBtn.textContent = 'Reset Zoom';
    resetBtn.addEventListener('click', function() { if (demandChart) demandChart.resetZoom(); });
    container.appendChild(resetBtn);
}

function toggleYear(year) {
    if (activeYears.has(year)) {
        activeYears.delete(year);
    } else {
        activeYears.add(year);
    }
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
    if (!historical.length) {
        showChartState('error', 'No sales data for this selection.');
        return;
    }
    showChartState('chart');

    const byYear    = groupByYearNorm(historical);
    const years     = Object.keys(byYear).sort();
    const allActive = activeYears.size === 0;

    const datasets = years.map(function(year, i) {
        const color    = YEAR_COLORS[i % YEAR_COLORS.length];
        const isActive = allActive || activeYears.has(year);
        return {
            label:               year,
            data:                byYear[year],
            hidden:              !isActive,
            borderColor:         color,
            backgroundColor:     hexToRgba(color, 0.06),
            borderWidth:         2,
            pointRadius:         0,
            pointHoverRadius:    4,
            pointBackgroundColor: color,
            fill:                false,
            tension:             0.3,
        };
    });

    // Compute the normalised data extent so zoom/pan can't go outside the data.
    // Add 3 days of padding on each side so the first/last points aren't clipped.
    let minNorm = '2000-12-31', maxNorm = '2000-01-01';
    datasets.forEach(function(ds) {
        ds.data.forEach(function(pt) {
            if (pt.x < minNorm) minNorm = pt.x;
            if (pt.x > maxNorm) maxNorm = pt.x;
        });
    });
    const PAD    = 3 * 86400000;
    const minTs  = new Date(minNorm).getTime() - PAD;
    const maxTs  = new Date(maxNorm).getTime() + PAD;

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
                    zoom: {
                        wheel: { enabled: true },
                        pinch: { enabled: true },
                        mode:  'x',
                    },
                    pan: {
                        enabled: true,
                        mode:    'x',
                    },
                    limits: {
                        x: { min: minTs, max: maxTs },
                    },
                },
                tooltip: {
                    backgroundColor: '#261F0E',
                    titleColor:      '#D2C8AE',
                    bodyColor:       '#F0E8D0',
                    padding:         10,
                    callbacks: {
                        title: function(items) {
                            if (!items.length) return '';
                            const d = new Date(items[0].parsed.x);
                            return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
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
                    type: 'time',
                    time: {
                        minUnit: 'day',
                        tooltipFormat: 'MMM d',
                        displayFormats: {
                            day:   'MMM d',
                            week:  'MMM d',
                            month: 'MMM',
                            year:  'MMM',
                        },
                    },
                    min: minTs,
                    max: maxTs,
                    ticks: {
                        color: 'rgba(38,31,14,0.45)',
                        font: { family: 'Lora', size: 11 },
                        maxTicksLimit: 10,
                        maxRotation: 0,
                    },
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
//  FORECAST MODAL
// ════════════════════════════════════════════════════════════════════

let fcChart            = null;
let fcWeeklyChart      = null;
let fcForecastRows     = [];    // [{date, predicted, lower, upper}]
let fcOptimizeResult   = null;  // {total_predicted, restock_qty, order_qty, est_profit}
let fcSelectedDays     = 30;
let fcCurrentStock     = 0;
let fcCostPrice        = 0;
let fcSellingPrice     = 0;
let fcHighlightEnabled = false; // events overlay on modal chart (off by default)
let fcNvOpen           = true;  // newsvendor section expanded by default
let fcActiveYears      = new Set(); // empty = all years visible
let fcForecastOnly     = false; // when true, only the projected-demand line is shown

// ── Modal keyboard + date-warning wiring ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Close modal on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('fc-modal');
            if (modal && !modal.classList.contains('hidden')) closeForecastModal();
        }
    });

    // Show long-range warning dynamically as the user changes the To date
    const toEl = document.getElementById('fc-to-date');
    if (toEl) {
        toEl.addEventListener('change', updateFcRangeWarning);
    }
});

function updateFcRangeWarning() {
    const toEl      = document.getElementById('fc-to-date');
    const warnEl    = document.getElementById('fc-range-warning');
    const lastDate  = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
    if (!warnEl || !toEl || !lastDate || !toEl.value) return;
    const daysOut = (new Date(toEl.value + 'T00:00:00') - new Date(lastDate + 'T00:00:00')) / 86400000;
    warnEl.style.display = daysOut > 365 ? '' : 'none';
}

// ── Open / close ──────────────────────────────────────────────────────────────
function openForecastModal() {
    if (!activeProductId) return;
    document.getElementById('fc-modal-title').textContent = activeProductName;
    resetForecastModal();

    // Set date range defaults: from = last sale + 1 day, to = last sale + 31 days.
    // min is set so the user cannot pick a date inside the existing data window.
    const lastDate = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
    if (lastDate) {
        const fromDt  = new Date(lastDate + 'T00:00:00');
        fromDt.setDate(fromDt.getDate() + 1);
        const toDt    = new Date(fromDt);
        toDt.setDate(toDt.getDate() + 30);
        const fromStr = fromDt.toISOString().slice(0, 10);
        const toStr   = toDt.toISOString().slice(0, 10);
        const fromEl  = document.getElementById('fc-from-date');
        const toEl    = document.getElementById('fc-to-date');
        if (fromEl) { fromEl.min = fromStr; fromEl.value = fromStr; }
        if (toEl)   { toEl.min   = fromStr; toEl.value   = toStr;   }
    }
    document.getElementById('fc-range-warning').style.display = 'none';

    document.getElementById('fc-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeForecastModal() {
    document.getElementById('fc-modal').classList.add('hidden');
    document.body.style.overflow = '';
    if (fcChart)       { fcChart.destroy();       fcChart       = null; }
    if (fcWeeklyChart) { fcWeeklyChart.destroy(); fcWeeklyChart = null; }
}

// ── Panel state machine ───────────────────────────────────────────────────────
function setFcPanel(panel) {
    document.getElementById('fc-input-panel').style.display   = panel === 'input'   ? '' : 'none';
    document.getElementById('fc-loading-panel').style.display = panel === 'loading' ? '' : 'none';
    document.getElementById('fc-results-panel').style.display = panel === 'results' ? '' : 'none';
}

function resetForecastModal() {
    document.getElementById('fc-input-error').style.display   = 'none';
    document.getElementById('fc-results-error').style.display = 'none';
    document.getElementById('fc-save-success').style.display  = 'none';
    const saveBtn = document.getElementById('fc-save-btn');
    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Forecast'; }
    fcForecastRows   = [];
    fcOptimizeResult = null;
    fcActiveYears    = new Set();
    fcForecastOnly   = false;
    const fcYearSel  = document.getElementById('fc-year-selector');
    if (fcYearSel) fcYearSel.innerHTML = '';
    setFcPanel('input');
}

// ── Run forecast ──────────────────────────────────────────────────────────────
function runForecast() {
    const cost     = parseFloat(document.getElementById('fc-cost').value)      || 0;
    const price    = parseFloat(document.getElementById('fc-price').value)     || 0;
    const stock    = parseInt(document.getElementById('fc-stock').value)       || 0;
    const fromDate = document.getElementById('fc-from-date').value;
    const toDate   = document.getElementById('fc-to-date').value;
    const errEl    = document.getElementById('fc-input-error');

    if (cost <= 0 || price <= 0) {
        errEl.textContent   = 'Please enter both cost price and selling price.';
        errEl.style.display = '';
        return;
    }
    if (price <= cost) {
        errEl.textContent   = 'Selling price must be greater than cost price.';
        errEl.style.display = '';
        return;
    }
    if (!fromDate || !toDate) {
        errEl.textContent   = 'Please select both a start and end date.';
        errEl.style.display = '';
        return;
    }
    if (fromDate >= toDate) {
        errEl.textContent   = 'End date must be after start date.';
        errEl.style.display = '';
        return;
    }
    const lastDate = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
    if (lastDate && fromDate <= lastDate) {
        errEl.textContent   = 'Start date must be after your last sale date (' + lastDate + ').';
        errEl.style.display = '';
        return;
    }

    errEl.style.display = 'none';
    fcCurrentStock  = stock;
    fcCostPrice     = cost;
    fcSellingPrice  = price;

    setFcPanel('loading');

    // Step 1 — get historical + forecast data from Flask via PHP bridge
    const forecastBody = new FormData();
    forecastBody.append('product_id', activeProductId);
    forecastBody.append('from_date',  fromDate);
    forecastBody.append('to_date',    toDate);

    fetch('<?php echo BASE_URL; ?>/api/run_product_forecast.php', { method: 'POST', body: forecastBody })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showFcInputError(data.error); return; }

            fcForecastRows = data.forecast;

            // Step 2 — run Newsvendor optimization
            const optBody = new FormData();
            optBody.append('forecast',      JSON.stringify(data.forecast));
            optBody.append('cost_price',    cost);
            optBody.append('selling_price', price);
            optBody.append('current_stock', stock);

            return fetch('<?php echo BASE_URL; ?>/api/run_optimize.php', { method: 'POST', body: optBody })
                .then(function (r) { return r.json(); })
                .then(function (opt) {
                    if (opt.error) { showFcInputError(opt.error); return; }
                    fcOptimizeResult = opt;
                    renderForecastResults(data.historical, data.forecast, opt);
                });
        })
        .catch(function () { showFcInputError('Network error. Please try again.'); });
}

function showFcInputError(msg) {
    setFcPanel('input');
    const errEl = document.getElementById('fc-input-error');
    errEl.textContent   = msg;
    errEl.style.display = '';
}

// ── Render results ────────────────────────────────────────────────────────────
function renderForecastResults(historical, forecast, opt) {
    setFcPanel('results');

    // Stat cards
    document.getElementById('fc-stat-demand').textContent = Math.round(opt.total_predicted) + ' units';
    document.getElementById('fc-stat-stock').textContent  = fcCurrentStock + ' units';
    document.getElementById('fc-stat-order').textContent  = opt.restock_qty + ' units';
    document.getElementById('fc-stat-profit').textContent = '₱' + opt.est_profit.toLocaleString('en-PH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    });

    // Reset events button appearance
    fcHighlightEnabled = false;
    updateFcEventsBtn();

    // Normalise dates to 2000 base year so all years overlap (same axis as main chart)
    const nd = function(d) { return d ? '2000' + d.slice(4) : null; };

    // Historical: one line per year, normalized
    const byYearFc     = groupByYearNorm(historical);
    const fcYears      = Object.keys(byYearFc).sort();
    const histDatasets = fcYears.map(function(year, i) {
        const color = YEAR_COLORS[i % YEAR_COLORS.length];
        return {
            label:            year,
            data:             byYearFc[year],
            borderColor:      color,
            backgroundColor:  'transparent',
            borderWidth:      1.5,
            pointRadius:      0,
            pointHoverRadius: 3,
            fill:             false,
            tension:          0.3,
        };
    });

    // Forecast datasets — normalized; projected-demand line is thicker and fully opaque
    const fcNormStart = nd(forecast[0].date);
    const datasets = histDatasets.concat([
        {
            label:           '_upper',
            data:            forecast.map(function(r) { return { x: nd(r.date), y: r.upper }; }),
            borderColor:     'transparent',
            backgroundColor: 'rgba(255,87,34,0.13)',
            borderWidth:     0,
            pointRadius:     0,
            fill:            '+1',
            tension:         0.3,
        },
        {
            label:       '_lower',
            data:        forecast.map(function(r) { return { x: nd(r.date), y: r.lower }; }),
            borderColor: 'transparent',
            borderWidth: 0,
            pointRadius: 0,
            fill:        false,
            tension:     0.3,
        },
        {
            label:            'Projected Demand',
            data:             forecast.map(function(r) { return { x: nd(r.date), y: r.predicted }; }),
            borderColor:      '#FF5722',
            borderWidth:      3,
            borderDash:       [6, 3],
            backgroundColor:  'transparent',
            pointRadius:      0,
            pointHoverRadius: 4,
            fill:             false,
            tension:          0.3,
        },
    ]);

    // Zoom limits: normalised data extent + 3-day padding
    let minNormFc = '2000-12-31', maxNormFc = '2000-01-01';
    datasets.forEach(function(ds) {
        if (ds.label === '_upper' || ds.label === '_lower') return;
        ds.data.forEach(function(pt) {
            if (pt.x < minNormFc) minNormFc = pt.x;
            if (pt.x > maxNormFc) maxNormFc = pt.x;
        });
    });
    const PAD_FC  = 3 * 86400000;
    const fcMinTs = new Date(minNormFc).getTime() - PAD_FC;
    const fcMaxTs = new Date(maxNormFc).getTime() + PAD_FC;

    // Initial view: 6 months before the normalised forecast start
    const initD = new Date(fcNormStart);
    initD.setMonth(initD.getMonth() - 6);
    const initialMin = initD.toISOString().slice(0, 10);

    // Annotations use normalised dates so they land correctly on the 2000 axis
    const annotations = Object.assign(
        { forecastStart: buildForecastStartAnnotation(fcNormStart) },
        fcHighlightEnabled ? buildChartAnnotations(tsToDateStr(fcMinTs), tsToDateStr(fcMaxTs), true, disabledEventIds) : {}
    );

    // Rebuild main chart
    if (fcChart) fcChart.destroy();

    fcChart = new Chart(document.getElementById('fc-chart'), {
        type: 'line',
        data: { datasets: datasets },
        options: {
            responsive: true,
            interaction: { mode: 'x', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: {
                        wheel:          { enabled: true },
                        pinch:          { enabled: true },
                        mode:           'x',
                        onZoomComplete: updateFcAnnotationsOnZoom,
                    },
                    pan: {
                        enabled:       true,
                        mode:          'x',
                        onPanComplete: updateFcAnnotationsOnZoom,
                    },
                    limits: {
                        x: { min: fcMinTs, max: fcMaxTs },
                    },
                },
                tooltip: {
                    backgroundColor: '#261F0E',
                    titleColor:      '#D2C8AE',
                    bodyColor:       '#F0E8D0',
                    padding:         10,
                    filter: function(item) {
                        return item.dataset.label !== '_upper' && item.dataset.label !== '_lower';
                    },
                    callbacks: {
                        title: function(items) {
                            if (!items.length) return '';
                            const d = new Date(items[0].parsed.x);
                            return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
                        },
                        label: function(ctx) {
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
                    min:  initialMin,
                    max:  fcMaxTs,
                    time: {
                        minUnit: 'day',
                        tooltipFormat: 'MMM d',
                        displayFormats: {
                            day:   'MMM d',
                            week:  'MMM d',
                            month: 'MMM',
                            year:  'MMM',
                        },
                    },
                    ticks: {
                        color: 'rgba(38,31,14,0.45)',
                        font: { family: 'Lora', size: 11 },
                        maxTicksLimit: 10,
                        maxRotation: 0,
                    },
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

    fcActiveYears = new Set();
    buildFcYearSelector(fcYears);
    renderWeeklyChart(forecast);
    renderNewsvendorExplanation(opt, fcCostPrice, fcSellingPrice, fcCurrentStock);
}

// ── Events toggle on modal chart ──────────────────────────────────────────────
function toggleFcEvents() {
    fcHighlightEnabled = !fcHighlightEnabled;
    updateFcEventsBtn();
    if (!fcChart) return;
    const fcNormStart = '2000' + fcForecastRows[0].date.slice(4);
    const visibleFrom = fcChart.scales.x ? tsToDateStr(fcChart.scales.x.min) : null;
    const visibleTo   = fcChart.scales.x ? tsToDateStr(fcChart.scales.x.max) : null;
    fcChart.options.plugins.annotation.annotations = Object.assign(
        { forecastStart: buildForecastStartAnnotation(fcNormStart) },
        fcHighlightEnabled ? buildChartAnnotations(visibleFrom, visibleTo, true, disabledEventIds) : {}
    );
    fcChart.update('none');
}

function updateFcEventsBtn() {
    const btn = document.getElementById('fc-events-btn');
    if (!btn) return;
    btn.style.background  = fcHighlightEnabled ? '#261F0E'  : 'transparent';
    btn.style.color       = fcHighlightEnabled ? '#F0E8D0'  : '#261F0E';
    btn.style.borderColor = fcHighlightEnabled ? '#261F0E'  : '#D2C8AE';
    btn.style.opacity     = fcHighlightEnabled ? '1'        : '0.5';
}

// Rebuilds event annotations after zoom/pan (compact ↔ full switch).
function updateFcAnnotationsOnZoom({ chart }) {
    if (!fcHighlightEnabled || !chart.scales.x) return;
    const fcNormStart = '2000' + fcForecastRows[0].date.slice(4);
    chart.options.plugins.annotation.annotations = Object.assign(
        { forecastStart: buildForecastStartAnnotation(fcNormStart) },
        buildChartAnnotations(tsToDateStr(chart.scales.x.min), tsToDateStr(chart.scales.x.max), true, disabledEventIds)
    );
    chart.update('none');
}

// ── Forecast modal year filter pills ─────────────────────────────────────────
function buildFcYearSelector(years) {
    const container = document.getElementById('fc-year-selector');
    if (!container || years.length <= 1) return;
    container.innerHTML = '';

    years.forEach(function(year, i) {
        const btn = document.createElement('button');
        btn.className    = 'year-pill';
        btn.textContent  = year;
        btn.dataset.year = year;
        btn.style.setProperty('--yc', YEAR_COLORS[i % YEAR_COLORS.length]);
        btn.addEventListener('click', function() { toggleFcYear(year); });
        container.appendChild(btn);
    });

    updateFcYearPills();
}

function toggleFcYear(year) {
    if (fcActiveYears.has(year)) {
        fcActiveYears.delete(year);
    } else {
        fcActiveYears.add(year);
    }
    updateFcYearPills();
    if (!fcChart) return;
    const allActive = fcActiveYears.size === 0;
    fcChart.data.datasets.forEach(function(ds) {
        if (ds.label === 'Projected Demand' || ds.label === '_upper' || ds.label === '_lower') return;
        ds.hidden = !(allActive || fcActiveYears.has(ds.label));
    });
    fcChart.update();
}

function updateFcYearPills() {
    const allActive = fcActiveYears.size === 0;
    document.querySelectorAll('#fc-year-selector .year-pill[data-year]').forEach(function(btn) {
        const selected = fcActiveYears.has(btn.dataset.year);
        btn.classList.toggle('year-pill-active', allActive || selected);
        btn.classList.toggle('year-pill-muted',  !allActive && !selected);
    });
}

function toggleFcForecastOnly() {
    fcForecastOnly = !fcForecastOnly;
    updateFcForecastOnlyBtn();
    if (!fcChart) return;
    const allActive = fcActiveYears.size === 0;
    fcChart.data.datasets.forEach(function(ds) {
        if (ds.label === 'Projected Demand' || ds.label === '_upper' || ds.label === '_lower') return;
        ds.hidden = fcForecastOnly ? true : !(allActive || fcActiveYears.has(ds.label));
    });
    fcChart.update();
}

function updateFcForecastOnlyBtn() {
    const btn = document.getElementById('fc-forecast-only-btn');
    if (!btn) return;
    btn.style.background  = fcForecastOnly ? '#261F0E' : 'transparent';
    btn.style.color       = fcForecastOnly ? '#F0E8D0' : '#261F0E';
    btn.style.borderColor = fcForecastOnly ? '#261F0E' : '#D2C8AE';
    btn.style.opacity     = fcForecastOnly ? '1'       : '0.45';
}

// ── Weekly forecast bar chart ─────────────────────────────────────────────────
function renderWeeklyChart(forecastRows) {
    // Group predicted demand into Monday-anchored weeks
    const weekMap = {};
    forecastRows.forEach(function (r) {
        const d   = new Date(r.date);
        const dow = d.getDay(); // 0=Sun … 6=Sat
        const monday = new Date(d);
        monday.setDate(d.getDate() - (dow === 0 ? 6 : dow - 1));
        const key = monday.toISOString().slice(0, 10);
        weekMap[key] = (weekMap[key] || 0) + r.predicted;
    });

    const weeks  = Object.keys(weekMap).sort();
    const labels = weeks.map(function (w) {
        const d = new Date(w + 'T00:00:00');
        return d.toLocaleString('default', { month: 'short', day: 'numeric' });
    });
    const values = weeks.map(function (w) { return Math.round(weekMap[w]); });

    if (fcWeeklyChart) fcWeeklyChart.destroy();

    fcWeeklyChart = new Chart(document.getElementById('fc-weekly-chart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Forecast',
                data: values,
                backgroundColor: 'rgba(255,87,34,0.65)',
                borderColor: '#FF5722',
                borderWidth: 1,
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#261F0E',
                    titleColor: '#D2C8AE',
                    bodyColor: '#F0E8D0',
                    padding: 10,
                    callbacks: {
                        title: function (items) { return 'Week of ' + items[0].label; },
                        label: function (ctx) { return ' ' + ctx.parsed.y + ' units predicted'; },
                    },
                },
            },
            scales: {
                x: {
                    ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } },
                    grid:  { display: false },
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

// ── Newsvendor explanation ────────────────────────────────────────────────────
function renderNewsvendorExplanation(opt, cost, price, stock) {
    const body = document.getElementById('fc-nv-body');
    if (!body) return;

    const margin        = price - cost;
    const cr            = (margin / price * 100).toFixed(1);
    const demandLow     = Math.round(opt.total_predicted - 1.96 * opt.total_std);
    const demandHigh    = Math.round(opt.total_predicted + 1.96 * opt.total_std);

    let strategy;
    if (parseFloat(cr) >= 70) {
        strategy = 'High margin — it is cheaper to over-stock than to lose a sale. Order aggressively.';
    } else if (parseFloat(cr) >= 40) {
        strategy = 'Balanced margin — order near the expected demand.';
    } else {
        strategy = 'Tight margin — the cost of unsold stock is high. Order conservatively.';
    }

    body.innerHTML =
        '<div class="fc-nv-row">'
        +   '<span class="fc-nv-label">Price / Cost</span>'
        +   '<span class="fc-nv-val">₱' + price.toFixed(2) + ' selling &nbsp;·&nbsp; ₱' + cost.toFixed(2) + ' cost &nbsp;·&nbsp; ₱' + margin.toFixed(2) + ' margin (' + cr + '%)</span>'
        + '</div>'
        + '<div class="fc-nv-row">'
        +   '<span class="fc-nv-label">Critical ratio</span>'
        +   '<span class="fc-nv-val"><strong>' + cr + '%</strong> — ' + strategy + '</span>'
        + '</div>'
        + '<div class="fc-nv-row">'
        +   '<span class="fc-nv-label">Under-stock cost</span>'
        +   '<span class="fc-nv-val">₱' + margin.toFixed(2) + ' per unit — profit you lose when you run out of stock</span>'
        + '</div>'
        + '<div class="fc-nv-row">'
        +   '<span class="fc-nv-label">Over-stock cost</span>'
        +   '<span class="fc-nv-val">₱' + cost.toFixed(2) + ' per unit — money tied up in unsold inventory</span>'
        + '</div>'
        + '<div class="fc-nv-row">'
        +   '<span class="fc-nv-label">Demand range (95%)</span>'
        +   '<span class="fc-nv-val">' + Math.max(0, demandLow) + ' – ' + demandHigh + ' units &nbsp;·&nbsp; avg ' + Math.round(opt.total_predicted) + ' units &nbsp;·&nbsp; σ = ' + Math.round(opt.total_std) + ' units</span>'
        + '</div>'
        + '<div class="fc-nv-row">'
        +   '<span class="fc-nv-label">Optimal supply</span>'
        +   '<span class="fc-nv-val">' + opt.optimal_total + ' units total &nbsp;·&nbsp; ' + stock + ' on hand + <strong>' + opt.restock_qty + ' to order</strong></span>'
        + '</div>';

    // Keep section open/closed per fcNvOpen state
    body.style.display = fcNvOpen ? '' : 'none';
    const chevron = document.getElementById('fc-nv-chevron');
    if (chevron) chevron.style.transform = fcNvOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
}

function toggleNvSection() {
    fcNvOpen = !fcNvOpen;
    const body    = document.getElementById('fc-nv-body');
    const chevron = document.getElementById('fc-nv-chevron');
    if (body)    body.style.display        = fcNvOpen ? '' : 'none';
    if (chevron) chevron.style.transform   = fcNvOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
}

// ── Save forecast ─────────────────────────────────────────────────────────────
function saveForecast() {
    if (!fcForecastRows.length || !fcOptimizeResult) return;

    const saveBtn          = document.getElementById('fc-save-btn');
    saveBtn.disabled       = true;
    saveBtn.textContent    = 'Saving…';

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
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                const errEl         = document.getElementById('fc-results-error');
                errEl.textContent   = data.error;
                errEl.style.display = '';
                saveBtn.disabled    = false;
                saveBtn.textContent = 'Save Forecast';
                return;
            }
            saveBtn.textContent = 'Saved ✓';
            document.getElementById('fc-save-success').style.display = '';
        })
        .catch(function () {
            const errEl         = document.getElementById('fc-results-error');
            errEl.textContent   = 'Network error. Could not save.';
            errEl.style.display = '';
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save Forecast';
        });
}
</script>

<!-- ════════════════════════════════════════════
     FORECAST MODAL
════════════════════════════════════════════ -->
<div id="fc-modal" class="fixed inset-0 z-[1000] flex items-center justify-center hidden"
     role="dialog" aria-modal="true" aria-labelledby="fc-modal-title">

    <!-- Backdrop -->
    <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)" onclick="closeForecastModal()"></div>

    <!-- Card -->
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

        <!-- ── Input panel ── -->
        <div id="fc-input-panel">
            <div class="fc-form">

                <!-- Forecast date range -->
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

                <!-- Cost and selling price -->
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

                <!-- Current stock -->
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

        <!-- ── Loading panel ── -->
        <div id="fc-loading-panel" style="display:none">
            <div class="fc-loading-state">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" style="animation:spin 1s linear infinite">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                </svg>
                Running Prophet model&hellip;
            </div>
        </div>

        <!-- ── Results panel ── -->
        <div id="fc-results-panel" style="display:none">

            <!-- Chart + controls -->
            <div class="fc-chart-wrap">

                <!-- Legend + interactive controls on one row -->
                <div class="fc-chart-controls">
                    <div class="fc-chart-legend">
                        <span class="fc-legend-item">
                            <svg width="18" height="10" viewBox="0 0 18 10">
                                <line x1="0" y1="5" x2="18" y2="5" stroke="#1A6933" stroke-width="2"/>
                            </svg>
                            Historical
                            <span class="fc-legend-info" data-tip="Actual units sold each day before this forecast — the real demand pattern the model learned from.">ⓘ</span>
                        </span>
                        <span class="fc-legend-item">
                            <svg width="18" height="10" viewBox="0 0 18 10">
                                <line x1="0" y1="5" x2="18" y2="5" stroke="#FF5722" stroke-width="2"
                                      stroke-dasharray="5 3"/>
                            </svg>
                            Projected Demand
                            <span class="fc-legend-info" data-tip="Units the model predicts will be needed each day. This drives the recommended order quantity.">ⓘ</span>
                        </span>
                        <span class="fc-legend-item">
                            <span style="display:inline-block;width:18px;height:10px;border-radius:3px;background:rgba(255,87,34,0.2)"></span>
                            Confidence band
                        </span>
                    </div>
                    <div class="fc-chart-btns">
                        <button id="fc-forecast-only-btn" class="fc-events-btn" onclick="toggleFcForecastOnly()">
                            Forecast Only
                        </button>
                        <button id="fc-events-btn" class="fc-events-btn" onclick="toggleFcEvents()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                            Events
                        </button>
                        <button class="fc-zoom-reset-btn" onclick="if(fcChart) fcChart.resetZoom()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                <path d="M3 3v5h5"/>
                            </svg>
                            Reset Zoom
                        </button>
                    </div>
                </div>

                <!-- Year filter pills — built by buildFcYearSelector() after chart loads -->
                <div id="fc-year-selector" class="fc-year-selector"></div>

                <canvas id="fc-chart" style="max-height:280px"></canvas>
            </div>

            <!-- Stats row -->
            <div class="fc-stats-grid">
                <div class="fc-stat-card">
                    <p class="fc-stat-label">Total Forecast</p>
                    <p id="fc-stat-demand" class="fc-stat-value"></p>
                    <p class="fc-stat-sub">units predicted</p>
                </div>
                <div class="fc-stat-card">
                    <p class="fc-stat-label">Current Stock</p>
                    <p id="fc-stat-stock" class="fc-stat-value"></p>
                    <p class="fc-stat-sub">units on hand</p>
                </div>
                <div class="fc-stat-card">
                    <p class="fc-stat-label">Order Qty</p>
                    <p id="fc-stat-order" class="fc-stat-value fc-stat-accent"></p>
                    <p class="fc-stat-sub">units to order</p>
                </div>
                <div class="fc-stat-card">
                    <p class="fc-stat-label">Est. Profit</p>
                    <p id="fc-stat-profit" class="fc-stat-value fc-stat-green"></p>
                    <p class="fc-stat-sub">at forecast demand</p>
                </div>
            </div>

            <!-- Newsvendor explanation -->
            <div class="fc-nv-section">
                <button class="fc-nv-header" onclick="toggleNvSection()">
                    <span class="fc-nv-title">Newsvendor Model — How this was calculated</span>
                    <svg id="fc-nv-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                         style="transition:transform 0.2s; flex-shrink:0">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div id="fc-nv-body" class="fc-nv-body">
                    <!-- Filled by renderNewsvendorExplanation() -->
                </div>
            </div>

            <!-- Weekly forecast bar chart -->
            <div class="fc-weekly-wrap">
                <p class="fc-section-label">Weekly Demand Forecast</p>
                <canvas id="fc-weekly-chart" style="max-height:170px"></canvas>
            </div>

            <div id="fc-results-error" class="fc-msg fc-msg-error" style="display:none; margin:0 1.5rem 0.75rem;"></div>
            <div id="fc-save-success" class="fc-msg fc-msg-success" style="display:none; margin:0 1.5rem 0.75rem;">
                Forecast saved. <a href="<?php echo BASE_URL; ?>/pages/reports.view.php"
                    style="font-weight:600;text-decoration:underline">View in Reports →</a>
            </div>

            <!-- Action buttons -->
            <div class="fc-action-row">
                <button class="fc-ghost-btn" onclick="resetForecastModal()">← Run Again</button>
                <button id="fc-save-btn" class="fc-primary-btn" onclick="saveForecast()">
                    Save Forecast
                </button>
            </div>

        </div><!-- /fc-results-panel -->

    </div><!-- /fc-modal-card -->
</div><!-- /fc-modal -->

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>