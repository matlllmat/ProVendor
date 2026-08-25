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
            Pick a time frame and ProVendor forecasts demand + a restock quantity for every product automatically.
        </p>
    </div>

    <!-- ── Chart Card ─────────────────────────────────────────────────────── -->
    <div class="chart-card">

        <!-- Card header. Left: the title, with the selected product named right
             beside it so the two read as one heading instead of the chip floating
             on its own line. Right: whichever contextual control the current mode
             owns — the events/zoom buttons in aggregate mode, the per-product
             forecast range in product mode. -->
        <div class="chart-head">
            <div class="chart-head-left">
                <p class="chart-title" style="margin-bottom:0">Demand Analysis</p>

                <div id="chart-selected-product" class="chart-selected-product" style="display:none">
                    <span class="chart-selected-dot"></span>
                    <span id="chart-selected-name" class="chart-selected-name"></span>
                    <button class="chart-deselect-btn" onclick="deselectProduct()" title="Back to all products">
                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                        Deselect
                    </button>
                </div>
            </div>

            <div class="chart-head-right">
                <div id="demand-chart-btns" class="fc-chart-btns"></div>

                <div id="product-range" class="product-range" style="display:none">
                    <span class="product-range-label">Forecast range</span>
                    <div class="product-range-input-wrap">
                        <input type="number" id="product-range-input" class="product-range-input" min="1" max="60">
                        <span class="product-range-affix">days</span>
                    </div>
                    <button type="button" id="product-range-apply" class="product-range-btn" onclick="applyProductRange()">Apply</button>
                    <span id="product-range-note" class="product-range-note"></span>
                </div>

                <!-- Graph ⇆ Calendar. Reuses the List/Cards switch styling from the
                     product list so the two view switches look like one idea. -->
                <div class="view-toggle" role="group" aria-label="Demand view">
                    <button type="button" class="view-toggle-btn" data-demandview="graph"
                            aria-pressed="false" title="Chart view" onclick="setDemandView('graph')">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="2 11 6 6 9 9 14 3"/></svg>
                        Graph
                    </button>
                    <button type="button" class="view-toggle-btn active" data-demandview="calendar"
                            aria-pressed="true" title="Calendar view" onclick="setDemandView('calendar')">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="11" rx="1.5"/><line x1="2" y1="6.5" x2="14" y2="6.5"/><line x1="5.5" y1="2" x2="5.5" y2="4"/><line x1="10.5" y1="2" x2="10.5" y2="4"/></svg>
                        Calendar
                    </button>
                </div>
            </div>
        </div>

        <!-- Controls row: view switcher (Daily/Weekly/Monthly/Yearly) + the date
             (year) filter inline. Both are hidden in product mode, where the
             inline forecast view renders its own controls. -->
        <div class="chart-filters-row">
            <div class="view-tabs">
                <button type="button" class="view-tab active" data-view="daily">Daily</button>
                <button type="button" class="view-tab"        data-view="weekly">Weekly</button>
                <button type="button" class="view-tab"        data-view="monthly">Monthly</button>
                <button type="button" class="view-tab"        data-view="yearly">Yearly</button>
            </div>

            <!-- Date filter — year pills; scrolls horizontally when there are many years -->
            <div class="year-selector" id="year-selector"></div>
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

        <!-- Calendar view — replaces whichever graph the current mode shows.
             Populated by DemandCalendar.renderIn(); see applyDemandView(). -->
        <div id="demand-calendar" class="demand-calendar" style="display:none"></div>

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

        <!-- (The per-product forecast range now lives in the card header above,
             beside the selected-product chip.) -->

        <!-- Inline forecast view — ChartModal.renderIn() target. Shown when a
             single product with a saved forecast is selected; it replaces the
             native historical chart + controls above (this IS the default
             "run forecast" view, now inline instead of in a modal). -->
        <div id="analysis-forecast" class="analysis-forecast" style="display:none"></div>

    </div>

    <!-- The catalogue is forecast automatically on import; the horizon lives in
         Settings. Selecting a product shows its forecast inline (generated on the
         spot if it has none yet); its range can be overridden near the chart. -->
    <div id="product-section">

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

        <?php if (!empty($products)): ?>
        <div class="product-list-toolbar">
            <div class="category-select-wrap">
                <label class="category-select-label" for="category-select">Category</label>
                <div class="category-select-control">
                    <select id="category-select" class="category-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg class="category-select-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>

                <!-- Fill in cost / price / stock for the whole catalogue at once. -->
                <button type="button" class="batch-edit-btn" onclick="bpOpen()"
                        title="Set cost price, selling price and stock for all products">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Batch edit
                </button>
            </div>
            <div class="product-toolbar-right">
                <select id="product-sort" class="product-sort-select" aria-label="Sort products">
                    <option value="name">Sort: Name (A&ndash;Z)</option>
                    <option value="demand">Sort: Forecast demand</option>
                    <option value="order">Sort: Suggested order</option>
                    <option value="category">Sort: Category</option>
                </select>
                <div class="view-toggle" role="group" aria-label="Product list view">
                    <button type="button" class="view-toggle-btn active" data-view="list"
                            aria-pressed="true" title="List view" onclick="setProductView('list')">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="2" y1="4" x2="14" y2="4"/><line x1="2" y1="8" x2="14" y2="8"/><line x1="2" y1="12" x2="14" y2="12"/></svg>
                        List
                    </button>
                    <button type="button" class="view-toggle-btn" data-view="cards"
                            aria-pressed="false" title="Card view" onclick="setProductView('cards')">
                        <svg viewBox="0 0 16 16" fill="currentColor"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
                        Cards
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="product-list">
            <?php if (empty($products)): ?>
            <div class="product-empty">No products found.</div>
            <?php else: ?>

                <?php foreach ($products as $product): ?>
                <?php $fc = $latestForecasts[$product['id']] ?? null; ?>
                <button class="product-row"
                        data-product-id="<?php echo $product['id']; ?>"
                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                        data-product-sku="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                        data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
                        data-cost-price="<?php echo $product['cost_price']    !== null ? htmlspecialchars((string) $product['cost_price'])    : ''; ?>"
                        data-selling-price="<?php echo $product['selling_price'] !== null ? htmlspecialchars((string) $product['selling_price']) : ''; ?>"
                        data-orig-cost-price="<?php echo $product['orig_cost_price']    !== null ? htmlspecialchars((string) $product['orig_cost_price'])    : ''; ?>"
                        data-orig-selling-price="<?php echo $product['orig_selling_price'] !== null ? htmlspecialchars((string) $product['orig_selling_price']) : ''; ?>"
                        data-demand="<?php echo $fc ? (float) $fc['total_predicted'] : -1; ?>"
                        data-order-qty="<?php echo $fc ? (int) $fc['restock_qty'] : -1; ?>">

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

                    <!-- Saved forecast summary. Rendered from the DB on load, and
                         updated live by AutoForecast as each product finishes. -->
                    <div class="product-row-forecast" id="prf-<?php echo $product['id']; ?>">
                        <?php if ($fc): ?>
                            <span class="prf-demand">
                                <?php echo number_format((float) $fc['total_predicted']); ?>
                                <span class="prf-unit">units</span>
                            </span>
                            <?php if ((int) $fc['restock_qty'] > 0): ?>
                            <span class="prf-order">Order <?php echo number_format((int) $fc['restock_qty']); ?></span>
                            <?php else: ?>
                            <span class="prf-no-order">No price set</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="prf-empty">Not forecast yet</span>
                        <?php endif; ?>
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


