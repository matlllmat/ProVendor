<?php
// pages/forecast.view.php
// Presentation only — demand chart + product list.

require_once __DIR__ . '/forecast.logic.php';

$pageTitle = 'ProVendor — Forecast';
$pageCss   = 'forecast.css';
$extraCss  = 'chart_modal.css';
require_once __DIR__ . '/../includes/header.php';
?>

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

        <!-- Filters row: categories left, view switcher right -->
        <div class="chart-filters-row">
            <div class="category-tabs">
                <button class="category-tab active" data-category="">All</button>
                <?php foreach ($categories as $cat): ?>
                <button class="category-tab" data-category="<?php echo htmlspecialchars($cat); ?>">
                    <?php echo htmlspecialchars($cat); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="view-tabs">
                <button type="button" class="view-tab active" data-view="daily">Daily</button>
                <button type="button" class="view-tab"        data-view="weekly">Weekly</button>
                <button type="button" class="view-tab"        data-view="monthly">Monthly</button>
                <button type="button" class="view-tab"        data-view="yearly">Yearly</button>
            </div>
        </div>

        <!-- Year pills (left) + Selected product / Run Forecast (right) -->
        <div class="year-product-row">

            <div class="year-selector" id="year-selector"></div>

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

        <!-- ── Product Insights ─────────────────────────────────────────
             Shown only when a single product is selected. Surfaces what
             Prophet will pick up on so the user can sanity-check the
             forecast against the data's behavior.
        ────────────────────────────────────────────────────────────── -->
        <div id="product-insights" class="product-insights" style="display:none">
            <div class="insights-header">
                <div class="insights-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>
                        <path d="M22 12A10 10 0 0 0 12 2v10z"/>
                    </svg>
                </div>
                <div class="insights-header-text">
                    <p class="insights-eyebrow">Product Insights</p>
                    <p class="insights-subtitle">What Prophet picks up from this product&rsquo;s history</p>
                </div>
            </div>
            <div class="insights-stats">
                <div class="insight-stat" data-tone="brown">
                    <div class="insight-stat-icon">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 11v6c0 1.66 4 3 9 3s9-1.34 9-3v-6"/></svg>
                    </div>
                    <p class="insight-stat-value" id="ins-records">—</p>
                    <p class="insight-stat-label">sales records</p>
                </div>
                <div class="insight-stat" data-tone="blue">
                    <div class="insight-stat-icon">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <p class="insight-stat-value" id="ins-range">—</p>
                    <p class="insight-stat-label">date span</p>
                </div>
                <div class="insight-stat" data-tone="green">
                    <div class="insight-stat-icon">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </div>
                    <p class="insight-stat-value" id="ins-units">—</p>
                    <p class="insight-stat-label">total units</p>
                </div>
                <div class="insight-stat" data-tone="orange">
                    <div class="insight-stat-icon">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <p class="insight-stat-value" id="ins-avg">—</p>
                    <p class="insight-stat-label">avg per day</p>
                </div>
            </div>
            <ul class="insights-list" id="ins-observations"></ul>
            <p class="insights-quality" id="ins-quality"></p>
        </div>

    </div>

    <!-- ── Product List ───────────────────────────────────────────────────── -->
    <div id="product-section">

        <!-- Batch selection toolbar — hidden by default, shown when batch mode is active -->
        <div id="batch-toolbar" class="batch-toolbar" style="display:none">
            <button type="button" class="batch-cancel-btn" onclick="BatchForecast.exitMode()">✕ Cancel</button>
            <button type="button" class="batch-select-all-btn" onclick="BatchForecast.selectAllVisible()">Select All visible</button>
            <span id="batch-selection-count" class="batch-selection-count">0 selected</span>
            <button type="button" id="batch-next-btn" class="batch-next-btn" onclick="BatchForecast.openFcModal()" disabled>Next →</button>
        </div>

        <form method="GET" action="<?php echo BASE_URL; ?>/pages/forecast.view.php">
            <div class="search-bar">
                <input type="text" name="search" class="search-input"
                    placeholder="Search by name, SKU, or ID…"
                    value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">Search</button>
                <?php if ($search): ?>
                <a href="<?php echo BASE_URL; ?>/pages/forecast.view.php" class="search-clear-btn">Clear</a>
                <?php endif; ?>
                <button type="button" id="batch-trigger-btn" class="batch-trigger-btn"
                        onclick="BatchForecast.enterMode()">Batch Forecast</button>
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
                        data-product-sku="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                        data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
                        data-cost-price="<?php echo $product['cost_price']    !== null ? htmlspecialchars((string) $product['cost_price'])    : ''; ?>"
                        data-selling-price="<?php echo $product['selling_price'] !== null ? htmlspecialchars((string) $product['selling_price']) : ''; ?>">

                    <!-- Checkbox visible only in batch selection mode (toggled via CSS) -->
                    <span class="batch-row-check" id="bchk-<?php echo $product['id']; ?>"></span>

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

<!-- Floating shortcut: jump down to the product list. Visibility is toggled
     by an IntersectionObserver on #product-section so the pill only shows
     when the list is below the viewport. -->
<button type="button" id="fc-jump-list" class="fc-jump-list-btn" onclick="scrollToProductList()" title="Jump to product list" aria-label="Jump to product list">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <polyline points="19 12 12 19 5 12"/>
    </svg>
    <span>Products</span>
</button>


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
let activeProductId    = null;
let activeProductName  = '';
let activeProductSku   = '';    // descriptive name from products.sku, if available
let activeProductCost  = null;  // from products.cost_price (null = not set in DB)
let activeProductPrice = null;  // from products.selling_price
let fullHistorical     = [];
let activeYears        = new Set();
let currentView        = 'daily'; // 'daily' | 'weekly' | 'monthly' | 'yearly'