<script src="<?php echo BASE_URL; ?>/assets/js/vendor/chart.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/vendor/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/vendor/hammer.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/vendor/chartjs-plugin-zoom.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/vendor/chartjs-plugin-annotation.min.js"></script>
<script>
const CHART_EVENTS        = <?php echo json_encode($chartEvents); ?>;
const EVENT_COLOR         = '#FF5722';
const INITIAL_PRODUCT_ID  = <?php echo json_encode($initialProductId); ?>;
const INITIAL_EVENT_ID    = <?php echo json_encode($initialEventId); ?>;

// Forecast inputs: last catalogue sale date (window start is clamped past it),
// the user's global horizon, and the full product list (id + prices + per-product
// horizon override), independent of the search filter.
const LAST_SALE_DATE      = <?php echo json_encode($lastSaleDate); ?>;
const USER_HORIZON        = <?php echo (int) $userHorizon; ?>;
const AUTO_PRODUCTS       = <?php echo json_encode(array_map(function ($p) {
    return [
        'id'        => (int) $p['id'],
        'name'      => $p['name'],
        'cost'      => $p['cost_price']    !== null ? (float) $p['cost_price']    : null,
        'price'     => $p['selling_price'] !== null ? (float) $p['selling_price'] : null,
        'origCost'  => $p['orig_cost_price']    !== null ? (float) $p['orig_cost_price']    : null,
        'origPrice' => $p['orig_selling_price'] !== null ? (float) $p['orig_selling_price'] : null,
        'horizon'   => $p['forecast_horizon_days'] !== null ? (int) $p['forecast_horizon_days'] : null,
    ];
}, $catalogue)); ?>;