let fcForecastRows   = [];
let fcOptimizeResult = null;
let fcCurrentStock   = 0;
let fcCostPrice      = 0;
let fcSellingPrice   = 0;
var fcBatchMode      = false;

function loadDisabledEvents() {
    const saved = localStorage.getItem('pv_disabled_events');
    return saved ? new Set(JSON.parse(saved)) : new Set();
}
let disabledEventIds = loadDisabledEvents();

function saveDisabledEvents() {
    localStorage.setItem('pv_disabled_events', JSON.stringify([...disabledEventIds]));
}

// ── Init ──────────────────────────────────────────────────────────────────────
// Scroll to the product list section (search bar + list).
function scrollToProductList() {
    const section = document.getElementById('product-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

document.addEventListener('DOMContentLoaded', function() {
    // Show the floating "Products" pill only while the product list isn't on screen.
    const jumpBtn = document.getElementById('fc-jump-list');
    const section = document.getElementById('product-section');
    if (jumpBtn && section && 'IntersectionObserver' in window) {
        const io = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) {
                jumpBtn.classList.toggle('visible', !e.isIntersecting);
            });
        }, { rootMargin: '0px 0px -20% 0px' }); // hide pill a bit before list fully reaches viewport
        io.observe(section);
    }

    function rowProductArgs(row) {
        return [
            parseInt(row.dataset.productId, 10),
            row.dataset.productName,
            row.dataset.productSku || '',
            row.dataset.costPrice    ? parseFloat(row.dataset.costPrice)    : null,
            row.dataset.sellingPrice ? parseFloat(row.dataset.sellingPrice) : null,
        ];
    }

    document.querySelectorAll('.product-row[data-product-id]').forEach(function(row) {
        row.addEventListener('click', function() {
            const willSelect = parseInt(row.dataset.productId, 10) !== activeProductId;
            selectProduct.apply(null, rowProductArgs(row));
            // Bring the chart into view so the user doesn't have to scroll back up.
            // Only on a fresh selection — clicking an already-selected row is "deselect"
            // and a sudden scroll-up there would feel unrelated to the user's intent.
            if (willSelect) {
                const chartCard = document.querySelector('.chart-card');
                if (chartCard) chartCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    if (INITIAL_PRODUCT_ID !== null) {
        const row = document.querySelector('.product-row[data-product-id="' + INITIAL_PRODUCT_ID + '"]');
        if (row) {
            selectProduct.apply(null, rowProductArgs(row));
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

// ── View tabs (Daily / Weekly / Monthly / Yearly) ─────────────────────────────
document.querySelectorAll('.view-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const view = btn.dataset.view;
        if (view === currentView) return;
        document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentView = view;
        applyViewVisibility();
        if (fullHistorical.length) renderChart(fullHistorical);
    });
});

function applyViewVisibility() {
    // Year pills only make sense when datasets are split by year
    const yearSel = document.getElementById('year-selector');
    if (yearSel) yearSel.style.display = currentView === 'yearly' ? 'none' : '';

    // Events overlay only applies to the daily time-series view
    const eventsGroup = document.querySelector('.event-btn-group');
    if (eventsGroup) eventsGroup.style.display = currentView === 'daily' ? '' : 'none';

    // Reset Zoom is useful in every view — bar charts support zoom now too.
    const zoomBtn = document.querySelector('.fc-zoom-reset-btn');
    if (zoomBtn) zoomBtn.style.display = '';
}

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
function selectProduct(productId, productName, productSku, costPrice, sellingPrice) {
    if (activeProductId === productId) { deselectProduct(); return; }
    activeProductId    = productId;
    activeProductName  = productName;
    activeProductSku   = productSku || '';
    activeProductCost  = (costPrice    != null && !isNaN(costPrice)    && costPrice    > 0) ? costPrice    : null;
    activeProductPrice = (sellingPrice != null && !isNaN(sellingPrice) && sellingPrice > 0) ? sellingPrice : null;
    activeYears        = new Set();
    loadSalesChart(activeCategory, productId);
    updateProductRows();
    updateChartContext();
}

function deselectProduct() {
    activeProductId    = null;
    activeProductName  = '';
    activeProductSku   = '';
    activeProductCost  = null;
    activeProductPrice = null;
    activeYears        = new Set();
    loadSalesChart(activeCategory, null);
    updateProductRows();
    updateChartContext();
}

// Combines name + sku for headers — falls back to just name when sku is missing.
function productDisplayLabel() {
    if (activeProductSku && activeProductSku !== activeProductName) {
        return activeProductName + ' · ' + activeProductSku;
    }
    return activeProductName;
}

// ── Product insights ─────────────────────────────────────────────────────────
// Derives quick stats + behavioral observations from fullHistorical so the user
// can sanity-check the data Prophet will train on. Computed purely client-side
// from the rows already fetched — no extra API call.
function computeProductInsights(rows) {
    if (!rows || !rows.length) return null;

    const total      = rows.length;
    const totalUnits = rows.reduce(function (s, r) { return s + r.actual; }, 0);
    const avg        = total > 0 ? totalUnits / total : 0;

    // Day-of-week averages (Sun=0..Sat=6)
    const dowSum = new Array(7).fill(0);
    const dowCnt = new Array(7).fill(0);
    rows.forEach(function (r) {
        const dow = new Date(r.date + 'T00:00:00').getDay();
        dowSum[dow] += r.actual;
        dowCnt[dow]++;
    });
    const dowAvg = dowSum.map(function (s, i) { return dowCnt[i] > 0 ? s / dowCnt[i] : null; });

    let bestDow = -1, bestDowAvg = -Infinity;
    let worstDow = -1, worstDowAvg = Infinity;
    dowAvg.forEach(function (a, i) {
        if (a == null) return;
        if (a > bestDowAvg)  { bestDowAvg  = a; bestDow  = i; }
        if (a < worstDowAvg) { worstDowAvg = a; worstDow = i; }
    });
    const DOW_NAMES = ['Sundays','Mondays','Tuesdays','Wednesdays','Thursdays','Fridays','Saturdays'];

    // Month-of-year averages (Jan=0..Dec=11) — only meaningful if data covers a few months
    const moSum = new Array(12).fill(0);
    const moCnt = new Array(12).fill(0);
    rows.forEach(function (r) {
        const m = parseInt(r.date.slice(5, 7), 10) - 1;
        moSum[m] += r.actual;
        moCnt[m]++;
    });
    const activeMonths = moCnt.filter(function (c) { return c > 0; }).length;
    let bestMonth = null, peakMonthPct = null, worstMonth = null, slowMonthPct = null;
    if (activeMonths >= 3) {
        const moAvg = moSum.map(function (s, i) { return moCnt[i] > 0 ? s / moCnt[i] : null; });
        let bm = -1, bv = -Infinity, wm = -1, wv = Infinity;
        moAvg.forEach(function (a, i) {
            if (a == null) return;
            if (a > bv) { bv = a; bm = i; }
            if (a < wv) { wv = a; wm = i; }
        });
        const MO = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        bestMonth     = MO[bm];
        peakMonthPct  = avg > 0 ? Math.round(((bv - avg) / avg) * 100) : 0;
        worstMonth    = MO[wm];
        slowMonthPct  = avg > 0 ? Math.round(((wv - avg) / avg) * 100) : 0;
    }

    // Trend: compare recent half vs earlier half — only with enough data
    let trendPct = null;
    if (total >= 60) {
        const mid = Math.floor(total / 2);
        const firstAvg  = rows.slice(0, mid).reduce(function (s, r) { return s + r.actual; }, 0) / mid;
        const secondAvg = rows.slice(mid).reduce(function (s, r) { return s + r.actual; }, 0) / (total - mid);
        if (firstAvg > 0) trendPct = Math.round(((secondAvg - firstAvg) / firstAvg) * 100);
    }

    return {
        totalRecords: total,
        dateFrom:     rows[0].date,
        dateTo:       rows[rows.length - 1].date,
        totalUnits:   Math.round(totalUnits),
        avgDaily:     avg,
        bestDow:      bestDow >= 0 ? DOW_NAMES[bestDow] : null,
        bestDowPct:   bestDow >= 0 && avg > 0 ? Math.round(((bestDowAvg - avg) / avg) * 100) : 0,
        worstDow:     worstDow >= 0 ? DOW_NAMES[worstDow] : null,
        worstDowPct:  worstDow >= 0 && avg > 0 ? Math.round(((worstDowAvg - avg) / avg) * 100) : 0,
        bestMonth: bestMonth, peakMonthPct: peakMonthPct,
        worstMonth: worstMonth, slowMonthPct: slowMonthPct,
        trendPct: trendPct,
    };
}

function updateProductInsights() {
    const panel = document.getElementById('product-insights');
    if (!panel) return;
    if (!activeProductId || !fullHistorical.length) {
        panel.style.display = 'none';
        return;
    }

    const s = computeProductInsights(fullHistorical);
    if (!s) { panel.style.display = 'none'; return; }

    function shortDate(d) {
        return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
    }
    function fmtPct(p) { return (p > 0 ? '+' : '') + p + '%'; }

    document.getElementById('ins-records').textContent = s.totalRecords.toLocaleString();
    document.getElementById('ins-range').textContent   = shortDate(s.dateFrom) + ' → ' + shortDate(s.dateTo);
    document.getElementById('ins-units').textContent   = s.totalUnits.toLocaleString();
    document.getElementById('ins-avg').textContent     = s.avgDaily.toFixed(1) + ' / day';

    function bullet(tone, emoji) {
        return '<span class="insight-bullet insight-bullet-' + tone + '">' + emoji + '</span>';
    }

    const obs = document.getElementById('ins-observations');
    let html  = '';

    if (s.bestDow && Math.abs(s.bestDowPct) >= 5) {
        html += '<li>' + bullet('good', '📅') + '<span>Strongest day: <strong>' + s.bestDow + '</strong> &mdash; ' + fmtPct(s.bestDowPct) + ' vs average</span></li>';
    }
    if (s.worstDow && s.worstDow !== s.bestDow && Math.abs(s.worstDowPct) >= 5) {
        html += '<li>' + bullet('flat', '😴') + '<span>Slowest day: <strong>' + s.worstDow + '</strong> &mdash; ' + fmtPct(s.worstDowPct) + ' vs average</span></li>';
    }
    if (s.bestMonth) {
        html += '<li>' + bullet('good', '📈') + '<span>Peak month: <strong>' + s.bestMonth + '</strong> &mdash; ' + fmtPct(s.peakMonthPct) + ' vs average</span></li>';
    }
    if (s.worstMonth && s.worstMonth !== s.bestMonth) {
        html += '<li>' + bullet('flat', '📉') + '<span>Slow month: <strong>' + s.worstMonth + '</strong> &mdash; ' + fmtPct(s.slowMonthPct) + ' vs average</span></li>';
    }
    if (s.trendPct !== null) {
        if (s.trendPct >= 5) {
            html += '<li>' + bullet('good', '⚡') + '<span>Sales <strong>growing ' + s.trendPct + '%</strong> &mdash; recent half vs earlier half</span></li>';
        } else if (s.trendPct <= -5) {
            html += '<li>' + bullet('bad', '⚡') + '<span>Sales <strong>declining ' + Math.abs(s.trendPct) + '%</strong> &mdash; recent half vs earlier half</span></li>';
        } else {
            html += '<li>' + bullet('blue', '📊') + '<span>Sales are <strong>steady</strong> &mdash; no significant trend</span></li>';
        }
    }

    if (!html) html = '<li class="insights-empty">Not enough data yet to spot a pattern.</li>';
    obs.innerHTML = html;

    // Data-quality footnote so the user sees what Prophet can / can't capture yet.
    const q = document.getElementById('ins-quality');
    if (s.totalRecords < 60) {
        q.textContent = 'Limited history (' + s.totalRecords + ' days). Prophet works best with at least 2 months of data — early forecasts will be rough.';
        q.className   = 'insights-quality insights-quality-warn';
    } else if (s.totalRecords < 365) {
        q.textContent = 'Less than 1 year of data — yearly seasonality patterns are not fully captured yet.';
        q.className   = 'insights-quality insights-quality-warn';
    } else {
        q.textContent = 'Strong dataset — full weekly + yearly patterns are captured.';
        q.className   = 'insights-quality insights-quality-good';
    }

    panel.style.display = '';
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
        nameEl.textContent      = productDisplayLabel();
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
            renderChart(fullHistorical);
            updateProductInsights();
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
        // Split button: left = toggle events on/off, right = open filter checklist
        const evtGroup = document.createElement('div');
        evtGroup.className = 'event-btn-group';

        const eventsBtn = document.createElement('button');
        eventsBtn.id        = 'demand-events-btn';
        eventsBtn.className = 'fc-events-btn';
        eventsBtn.style.cssText = 'border-radius:999px 0 0 999px;border-right:none';
        eventsBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Events';
        eventsBtn.addEventListener('click', toggleDemandEvents);

        const filterTrigger = document.createElement('button');
        filterTrigger.className = 'event-filter-trigger';
        filterTrigger.title     = 'Filter events';
        filterTrigger.innerHTML = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
        filterTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            const opened = toggleEventsChecklist(filterTrigger, disabledEventIds, function() {
                saveDisabledEvents();
                if (demandChart && demandChart.scales.x) {
                    demandChart.options.plugins.annotation.annotations = demandHighlight
                        ? buildChartAnnotations(tsToDateStr(demandChart.scales.x.min), tsToDateStr(demandChart.scales.x.max), true, disabledEventIds)
                        : {};
                    demandChart.update('none');
                }
            });
            if (opened && !demandHighlight) toggleDemandEvents();
        });

        evtGroup.appendChild(eventsBtn);
        evtGroup.appendChild(filterTrigger);
        btnsRow.appendChild(evtGroup);
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

// ── Chart rendering: dispatcher + per-view renderers ──────────────────────────
const AXIS_COLOR = 'rgba(38,31,14,0.45)';
const GRID_COLOR = 'rgba(38,31,14,0.06)';
const AXIS_FONT  = { family: 'Lora', size: 11 };
const TOOLTIP_BG = { backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10 };

function renderChart(historical) {
    if (!historical.length) { showChartState('error', 'No sales data for this selection.'); return; }
    showChartState('chart');
    if (demandChart) { demandChart.destroy(); demandChart = null; }
    applyViewVisibility();
    switch (currentView) {
        case 'weekly':  renderWeeklyView(historical);  break;
        case 'monthly': renderMonthlyView(historical); break;
        case 'yearly':  renderYearlyView(historical);  break;
        default:        renderDailyView(historical);   break;
    }
}

// ── Daily view: line chart with year overlay (normalized to base year 2000) ───
function renderDailyView(historical) {
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

    demandChart = new Chart(document.getElementById('demand-chart'), {
        type: 'line',
        data: { datasets: datasets },
        options: {
            responsive: true,
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x', onZoomComplete: updateDemandAnnotationsOnZoom },
                    pan:  { enabled: true, mode: 'x', onPanComplete: updateDemandAnnotationsOnZoom },
                    limits: { x: { min: minTs, max: maxTs } },
                },
                tooltip: {
                    enabled: false,
                    external: function(ctx) { return externalChartTooltip(ctx, { disabledIds: disabledEventIds }); },
                },
                annotation: { annotations: demandHighlight ? buildChartAnnotations(minNorm, maxNorm, true, disabledEventIds) : {} },
            },
            scales: {
                x: {
                    type: 'time', min: minTs, max: maxTs,
                    time: { minUnit: 'day', tooltipFormat: 'MMM d', displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM', year: 'MMM' } },
                    ticks: { color: AXIS_COLOR, font: AXIS_FONT, maxTicksLimit: 10, maxRotation: 0 },
                    grid: { color: GRID_COLOR },
                },
                y: { beginAtZero: true, ticks: { color: AXIS_COLOR, font: AXIS_FONT }, grid: { color: GRID_COLOR } },
            },
        },
    });
}

// ── Weekly view: bar chart, ISO weeks grouped by year ─────────────────────────
function renderWeeklyView(historical) {
    function weekOfYear(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const target = new Date(d.valueOf());
        const dayNr = (d.getDay() + 6) % 7;
        target.setDate(target.getDate() - dayNr + 3);
        const firstThu = target.valueOf();
        target.setMonth(0, 1);
        if (target.getDay() !== 4) target.setMonth(0, 1 + ((4 - target.getDay()) + 7) % 7);
        return 1 + Math.ceil((firstThu - target) / 604800000);
    }

    const byYear = {};
    let maxWeek  = 52;
    historical.forEach(function(r) {
        const year = r.date.slice(0, 4);
        const wk   = weekOfYear(r.date);
        if (wk > maxWeek) maxWeek = wk;
        if (!byYear[year]) byYear[year] = {};
        byYear[year][wk] = (byYear[year][wk] || 0) + r.actual;
    });

    const labels    = Array.from({ length: maxWeek }, (_, i) => 'W' + (i + 1));
    const years     = Object.keys(byYear).sort();
    const allActive = activeYears.size === 0;

    // Use null for weeks a year never reported in — no bar, no tooltip line.
    const datasets = years.map(function(year, i) {
        const color = YEAR_COLORS[i % YEAR_COLORS.length];
        const data  = Array.from({ length: maxWeek }, function(_, w) {
            const v = byYear[year][w + 1];
            return v !== undefined ? v : null;
        });
        return {
            label: year, data: data, hidden: !(allActive || activeYears.has(year)),
            backgroundColor: hexToRgba(color, 0.75), borderColor: color, borderWidth: 1,
            borderRadius: 2, categoryPercentage: 0.85, barPercentage: 0.92,
        };
    });

    demandChart = new Chart(document.getElementById('demand-chart'), {
        type: 'bar',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
                    pan:  { enabled: true, mode: 'x' },
                },
                tooltip: {
                    enabled: false,
                    external: function(ctx) { return externalChartTooltip(ctx, {}); },
                },
            },
            scales: {
                x: { ticks: { color: AXIS_COLOR, font: AXIS_FONT, autoSkip: true, maxTicksLimit: 13, maxRotation: 0 }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: AXIS_COLOR, font: AXIS_FONT }, grid: { color: GRID_COLOR } },
            },
        },
    });
}