// Accuracy reporting toggle (SHOW_ACCURACY_FEATURES in config/bootstrap.php).
// chart_modal.js reads this to show/hide the per-product "Accuracy" tab.
window.PV_SHOW_ACCURACY = <?php echo SHOW_ACCURACY_FEATURES ? 'true' : 'false'; ?>;
</script>
<script src="<?php echo BASE_URL; ?>/pages/js/chart.shared.js?v=<?php echo filemtime(__DIR__ . '/js/chart.shared.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/pages/js/chart_modal.js?v=<?php echo filemtime(__DIR__ . '/js/chart_modal.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?>/pages/js/demand_calendar.js?v=<?php echo filemtime(__DIR__ . '/js/demand_calendar.js'); ?>"></script>
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
let activeProductCost  = null;  // effective products.cost_price (null = not set in DB)
let activeProductPrice = null;  // effective products.selling_price
let activeProductOrigCost  = null;  // imported price — for "Reset to imported price"
let activeProductOrigPrice = null;
let fullHistorical     = [];
let activeYears        = new Set();
let currentView        = 'daily'; // 'daily' | 'weekly' | 'monthly' | 'yearly'

function loadDisabledEvents() {
    const saved = localStorage.getItem('pv_disabled_events');
    return saved ? new Set(JSON.parse(saved)) : new Set();
}
let disabledEventIds = loadDisabledEvents();

function saveDisabledEvents() {
    localStorage.setItem('pv_disabled_events', JSON.stringify([...disabledEventIds]));
}

// ── Init ──────────────────────────────────────────────────────────────────────
// Gentle eased scroll to bring an element just below the sticky navbar. Native
// scrollIntoView({behavior:'smooth'}) can't be tuned and often feels fast/abrupt —
// this animates over a distance-scaled duration with an ease-in-out curve.
function smoothScrollToEl(el) {
    if (!el) return;
    var navOffset = 80; // sticky navbar (h-16) + a little breathing room
    var startY    = window.pageYOffset;
    var targetY   = Math.max(0, el.getBoundingClientRect().top + startY - navOffset);
    var dist      = targetY - startY;
    if (Math.abs(dist) < 4) return;

    // Respect reduced-motion — jump straight there.
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        window.scrollTo(0, targetY);
        return;
    }

    // app.css sets `html { scroll-behavior: smooth }`; override it to auto for the
    // duration so our per-frame scrollTo() calls land instantly instead of each
    // queuing its own native smooth scroll (which fights this animation and is the
    // source of the abrupt, janky feel). Restore it when we're done.
    var root = document.documentElement;
    var prevBehavior = root.style.scrollBehavior;
    root.style.scrollBehavior = 'auto';

    // Distance-scaled duration so short and long hops both feel unhurried.
    var duration = Math.min(900, Math.max(450, Math.abs(dist) * 0.55));
    var startT = null;
    function ease(t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; } // easeInOutCubic
    function step(ts) {
        if (startT === null) startT = ts;
        var p = Math.min((ts - startT) / duration, 1);
        window.scrollTo(0, startY + dist * ease(p));
        if (p < 1) { requestAnimationFrame(step); }
        else       { root.style.scrollBehavior = prevBehavior; }
    }
    requestAnimationFrame(step);
}

// Scroll to the product list section (search bar + list).
function scrollToProductList() {
    smoothScrollToEl(document.getElementById('product-section'));
}

// ── Product list view: list ⇆ cards ───────────────────────────────────────────
// Same product buttons either way — only the .product-list layout changes — so
// every existing behavior (click-to-chart, active state, live forecast updates,
// category filter) keeps working. Choice is remembered per browser.
function setProductView(view) {
    var list = document.querySelector('.product-list');
    if (!list) return;
    list.classList.toggle('as-cards', view === 'cards');
    document.querySelectorAll('.view-toggle-btn').forEach(function (b) {
        var on = b.dataset.view === view;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    try { localStorage.setItem('pv_product_view', view); } catch (e) {}
}
// Apply the saved preference on load (runs at parse time — the list is already
// in the DOM above this script — so there's no list→cards flash).
(function () {
    var saved = 'list';
    try { saved = localStorage.getItem('pv_product_view') || 'list'; } catch (e) {}
    if (saved === 'cards') setProductView('cards');
}());

// Smoothly animate the Demand Analysis card's height when its content changes
// size — most visibly when switching between the aggregate (all-products) chart
// and a taller individual-product forecast view. Height:auto can't be CSS-
// transitioned, so a ResizeObserver watches the card's natural height and eases
// the change (setting an explicit px height only for the ~0.4s animation, so
// pop-overs/tooltips aren't clipped the rest of the time).
document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('.chart-card');
    if (!card || !('ResizeObserver' in window)) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var lastH = card.offsetHeight;
    var animating = false;
    var timer = null;

    var ro = new ResizeObserver(function () {
        if (animating) return;                       // ignore our own explicit-height writes
        var target = card.offsetHeight;              // natural height (card is on auto)
        if (Math.abs(target - lastH) < 8) { lastH = target; return; }

        var fromH = lastH;
        lastH = target;
        animating = true;
        card.style.overflow   = 'hidden';
        card.style.height     = fromH + 'px';
        card.getBoundingClientRect();                // reflow so the start height sticks
        card.style.transition = 'height 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
        card.style.height     = target + 'px';

        clearTimeout(timer);
        timer = setTimeout(function () {
            card.style.transition = '';
            card.style.height     = '';              // release back to auto
            card.style.overflow   = '';
            lastH = card.offsetHeight;               // resync to the true natural height
            animating = false;
        }, 440);
    });

    // Start observing after the first chart settles so page load doesn't animate.
    setTimeout(function () { lastH = card.offsetHeight; ro.observe(card); }, 1200);
});

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
            row.dataset.origCostPrice    ? parseFloat(row.dataset.origCostPrice)    : null,
            row.dataset.origSellingPrice ? parseFloat(row.dataset.origSellingPrice) : null,
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
                smoothScrollToEl(document.querySelector('.chart-card'));
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

// ── Category dropdown ─────────────────────────────────────────────────────────
var categorySelect = document.getElementById('category-select');
if (categorySelect) {
    categorySelect.addEventListener('change', function() {
        activeCategory    = categorySelect.value;
        activeProductId   = null;
        activeProductName = '';
        activeYears       = new Set();
        updateProductRows();
        updateChartContext();
        filterProductList(activeCategory);
        enterAggregateMode();
        loadSalesChart(activeCategory, null);
    });
}

// ── Product sort dropdown ───────────────────────────────────────────────────────
// Reorders the .product-row buttons in place (DOM order), so it works the same
// whether the list is showing as List or Cards. Rows without a saved forecast
// carry data-demand/data-order-qty = -1, which naturally sinks them to the
// bottom of the demand/order sorts.
var productSort = document.getElementById('product-sort');
if (productSort) {
    productSort.addEventListener('change', applyProductSort);
}

// NaN-safe number read — plain `|| fallback` would also catch a legitimate 0
// (e.g. an unpriced product's order qty), sinking it to "not forecast" rank.
function _numOr(v, fallback) {
    var n = parseFloat(v);
    return isNaN(n) ? fallback : n;
}

function applyProductSort() {
    var sel = document.getElementById('product-sort');
    var list = document.querySelector('.product-list');
    if (!sel || !list) return;

    var key  = sel.value;
    var rows = Array.prototype.slice.call(list.querySelectorAll('.product-row[data-product-id]'));

    rows.sort(function (a, b) {
        switch (key) {
            case 'demand':
                return _numOr(b.dataset.demand, -1) - _numOr(a.dataset.demand, -1);
            case 'order':
                return _numOr(b.dataset.orderQty, -1) - _numOr(a.dataset.orderQty, -1);
            case 'category':
                return (a.dataset.category || '').localeCompare(b.dataset.category || '')
                    || a.dataset.productName.localeCompare(b.dataset.productName);
            default: // name
                return a.dataset.productName.localeCompare(b.dataset.productName);
        }
    });

    rows.forEach(function (row) { list.appendChild(row); });
}

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
function selectProduct(productId, productName, productSku, costPrice, sellingPrice, origCost, origPrice) {
    if (activeProductId === productId) { deselectProduct(); return; }
    activeProductId    = productId;
    activeProductName  = productName;
    activeProductSku   = productSku || '';
    activeProductCost  = (costPrice    != null && !isNaN(costPrice)    && costPrice    > 0) ? costPrice    : null;
    activeProductPrice = (sellingPrice != null && !isNaN(sellingPrice) && sellingPrice > 0) ? sellingPrice : null;
    activeProductOrigCost  = (origCost  != null && !isNaN(origCost)  && origCost  > 0) ? origCost  : null;
    activeProductOrigPrice = (origPrice != null && !isNaN(origPrice) && origPrice > 0) ? origPrice : null;
    activeYears        = new Set();
    showProductAnalysis();   // inline forecast view (or historical if not forecast yet)
    updateProductRows();
    updateChartContext();
}