// ── Monthly view: bar chart, Jan–Dec grouped by year ──────────────────────────
function renderMonthlyView(historical) {
    const MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    // null-fill so months a year never reported in don't render a bar or appear on hover.
    const byYear = {};
    historical.forEach(function(r) {
        const year = r.date.slice(0, 4);
        const m    = parseInt(r.date.slice(5, 7), 10) - 1;
        if (!byYear[year]) byYear[year] = new Array(12).fill(null);
        byYear[year][m] = (byYear[year][m] || 0) + r.actual;
    });

    const years     = Object.keys(byYear).sort();
    const allActive = activeYears.size === 0;

    const datasets = years.map(function(year, i) {
        const color = YEAR_COLORS[i % YEAR_COLORS.length];
        return {
            label: year, data: byYear[year], hidden: !(allActive || activeYears.has(year)),
            backgroundColor: hexToRgba(color, 0.75), borderColor: color, borderWidth: 1,
            borderRadius: 4, categoryPercentage: 0.8, barPercentage: 0.9,
        };
    });

    demandChart = new Chart(document.getElementById('demand-chart'), {
        type: 'bar',
        data: { labels: MONTH_LABELS, datasets: datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
                    pan:  { enabled: true, mode: 'x' },
                },
                tooltip: {
                    enabled: false,
                    external: function(ctx) { return externalChartTooltip(ctx, {}); },
                },
            },
            scales: {
                x: { ticks: { color: AXIS_COLOR, font: AXIS_FONT }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: AXIS_COLOR, font: AXIS_FONT }, grid: { color: GRID_COLOR } },
            },
        },
    });
}