function deselectProduct() {
    activeProductId    = null;
    activeProductName  = '';
    activeProductSku   = '';
    activeProductCost  = null;
    activeProductPrice = null;
    activeProductOrigCost  = null;
    activeProductOrigPrice = null;
    activeYears        = new Set();
    enterAggregateMode();
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

// Records which aggregate-chart state is wanted ('loading' | 'error' | 'chart').
// applyDemandView() is what actually shows it, so the calendar can't be stomped
// on by a chart load finishing in the background.
var chartState = 'loading';

function showChartState(state, msg) {
    chartState = state;
    if (state === 'error') document.getElementById('chart-error').textContent = msg;
    applyDemandView();
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
    // Chart.js snapshots the canvas's inline style when a chart is constructed and
    // restores it on destroy(). A chart built while the canvas was hidden (calendar
    // view active) therefore re-applies display:none on every later rebuild. Re-derive
    // visibility from our own state here, after the rebuild, so it can't stick hidden.
    applyDemandView();
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
//  INLINE FORECAST VIEW + HORIZON RE-FORECAST
//  Selecting a product shows its forecast chart inline (ChartModal.renderIn)
//  as the default Demand Analysis view — no modal, no manual "run" button.
// ════════════════════════════════════════════════════════════════════

// Switch the Demand Analysis card between the aggregate historical chart (native
// canvas + its controls) and the per-product forecast view rendered inline.
// ── Demand view: graph ⇆ calendar ───────────────────────────────────────────
// Two independent axes decide what the card shows:
//   demandMode — 'aggregate' (no product) | 'product' (one selected)
//   demandView — 'graph' | 'calendar'
// applyDemandView() is the single place that resolves them into visibility, so
// the mode switches and the view toggle can never fight each other.
var demandMode = 'aggregate';
var demandView = 'calendar';   // calendar is the default view; the graph is one click away
try { demandView = localStorage.getItem('pv_demand_view') || 'calendar'; } catch (e) {}

var calendarLoadedKey = null;   // scope the loaded data belongs to
var calendarLoading   = false;

// Identifies the current scope so the calendar refetches when it changes.
function demandScopeKey() {
    return activeProductId ? ('p:' + activeProductId) : ('c:' + (activeCategory || ''));
}

function setDemandView(view) {
    demandView = (view === 'calendar') ? 'calendar' : 'graph';
    try { localStorage.setItem('pv_demand_view', demandView); } catch (e) {}
    applyDemandView();
}

function applyDemandView() {
    var show = function (el, mode) { if (el) el.style.display = mode; };
    var byId = function (id) { return document.getElementById(id); };

    var calendarOn = (demandView === 'calendar');

    // Toggle button state
    document.querySelectorAll('[data-demandview]').forEach(function (b) {
        var on = (b.dataset.demandview === demandView);
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    // Graph-only furniture (aggregate chart controls + the inline forecast view)
    var isProduct = (demandMode === 'product');
    show(byId('demand-chart-btns'), (!calendarOn && !isProduct) ? '' : 'none');
    show(document.querySelector('.chart-filters-row'), (!calendarOn && !isProduct) ? '' : 'none');
    // The inline forecast view holds two halves: the chart (.cm-chart-wrap) and the
    // Restock / "Why this forecast" tabs (#cm-info). Only the chart half is redundant
    // in calendar mode — the tabs are product information the calendar doesn't cover —
    // so keep the container mounted in product mode and hide just the chart.
    var af = byId('analysis-forecast');
    show(af, isProduct ? '' : 'none');
    if (af) af.classList.toggle('is-chart-hidden', calendarOn);

    // The per-product forecast range still applies in either view — it changes the
    // data both of them draw — so it follows the mode, not the view.
    show(byId('product-range'), isProduct ? 'flex' : 'none');

    if (calendarOn) {
        ['chart-loading', 'chart-error', 'demand-chart', 'product-insights'].forEach(function (id) {
            show(byId(id), 'none');
        });
        show(byId('demand-calendar'), '');
        loadDemandCalendar();
        return;
    }

    show(byId('demand-calendar'), 'none');

    if (isProduct) {
        // The inline forecast view owns the whole area in product mode.
        ['chart-loading', 'chart-error', 'demand-chart', 'product-insights'].forEach(function (id) {
            show(byId(id), 'none');
        });
        return;
    }

    // Aggregate graph — honour whatever showChartState() last recorded.
    show(byId('chart-loading'), chartState === 'loading' ? 'flex'  : 'none');
    show(byId('chart-error'),   chartState === 'error'   ? 'flex'  : 'none');
    show(byId('demand-chart'),  chartState === 'chart'   ? 'block' : 'none');
    show(byId('product-insights'), 'none');
}

// Fetches (once per scope) and renders the calendar. Read-only and Flask-free —
// it draws saved data, so it works even when the forecast server is down.
function loadDemandCalendar() {
    var el = document.getElementById('demand-calendar');
    if (!el) return;

    var key = demandScopeKey();
    if (calendarLoading || key === calendarLoadedKey) return;

    calendarLoading = true;
    // Only show a placeholder on the FIRST load. On a re-fetch (switching product
    // or category) keep the existing grid on screen and just dim it — replacing it
    // with a spinner collapses the card and re-expands it, which reads as a blink.
    if (el.querySelector('.dc-grid')) {
        DemandCalendar.setLoading(true);
    } else {
        el.innerHTML = '<div class="dc-empty">Loading demand…</div>';
    }

    var body = new FormData();
    if (activeProductId) body.append('product_id', activeProductId);
    else                 body.append('category',   activeCategory || '');

    fetch('<?php echo BASE_URL; ?>/api/get_demand_calendar.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            calendarLoading = false;
            if (data.error) {
                DemandCalendar.setLoading(false);
                el.innerHTML = '<div class="dc-empty">' + data.error + '</div>';
                return;
            }
            calendarLoadedKey = key;
            DemandCalendar.renderIn(el, data);
        })
        .catch(function () {
            calendarLoading = false;
            calendarLoadedKey = null;
            DemandCalendar.setLoading(false);
            el.innerHTML = '<div class="dc-empty">Could not load demand data. Please refresh.</div>';
        });
}

// Scope changed (product picked/dropped, category switched) — drop the cached
// data so the next render refetches for the new scope.
function invalidateDemandCalendar() {
    calendarLoadedKey = null;
    if (demandView === 'calendar') loadDemandCalendar();
}

function enterProductMode() {
    demandMode = 'product';
    applyDemandView();
}

function enterAggregateMode() {
    ChartModal.destroyIn();
    demandMode = 'aggregate';
    applyDemandView();
}

// Effective forecast horizon (days) for a product: its override from AUTO_PRODUCTS
// if set, else the user's global horizon.
function productHorizon(pid) {
    var p = AUTO_PRODUCTS.find(function (x) { return x.id === pid; });
    return (p && p.horizon) ? p.horizon : USER_HORIZON;
}

function _rangeNote(text, isError) {
    var note = document.getElementById('product-range-note');
    if (!note) return;
    note.textContent = text;
    note.className   = 'product-range-note' + (isError ? ' is-error' : '');
}

// Per-product range override: save it, then re-forecast just this product at the
// new range and re-render (generateProductForecast reads productHorizon()).
function applyProductRange() {
    var pid = activeProductId;
    if (pid === null) return;

    var days = parseInt(document.getElementById('product-range-input').value, 10);
    var btn  = document.getElementById('product-range-apply');
    if (isNaN(days) || days < 1 || days > 60) { _rangeNote('Enter 1–60 days.', true); return; }

    if (btn) { btn.disabled = true; btn.textContent = 'Applying…'; }
    _rangeNote('', false);

    var body = new FormData();
    body.append('product_id', pid);
    body.append('forecast_horizon_days', days);

    fetch('<?php echo BASE_URL; ?>/api/update_product_horizon.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (btn) { btn.disabled = false; btn.textContent = 'Apply'; }
            if (data.error) { _rangeNote(data.error, true); return; }
            var p = AUTO_PRODUCTS.find(function (x) { return x.id === pid; });
            if (p) p.horizon = days;
            // The scope is unchanged but its forecast data isn't, so the calendar's
            // cache has to be dropped explicitly here.
            invalidateDemandCalendar();
            generateProductForecast(pid, fullHistorical);   // re-forecast at the new range
        })
        .catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Apply'; }
            _rangeNote('Network error. Try again.', true);
        });
}