// ── Yearly view: single-series bar chart, one bar per year ────────────────────
function renderYearlyView(historical) {
    const totals = {};
    historical.forEach(function(r) {
        const year = r.date.slice(0, 4);
        totals[year] = (totals[year] || 0) + r.actual;
    });
    const years  = Object.keys(totals).sort();
    const colors = years.map((_, i) => YEAR_COLORS[i % YEAR_COLORS.length]);

    demandChart = new Chart(document.getElementById('demand-chart'), {
        type: 'bar',
        data: {
            labels: years,
            datasets: [{
                label: 'Total Units',
                data: years.map(y => totals[y]),
                backgroundColor: colors.map(c => hexToRgba(c, 0.75)),
                borderColor: colors, borderWidth: 1, borderRadius: 6,
                categoryPercentage: 0.7, barPercentage: 0.85,
            }],
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
                    pan:  { enabled: true, mode: 'x' },
                },
                tooltip: {
                    enabled: false,
                    external: function(ctx) { return externalChartTooltip(ctx, {}); },
                },
            },
            scales: {
                x: { ticks: { color: AXIS_COLOR, font: { family: 'Lora', size: 12, weight: '600' } }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { color: AXIS_COLOR, font: AXIS_FONT }, grid: { color: GRID_COLOR } },
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

// ── Per-product input persistence (session-scoped) ───────────────────────────
// Survives modal close and page navigation within a login session.
// Cleared on logout by pvLogout() in csrf.js.
const PI_KEY = 'provendor_pi_v1';
function piGet(pid) {
    if (!pid) return {};
    try {
        const all = JSON.parse(sessionStorage.getItem(PI_KEY) || '{}');
        return all[pid] || {};
    } catch (e) { return {}; }
}
function piSet(pid, partial) {
    if (!pid) return;
    try {
        const all = JSON.parse(sessionStorage.getItem(PI_KEY) || '{}');
        all[pid] = Object.assign(all[pid] || {}, partial);
        sessionStorage.setItem(PI_KEY, JSON.stringify(all));
    } catch (e) {}
}

function openForecastModal() {
    if (!activeProductId && !fcBatchMode) return;

    if (fcBatchMode) {
        var n = BatchForecast.selectedCount();
        document.getElementById('fc-modal-title').textContent =
            'Batch Forecast — ' + n + ' product' + (n === 1 ? '' : 's');
    } else {
        document.getElementById('fc-modal-title').textContent = productDisplayLabel();
    }

    // Reset the reference toggle to "today" on every open.
    forecastReferenceDate = 'today';
    document.querySelectorAll('#fc-ref-pills .fc-preset-pill').forEach(function(b) {
        b.classList.toggle('fc-preset-pill-on', b.dataset.ref === 'today');
    });

    // Default to "Next Month".
    // applyForecastPreset handles batch mode by substituting yesterday as lastDate.
    applyForecastPreset('month');

    if (!fcBatchMode) {
        // Capture any saved dates BEFORE applying the preset — applyForecastPreset
        // writes its own values to storage, which would otherwise clobber the
        // user's previously typed dates.
        const saved = piGet(activeProductId);

        // If the user had previously typed dates for this product, override the
        // preset defaults and re-persist (the preset call just overwrote storage).
        if (saved.fromDate || saved.toDate) {
            const fromEl = document.getElementById('fc-from-date');
            const toEl   = document.getElementById('fc-to-date');
            if (saved.fromDate && fromEl) fromEl.value = saved.fromDate;
            if (saved.toDate   && toEl)   toEl.value   = saved.toDate;
            piSet(activeProductId, {
                fromDate: saved.fromDate || (fromEl ? fromEl.value : ''),
                toDate:   saved.toDate   || (toEl   ? toEl.value   : ''),
            });
            // Clear preset pill — values are user-chosen, not preset-derived.
            document.querySelectorAll('#fc-preset-pills .fc-preset-pill').forEach(function(b) {
                b.classList.remove('fc-preset-pill-on');
            });
        }
    }

    document.getElementById('fc-range-warning').style.display = 'none';
    document.getElementById('fc-input-error').style.display   = 'none';

    fcForecastRows   = [];
    fcOptimizeResult = null;
    setFcPanel('input');
    document.getElementById('fc-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    updateFcRangeWarning();
}

// ── Forecast range presets ───────────────────────────────────────────────────
// Computes From/To dates relative to a chosen reference date (today by default,
// or the last sale date if the user toggles it), sets the inputs, and
// highlights the active pill. Manual date edits clear the pill state (see init).
let forecastReferenceDate = 'today'; // 'today' | 'last'

function applyForecastPreset(preset) {
    const lastDate = fullHistorical.length
        ? fullHistorical[fullHistorical.length - 1].date
        : (fcBatchMode ? new Date(Date.now() - 86400000).toISOString().slice(0, 10) : null);
    if (!lastDate) return;

    // Format using LOCAL components — toISOString() converts to UTC, which in
    // +UTC timezones rolls "Feb 1 00:00 local" back to "Jan 31 16:00 UTC" and
    // strips the day. This bit us as a 1-day-off default before.
    function toYMD(d) {
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    const lastDt  = new Date(lastDate + 'T00:00:00');
    const todayDt = new Date();
    todayDt.setHours(0, 0, 0, 0);

    // The pivot all preset math is computed against
    const refDt = (forecastReferenceDate === 'today') ? todayDt : lastDt;

    let fromDt, toDt;

    // Every preset snaps to a calendar boundary — never a rolling window.
    if (preset === 'week') {
        // Next calendar week: Monday → Sunday
        fromDt = new Date(refDt);
        const dow = fromDt.getDay();                 // 0=Sun, 1=Mon, ..., 6=Sat
        const daysToNextMon = (8 - dow) % 7 || 7;    // always advance to the NEXT Monday
        fromDt.setDate(fromDt.getDate() + daysToNextMon);
        toDt = new Date(fromDt); toDt.setDate(toDt.getDate() + 6);
    }
    else if (preset === 'month') {
        // 1st → last day of next calendar month
        fromDt = new Date(refDt.getFullYear(), refDt.getMonth() + 1, 1);
        toDt   = new Date(fromDt.getFullYear(), fromDt.getMonth() + 1, 0);
    }
    else if (preset === '3months') {
        // Next calendar quarter (Q1 = Jan–Mar, Q2 = Apr–Jun, Q3 = Jul–Sep, Q4 = Oct–Dec)
        const q                = Math.floor(refDt.getMonth() / 3);  // current quarter index (0..3)
        const nextQStartMonth  = (q + 1) * 3;                       // 3, 6, 9, or 12 (auto-wraps to next Jan)
        fromDt = new Date(refDt.getFullYear(), nextQStartMonth, 1);
        toDt   = new Date(fromDt.getFullYear(), fromDt.getMonth() + 3, 0);
    }
    else if (preset === '6months') {
        // Next calendar half-year (H1 = Jan–Jun, H2 = Jul–Dec)
        const nextHStartMonth = refDt.getMonth() < 6 ? 6 : 12;      // Jul of same year, or Jan of next year
        fromDt = new Date(refDt.getFullYear(), nextHStartMonth, 1);
        toDt   = new Date(fromDt.getFullYear(), fromDt.getMonth() + 6, 0);
    }
    else if (preset === 'year') {
        // The literal next calendar year: Jan 1 → Dec 31
        fromDt = new Date(refDt.getFullYear() + 1, 0, 1);
        toDt   = new Date(refDt.getFullYear() + 1, 11, 31);
    }

    // Date inputs can never start before the day after the last sale —
    // Prophet only projects forward from historical data. If the reference
    // is "today" but today is earlier than (or on) the last sale date,
    // we clamp From so the user can't accidentally pick an invalid window.
    const minDt = new Date(lastDt); minDt.setDate(minDt.getDate() + 1);
    if (fromDt < minDt) fromDt = new Date(minDt);
    if (toDt   < fromDt) toDt  = new Date(fromDt);

    const minStr  = toYMD(minDt);
    const fromStr = toYMD(fromDt);
    const toStr   = toYMD(toDt);

    const fromEl = document.getElementById('fc-from-date');
    const toEl   = document.getElementById('fc-to-date');
    if (fromEl) { fromEl.min = minStr; fromEl.value = fromStr; }
    if (toEl)   { toEl.min   = minStr; toEl.value   = toStr;   }

    document.querySelectorAll('#fc-preset-pills .fc-preset-pill').forEach(function(b) {
        b.classList.toggle('fc-preset-pill-on', b.dataset.preset === preset);
    });

    // Programmatic value changes don't fire the input event, so persist explicitly.
    piSet(activeProductId, { fromDate: fromStr, toDate: toStr });

    updateFcRangeWarning();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#fc-preset-pills .fc-preset-pill').forEach(function(btn) {
        btn.addEventListener('click', function() { applyForecastPreset(btn.dataset.preset); });
    });

    // Reference-date toggle: switch the pivot between today and last-sale, then
    // re-apply whichever preset is currently active so the dates refresh.
    document.querySelectorAll('#fc-ref-pills .fc-preset-pill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            forecastReferenceDate = btn.dataset.ref;
            document.querySelectorAll('#fc-ref-pills .fc-preset-pill').forEach(function(b) {
                b.classList.toggle('fc-preset-pill-on', b.dataset.ref === forecastReferenceDate);
            });
            var activePreset = document.querySelector('#fc-preset-pills .fc-preset-pill.fc-preset-pill-on');
            if (activePreset) applyForecastPreset(activePreset.dataset.preset);
        });
    });

    // Manual date edits exit preset mode (no pill highlighted) and persist.
    ['fc-from-date', 'fc-to-date'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function() {
                document.querySelectorAll('#fc-preset-pills .fc-preset-pill').forEach(function(b) {
                    b.classList.remove('fc-preset-pill-on');
                });
                piSet(activeProductId, id === 'fc-from-date' ? { fromDate: el.value } : { toDate: el.value });
            });
        }
    });
});