// Load a selected product's historical + saved forecast, then render the forecast
// chart inline. If the product has no saved forecast yet (e.g. added after the
// last catalogue run), fall back to its historical chart.
function showProductAnalysis() {
    var pid = activeProductId;
    enterProductMode();

    // Sync the range control to this product's effective horizon.
    var rangeInput = document.getElementById('product-range-input');
    if (rangeInput) rangeInput.value = productHorizon(pid);
    _rangeNote('', false);

    var af = document.getElementById('analysis-forecast');
    if (af) af.innerHTML =
        '<div class="af-inline-loading">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>' +
        ' Loading forecast…</div>';

    var histBody = new FormData(); histBody.append('product_id', pid);
    var fcBody   = new FormData(); fcBody.append('product_id', pid);

    Promise.all([
        fetch('<?php echo BASE_URL; ?>/api/get_sales_chart.php',      { method: 'POST', body: histBody }).then(function (r) { return r.json(); }),
        fetch('<?php echo BASE_URL; ?>/api/get_product_forecast.php', { method: 'POST', body: fcBody   }).then(function (r) { return r.json(); }),
    ]).then(function (res) {
        if (activeProductId !== pid) return; // selection changed while loading
        var hist = (res[0] && res[0].historical) ? res[0].historical : [];
        var fc   = res[1] || {};
        fullHistorical = hist;

        if (fc.forecast && fc.forecast.length) {
            renderInlineForecast(hist, fc.forecast, fc.meta || null);
        } else {
            // No saved forecast for this product yet — generate one now (Prophet +
            // Newsvendor at the current horizon) so the forecast always shows on
            // click, even for data imported before the auto-forecast existed.
            generateProductForecast(pid, hist);
        }
    }).catch(function () {
        _showInlineForecastError('Could not load this product&rsquo;s forecast. Please refresh.');
    });
}

// Generate a forecast for one product on demand: Prophet then Newsvendor (which
// also saves it), then render it inline. current_stock defaults to 0; the user
// can refine it from the Restock tab. Prices come from the selected product.
function generateProductForecast(pid, hist) {
    var af = document.getElementById('analysis-forecast');
    if (af) af.innerHTML =
        '<div class="af-inline-loading">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>' +
        ' Forecasting this product… (first time may take a moment)</div>';

    var range = AutoForecast.computeDayRange(productHorizon(pid), LAST_SALE_DATE);
    var cost  = activeProductCost  != null ? activeProductCost  : 0;
    var price = activeProductPrice != null ? activeProductPrice : 0;

    // 1) Prophet forecast for this product.
    fetch('<?php echo BASE_URL; ?>/api/run_batch_prophet.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ product_ids: [pid], from_date: range.from, to_date: range.to }),
    })
        .then(function (r) { return r.json(); })
        .then(function (presp) {
            if (activeProductId !== pid) return; // selection changed
            var pres = presp.results && presp.results[0];
            if (presp.error || !pres || !pres.success || !pres.forecast || !pres.forecast.length) {
                _showInlineForecastError('Could not forecast this product. Make sure the forecast server is running and this product has enough sales history.');
                return;
            }
            var forecast = pres.forecast;

            // 2) Newsvendor — also saves the forecast (restock at 0 stock).
            fetch('<?php echo BASE_URL; ?>/api/run_batch_newsvendor.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ products: [{ id: pid, cost_price: cost, selling_price: price, current_stock: 0, forecast: forecast }] }),
            })
                .then(function (r) { return r.json(); })
                .then(function (nvresp) {
                    if (activeProductId !== pid) return;
                    var opt  = nvresp.results && nvresp.results[0];
                    var meta = null;
                    if (opt && opt.success && opt.priced) {
                        meta = {
                            total_predicted:      opt.total_predicted,
                            restock_qty:          opt.restock_qty,
                            current_stock:        opt.current_stock,
                            cost_price:           opt.cost_price,
                            selling_price:        opt.selling_price,
                            total_std:            opt.total_std,
                            optimal_total:        opt.optimal_total,
                            est_profit:           opt.est_profit,
                            rho_used:             opt.rho_used,
                            std_inflation_factor: opt.std_inflation_factor,
                        };
                    }
                    if (opt && opt.success) updateProductCard(pid, opt.total_predicted, opt.restock_qty || 0);
                    renderInlineForecast(hist, forecast, meta);
                })
                .catch(function () {
                    // Newsvendor failed but Prophet succeeded — still show the forecast chart.
                    if (activeProductId === pid) renderInlineForecast(hist, forecast, null);
                });
        })
        .catch(function () {
            _showInlineForecastError('Network error while forecasting. Please try again.');
        });
}

function _showInlineForecastError(msg) {
    var af = document.getElementById('analysis-forecast');
    if (af) af.innerHTML = '<div class="af-inline-error">' + msg + '</div>';
}