function closeForecastModal() {
    fcBatchMode = false;
    document.getElementById('fc-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function setFcPanel(panel) {
    document.getElementById('fc-input-panel').style.display   = panel === 'input'   ? '' : 'none';
    document.getElementById('fc-loading-panel').style.display = panel === 'loading' ? '' : 'none';
}

// ── Run forecast (Prophet only — Newsvendor is opt-in from the result modal) ──
function runForecast() {
    const fromDate = document.getElementById('fc-from-date').value;
    const toDate   = document.getElementById('fc-to-date').value;
    const errEl    = document.getElementById('fc-input-error');

    if (!fromDate || !toDate) { errEl.textContent = 'Please select both a start and end date.'; errEl.style.display = ''; return; }
    if (fromDate >= toDate)   { errEl.textContent = 'End date must be after start date.';       errEl.style.display = ''; return; }

    if (!fcBatchMode) {
        const lastDate = fullHistorical.length ? fullHistorical[fullHistorical.length - 1].date : null;
        if (lastDate && fromDate <= lastDate) { errEl.textContent = 'Start date must be after your last sale date (' + lastDate + ').'; errEl.style.display = ''; return; }
    }

    errEl.style.display = 'none';
    setFcPanel('loading');

    // In batch mode, delegate to the batch Prophet runner and bail out.
    if (fcBatchMode) {
        BatchForecast.runProphet(fromDate, toDate);
        return;
    }

    const forecastBody = new FormData();
    forecastBody.append('product_id', activeProductId);
    forecastBody.append('from_date',  fromDate);
    forecastBody.append('to_date',    toDate);

    fetch('<?php echo BASE_URL; ?>/api/run_product_forecast.php', { method: 'POST', body: forecastBody })
        .then(r => r.json())
        .then(function (data) {
            if (data.error) { showFcInputError(data.error); return; }
            fcForecastRows = data.forecast;

            // Close input modal, open ChartModal with forecast only.
            // Newsvendor / restock insight is opt-in from inside the result modal.
            closeForecastModal();
            ChartModal.open({
                label:               'Demand Forecast',
                title:               productDisplayLabel(),
                productId:           activeProductId,      // keys per-product input persistence
                accuracyBase:        '<?php echo BASE_URL; ?>', // for /api/run_product_accuracy.php
                historical:          data.historical,
                forecast:            data.forecast,
                hasBand:             true,
                productCostPrice:    activeProductCost,    // null if not in DB
                productSellingPrice: activeProductPrice,   // null if not in DB
                disabledEventIds:    disabledEventIds,
                onRunAgain: function () {
                    ChartModal.close();
                    openForecastModal();
                },
                onGenerateRestock: function (inputs, done) {
                    // Called by chart_modal.js when user submits the restock form.
                    // inputs = { cost_price, selling_price, current_stock }
                    // done   = callback(opt | { error })
                    fcCurrentStock = inputs.current_stock;
                    fcCostPrice    = inputs.cost_price;
                    fcSellingPrice = inputs.selling_price;

                    const optBody = new FormData();
                    optBody.append('forecast',      JSON.stringify(data.forecast));
                    optBody.append('cost_price',    inputs.cost_price);
                    optBody.append('selling_price', inputs.selling_price);
                    optBody.append('current_stock', inputs.current_stock);
                    // Passes product_id so run_optimize.php can look up the
                    // cached residual_rho and apply the AR(1) σ correction.
                    optBody.append('product_id',    activeProductId);

                    fetch('<?php echo BASE_URL; ?>/api/run_optimize.php', { method: 'POST', body: optBody })
                        .then(r => r.json())
                        .then(function (opt) {
                            if (opt.error) { done(opt); return; }
                            fcOptimizeResult = opt;
                            done({
                                total_predicted:      opt.total_predicted,
                                restock_qty:          opt.restock_qty,
                                current_stock:        inputs.current_stock,
                                cost_price:           inputs.cost_price,
                                selling_price:        inputs.selling_price,
                                total_std:            opt.total_std,
                                optimal_total:        opt.optimal_total,
                                est_profit:           opt.est_profit,
                                rho_used:             opt.rho_used,
                                std_inflation_factor: opt.std_inflation_factor,
                            });
                        })
                        .catch(function () { done({ error: 'Network error. Please try again.' }); });
                },
                onSave: saveForecast,
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
    // AR(1) bookkeeping so the saved Newsvendor disclosure reproduces correctly
    // when this forecast is reopened from the Reports page.
    if (fcOptimizeResult.rho_used             != null) body.append('rho_used',             fcOptimizeResult.rho_used);
    if (fcOptimizeResult.std_inflation_factor != null) body.append('std_inflation_factor', fcOptimizeResult.std_inflation_factor);

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
                'Forecast saved. <a href="<?php echo BASE_URL; ?>/pages/reports.view.php#forecasts" style="font-weight:600;text-decoration:underline">View in Reports →</a>'
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

        <!-- Input panel — Prophet only needs a date range.
             Cost / selling price / stock are collected later in the result
             modal when the user generates a Newsvendor restock insight. -->
        <div id="fc-input-panel">
            <div class="fc-form">

                <p class="fc-form-helptext">
                    Prophet will fit a model to this product&rsquo;s sales history and project demand
                    for every day in the window below. You can request a restock recommendation after
                    you see the forecast.
                </p>

                <div class="fc-form-group">
                    <label class="fc-form-label">Forecast Date Range</label>

                    <!-- Reference date: presets are computed relative to this pivot. -->
                    <div class="fc-ref-toggle">
                        <span class="fc-ref-label">Compute from</span>
                        <div class="fc-preset-pills" id="fc-ref-pills">
                            <button type="button" class="fc-preset-pill fc-preset-pill-on" data-ref="today">Today&rsquo;s date</button>
                            <button type="button" class="fc-preset-pill"                   data-ref="last">Last sale date</button>
                        </div>
                    </div>

                    <!-- Quick presets: click to auto-fill From/To. Manual edits clear the active pill. -->
                    <div class="fc-preset-pills" id="fc-preset-pills">
                        <button type="button" class="fc-preset-pill"                          data-preset="week">Next Week</button>
                        <button type="button" class="fc-preset-pill fc-preset-pill-on"        data-preset="month">Next Month</button>
                        <button type="button" class="fc-preset-pill"                          data-preset="3months">Next 3 Months</button>
                        <button type="button" class="fc-preset-pill"                          data-preset="6months">Next 6 Months</button>
                        <button type="button" class="fc-preset-pill"                          data-preset="year">Next Year</button>
                    </div>

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

<!-- ════════════════════════════════════════════
     BATCH CHART MODAL
     Product tab strip + ChartModal.renderIn() area + Newsvendor footer.
════════════════════════════════════════════ -->
<div id="batch-chart-modal" class="fixed inset-0 z-[1100] flex items-center justify-center hidden"
     role="dialog" aria-modal="true">

    <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)" onclick="BatchForecast.confirmCloseChartModal()"></div>

    <div class="bcm-card">

        <div class="bcm-header">
            <div style="min-width:0">
                <p class="bcm-eyebrow">Batch Forecast</p>
                <h2 id="bcm-title" class="bcm-title">—</h2>
            </div>
            <button type="button" class="batch-modal-close-btn" onclick="BatchForecast.confirmCloseChartModal()" title="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Product tab strip — one tab per successfully forecast product -->
        <div id="bcm-tabs" class="bcm-tabs"></div>

        <!-- Chart area: ChartModal.renderIn() target -->
        <div id="bcm-chart-area" class="bcm-chart-area"></div>

        <div class="bcm-footer">
            <button type="button" class="batch-ghost-btn" onclick="BatchForecast.confirmCloseChartModal()">Close</button>
            <div style="display:flex;gap:0.6rem;align-items:center">
                <button type="button" class="batch-ghost-btn" id="bcm-save-all-btn"
                        style="display:none" onclick="BatchForecast.openSaveModal()">Save Forecasts</button>
                <button type="button" class="batch-primary-btn" id="bcm-nv-btn"
                        onclick="BatchForecast.openNvModal()">Generate Newsvendor →</button>
            </div>
        </div>

    </div>
</div>

<!-- ════════════════════════════════════════════
     BATCH NEWSVENDOR MODAL
     Fast-generate Newsvendor for all forecast products.
════════════════════════════════════════════ -->
<div id="batch-nv-modal" class="fixed inset-0 z-[1200] flex items-center justify-center hidden"
     role="dialog" aria-modal="true">

    <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)"></div>

    <div class="bnv-card">

        <div class="bnv-header">
            <div style="min-width:0">
                <p class="bnv-eyebrow">Newsvendor — Fast Generate</p>
                <h2 class="bnv-title">Set prices &amp; stock, then generate restock for all</h2>
            </div>
            <button type="button" class="batch-modal-close-btn" onclick="BatchForecast.closeNvModal()" title="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div id="bnv-error" class="batch-run-error" style="display:none"></div>

        <div id="bnv-body" class="bnv-body"></div>

        <div class="bnv-footer">
            <button type="button" class="batch-ghost-btn" onclick="BatchForecast.closeNvModal()">Close</button>
            <button type="button" class="batch-primary-btn" id="bnv-gen-btn"
                    onclick="BatchForecast.confirmRunNewsvendor()">Generate All →</button>
        </div>

    </div>
</div>

<!-- ════════════════════════════════════════════
     BATCH SAVE MODAL — select which forecasts to save
════════════════════════════════════════════ -->
<div id="batch-save-modal" class="fixed inset-0 z-[1300] flex items-center justify-center hidden"
     role="dialog" aria-modal="true">

    <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)" onclick="BatchForecast.closeSaveModal()"></div>

    <div class="bsv-card">

        <div class="bsv-header">
            <div style="min-width:0">
                <p class="bsv-eyebrow">Batch Forecast</p>
                <h2 class="bsv-title">Save Forecasts</h2>
            </div>
            <button type="button" class="batch-modal-close-btn" onclick="BatchForecast.closeSaveModal()" title="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div id="bsv-error" class="batch-run-error" style="display:none"></div>

        <div id="bsv-body" class="bsv-body"></div>

        <div class="bsv-footer">
            <button type="button" class="batch-ghost-btn" onclick="BatchForecast.closeSaveModal()">Cancel</button>
            <button type="button" class="batch-primary-btn" id="bsv-save-btn"
                    onclick="BatchForecast.confirmSaveSelected()">Save Selected →</button>
        </div>

    </div>
</div>

<script>
const BATCH_BASE_URL = '<?php echo BASE_URL; ?>';
</script>
<script src="<?php echo BASE_URL; ?>/pages/js/batch_forecast.js?v=<?php echo filemtime(__DIR__ . '/js/batch_forecast.js'); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