// Render the forecast-projection view inline via the shared chart modal renderer.
function renderInlineForecast(historical, forecast, meta) {
    enterProductMode();

    ChartModal.renderIn(document.getElementById('analysis-forecast'), {
        label:               'Demand Forecast',
        title:               productDisplayLabel(),
        productId:           activeProductId,
        accuracyBase:        '<?php echo BASE_URL; ?>',
        historical:          historical,
        forecast:            forecast,
        hasBand:             true,
        productCostPrice:    activeProductCost,
        productSellingPrice: activeProductPrice,
        productOrigCost:     activeProductOrigCost,   // imported price, for "Reset to imported"
        productOrigPrice:    activeProductOrigPrice,
        disabledEventIds:    disabledEventIds,
        meta:                meta,
        onRunAgain:          null,
        // Restock refine: re-run Newsvendor for this product with the owner's real
        // stock/prices. run_batch_newsvendor optimizes AND saves (replacing the
        // product's forecast), so there's no separate Save step — we just refresh
        // the card. Same callback contract chart_modal.js expects.
        onGenerateRestock: function (inputs, done) {
            fetch('<?php echo BASE_URL; ?>/api/run_batch_newsvendor.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ products: [{
                    id:            activeProductId,
                    cost_price:    inputs.cost_price,
                    selling_price: inputs.selling_price,
                    current_stock: inputs.current_stock,
                    forecast:      forecast,
                }] }),
            })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    var opt = resp.results && resp.results[0];
                    if (resp.error || !opt || !opt.success) {
                        done({ error: (resp.error) || (opt && opt.error) || 'Could not calculate restock insight.' });
                        return;
                    }
                    // Persist the cost/price the owner used so it sticks (future
                    // forecasts, cards, on-demand runs). orig_* is preserved server-side
                    // for "Reset to imported price". Fire-and-forget + update local state.
                    persistProductPricing(activeProductId, inputs.cost_price, inputs.selling_price);
                    updateProductCard(activeProductId, opt.total_predicted, opt.restock_qty);
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
    });
}

// ── Update a product card's forecast summary ──────────────────────────────────
// Called after a forecast is saved (auto-run or single-product refine) so the
// card reflects the new numbers without a page reload. Exposed globally so the
// auto-run driver (autorun_forecast.js) can reuse it. Cards for products hidden
// by the current search filter simply aren't in the DOM — nothing to update.
function updateProductCard(productId, totalPredicted, restockQty) {
    const el = document.getElementById('prf-' + productId);
    if (!el) return;

    const demandNum = Number(totalPredicted || 0);
    const order      = Number(restockQty     || 0);
    const orderHtml = order > 0
        ? '<span class="prf-order">Order ' + order.toLocaleString() + '</span>'
        : '<span class="prf-no-order">No price set</span>';

    el.innerHTML =
        '<span class="prf-demand">' + demandNum.toLocaleString() + ' <span class="prf-unit">units</span></span>' +
        orderHtml;

    // Keep the sort dataset in sync so "Forecast demand" / "Suggested order"
    // sorting reflects a forecast that just completed (auto-run or restock refine).
    const row = el.closest('.product-row');
    if (row) {
        row.dataset.demand   = demandNum;
        row.dataset.orderQty = order;
    }
}
window.updateProductCard = updateProductCard;

// Persist an edited cost/price to the product (so it sticks) and sync local state
// so the current selection, cards, and future on-demand runs use it. orig_* is
// preserved server-side for "Reset to imported price". Best-effort.
function persistProductPricing(productId, cost, price) {
    if (!(cost > 0 && price > cost)) return;

    activeProductCost  = cost;
    activeProductPrice = price;

    var p = AUTO_PRODUCTS.find(function (x) { return x.id === productId; });
    if (p) { p.cost = cost; p.price = price; }

    var row = document.querySelector('.product-row[data-product-id="' + productId + '"]');
    if (row) { row.dataset.costPrice = cost; row.dataset.sellingPrice = price; }

    var body = new FormData();
    body.append('product_id', productId);
    body.append('cost_price', cost);
    body.append('selling_price', price);
    fetch('<?php echo BASE_URL; ?>/api/update_product_pricing.php', { method: 'POST', body: body })
        .catch(function () {});
}
</script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>

<script>
const AUTO_BASE_URL = '<?php echo BASE_URL; ?>';
</script>
<script src="<?php echo BASE_URL; ?>/pages/js/autorun_forecast.js?v=<?php echo filemtime(__DIR__ . '/js/autorun_forecast.js'); ?>"></script>

<script>const BP_BASE = '<?php echo BASE_URL; ?>';</script>
<?php require_once __DIR__ . '/../includes/batch_pricing_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

