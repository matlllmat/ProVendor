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

            <!-- Controls: Events toggle + per-event filter + Reset Zoom -->
            <div class="flex items-center gap-3" style="flex-shrink:0; margin-left:1rem">

                <!-- Events toggle + filter dropdown grouped as a split button -->
                <div class="event-btn-group">
                    <button id="events-toggle-btn" onclick="toggleHighlight()"
                        class="text-xs flex items-center gap-1.5"
                        style="padding:0.3rem 0.7rem; border-radius:999px 0 0 999px;
                               border:1px solid #D2C8AE; border-right:none; font-weight:600">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        Events
                    </button>
                    <button id="event-filter-trigger" onclick="toggleEventFilter(event)"
                            class="event-filter-trigger" title="Filter individual events">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>

                    <!-- Per-event filter panel — content built by buildEventFilterPanel() -->
                    <div id="event-filter-panel" class="event-filter-panel" style="display:none"></div>
                </div>

                <button onclick="resetZoom()"
                    class="text-xs text-[#261F0E] flex items-center gap-1.5 hover:opacity-70 transition-opacity"
                    style="opacity:0.45">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                    </svg>
                    Reset Zoom
                </button>
            </div>
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

        <!-- Date range filter -->
        <div class="date-filter-bar">
            <span class="date-filter-label">Date Range</span>
            <input type="date" id="date-from" class="date-filter-input">
            <span class="date-filter-sep">—</span>
            <input type="date" id="date-to" class="date-filter-input">
            <button onclick="applyDateFilter()" class="date-filter-btn">Apply</button>
            <button onclick="resetDateFilter()" class="date-filter-btn date-filter-btn-ghost">All</button>
        </div>

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
let currentHist       = [];
let highlightEnabled  = localStorage.getItem('pv_highlight_events') === 'true';

// Per-event filter: stores IDs of DISABLED events (empty = all visible by default).
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
    // Wire up product row clicks via delegation to avoid inline-onclick quoting issues.
    document.querySelectorAll('.product-row[data-product-id]').forEach(function(row) {
        row.addEventListener('click', function() {
            selectProduct(
                parseInt(this.dataset.productId, 10),
                this.dataset.productName
            );
        });
    });

    // When arriving from an event_detail product row, pre-configure the page:
    // enable event highlighting and show only the linked event.
    if (INITIAL_EVENT_ID !== null) {
        const uniqueIds = new Set(CHART_EVENTS.map(function(ev) { return ev.id; }));
        uniqueIds.delete(INITIAL_EVENT_ID);
        disabledEventIds = uniqueIds;
        saveDisabledEvents();
        highlightEnabled = true;
        localStorage.setItem('pv_highlight_events', 'true');
    }

    updateHighlightBtn();

    // Pre-select the product if one was passed, otherwise load the default chart.
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

    // Close event filter panel on outside click.
    document.addEventListener('click', function(e) {
        const group = document.querySelector('.event-btn-group');
        if (group && !group.contains(e.target)) closeEventFilter();
    });
});

// ── Category tabs ─────────────────────────────────────────────────────────────
document.querySelectorAll('.category-tab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.category-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCategory    = btn.dataset.category;
        activeProductId   = null;
        activeProductName = '';
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
    loadSalesChart(activeCategory, productId);
    updateProductRows();
    updateChartContext();
}

function deselectProduct() {
    activeProductId   = null;
    activeProductName = '';
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
            initDateFilter();
            updateHighlightBtn();
            const from = document.getElementById('date-from').value;
            const to   = document.getElementById('date-to').value;
            renderChart(
                (from || to)
                    ? fullHistorical.filter(r => (!from || r.date >= from) && (!to || r.date <= to))
                    : fullHistorical
            );
        })
        .catch(() => showChartState('error', 'Network error. Please refresh.'));
}

function showChartState(state, msg) {
    document.getElementById('chart-loading').style.display = state === 'loading' ? 'flex'  : 'none';
    document.getElementById('chart-error').style.display   = state === 'error'   ? 'flex'  : 'none';
    document.getElementById('demand-chart').style.display  = state === 'chart'   ? 'block' : 'none';
    if (state === 'error') document.getElementById('chart-error').textContent = msg;
}

// ── Date range filter ─────────────────────────────────────────────────────────
function initDateFilter() {
    if (!fullHistorical.length) return;
    const dates = fullHistorical.map(r => r.date);
    const min = dates[0], max = dates[dates.length - 1];
    const fromEl = document.getElementById('date-from');
    const toEl   = document.getElementById('date-to');
    fromEl.min = min; fromEl.max = max;
    toEl.min   = min; toEl.max   = max;
    const sf = localStorage.getItem('pv_date_from') || '';
    const st = localStorage.getItem('pv_date_to')   || '';
    fromEl.value = (sf && sf >= min && sf <= max) ? sf : '';
    toEl.value   = (st && st >= min && st <= max) ? st : '';
}

function applyDateFilter() {
    const from = document.getElementById('date-from').value;
    const to   = document.getElementById('date-to').value;
    localStorage.setItem('pv_date_from', from);
    localStorage.setItem('pv_date_to',   to);
    renderChart(fullHistorical.filter(r => (!from || r.date >= from) && (!to || r.date <= to)));
}

function resetDateFilter() {
    document.getElementById('date-from').value = '';
    document.getElementById('date-to').value   = '';
    localStorage.removeItem('pv_date_from');
    localStorage.removeItem('pv_date_to');
    renderChart(fullHistorical);
}

// ── Events toggle ─────────────────────────────────────────────────────────────
function toggleHighlight() {
    highlightEnabled = !highlightEnabled;
    localStorage.setItem('pv_highlight_events', highlightEnabled);
    updateHighlightBtn();
    renderChart(currentHist);
}

function updateHighlightBtn() {
    const btn     = document.getElementById('events-toggle-btn');
    const trigger = document.getElementById('event-filter-trigger');
    const on      = highlightEnabled;
    if (!btn) return;
    [btn, trigger].forEach(function(el) {
        if (!el) return;
        el.style.background  = on ? '#261F0E'     : 'transparent';
        el.style.color       = on ? '#F0E8D0'     : '#261F0E';
        el.style.borderColor = on ? '#261F0E'     : '#D2C8AE';
        el.style.opacity     = on ? '1'           : '0.5';
    });
}

// ── Per-event filter panel ────────────────────────────────────────────────────
// Builds the dropdown content from CHART_EVENTS on demand.
// Events are deduplicated by id, shown as a flat alphabetical list.
function buildEventFilterPanel() {
    const panel = document.getElementById('event-filter-panel');
    if (!panel) return;
    panel.innerHTML = '';

    // Deduplicate: same event can have many yearly/monthly instances.
    const seen = {};
    CHART_EVENTS.forEach(function(ev) {
        if (!seen[ev.id]) seen[ev.id] = { id: ev.id, name: ev.name, color: ev.color || '#FF5722' };
    });
    const unique = Object.values(seen).sort(function(a, b) {
        return a.name.localeCompare(b.name);
    });

    if (!unique.length) {
        const msg = document.createElement('p');
        msg.className   = 'event-filter-empty';
        msg.textContent = 'No events found.';
        panel.appendChild(msg);
        return;
    }

    const inner = document.createElement('div');
    inner.className = 'event-filter-inner';

    // ── Header row with Show/Hide all ─────────────────────────────────────
    const header = document.createElement('div');
    header.className = 'event-filter-group-header';

    const dot = document.createElement('span');
    dot.className        = 'event-filter-dot';
    dot.style.background = EVENT_COLOR;

    const allLabel = document.createElement('span');
    allLabel.textContent = 'Events';

    const toggleBtn = document.createElement('button');
    toggleBtn.className = 'event-filter-group-toggle';
    refreshGroupToggle(toggleBtn, unique);
    toggleBtn.addEventListener('click', function() {
        toggleTypeAll(unique);
        refreshGroupToggle(toggleBtn, unique);
        unique.forEach(function(ev) {
            const cb = panel.querySelector('input[data-event-id="' + ev.id + '"]');
            if (cb) cb.checked = !disabledEventIds.has(ev.id);
        });
        if (highlightEnabled) renderChart(currentHist);
    });

    header.appendChild(dot);
    header.appendChild(allLabel);
    header.appendChild(toggleBtn);
    inner.appendChild(header);

    // ── Individual event checkboxes ───────────────────────────────────────
    unique.forEach(function(ev) {
        const lbl = document.createElement('label');
        lbl.className = 'event-filter-row event-filter-row-child';

        const cb = document.createElement('input');
        cb.type            = 'checkbox';
        cb.dataset.eventId = ev.id;
        cb.checked         = !disabledEventIds.has(ev.id);

        cb.addEventListener('change', function() {
            if (this.checked) {
                disabledEventIds.delete(ev.id);
            } else {
                disabledEventIds.add(ev.id);
            }
            saveDisabledEvents();
            refreshGroupToggle(toggleBtn, unique);
            if (highlightEnabled) renderChart(currentHist);
        });

        const evDot = document.createElement('span');
        evDot.className        = 'event-filter-dot';
        evDot.style.background = ev.color;

        const nameSpan = document.createElement('span');
        nameSpan.textContent = ev.name;

        lbl.appendChild(cb);
        lbl.appendChild(evDot);
        lbl.appendChild(nameSpan);
        inner.appendChild(lbl);
    });

    panel.appendChild(inner);
}

// Updates the "Show all / Hide all" text on the group toggle button.
function refreshGroupToggle(btn, evs) {
    const allVisible = evs.every(ev => !disabledEventIds.has(ev.id));
    btn.textContent  = allVisible ? 'Hide all' : 'Show all';
}

// Enables or disables all events.
function toggleTypeAll(evs) {
    const allVisible = evs.every(ev => !disabledEventIds.has(ev.id));
    evs.forEach(function(ev) {
        if (allVisible) {
            disabledEventIds.add(ev.id);
        } else {
            disabledEventIds.delete(ev.id);
        }
    });
    saveDisabledEvents();
}

function toggleEventFilter(e) {
    e.stopPropagation();
    const panel = document.getElementById('event-filter-panel');
    if (!panel) return;
    if (panel.style.display === 'none') {
        buildEventFilterPanel(); // always rebuild to reflect latest state
        panel.style.display = '';
    } else {
        panel.style.display = 'none';
    }
}

function closeEventFilter() {
    const panel = document.getElementById('event-filter-panel');
    if (panel) panel.style.display = 'none';
}

// ── Chart annotations ─────────────────────────────────────────────────────────
// Compact mode (>8 events in range): thin line + small lettered circle.
// Full mode   (≤8 events in range): labeled line or shaded box.
// buildAnnotations is called on first render AND after every zoom/pan.

function buildAnnotations(visibleFrom, visibleTo) {
    const visible = CHART_EVENTS.filter(function(ev) {
        if (disabledEventIds.has(ev.id)) return false;
        const evEnd = ev.instance_end || ev.instance_start;
        if (visibleFrom && evEnd             < visibleFrom) return false;
        if (visibleTo   && ev.instance_start > visibleTo)   return false;
        return true;
    });

    const compact = visible.length > 8;

    // ── Lane assignment — stagger labels so nearby events don't pile up ───────
    // Two events share a lane if the earlier one's end + proximityDays >= later one's start.
    // proximityDays scales with the visible window so it works at any zoom level.
    const totalDays     = (visibleFrom && visibleTo)
        ? Math.max(1, (new Date(visibleTo) - new Date(visibleFrom)) / 86400000)
        : 365;
    const proximityDays = Math.max(3, Math.floor(totalDays * 0.04));
    const laneH         = compact ? 18 : 26;   // px gap between lanes
    const baseY         = compact ?  4 : -4;   // top-most label yAdjust

    // Sort chronologically, assign each event to the first free lane.
    const sorted = visible.slice().sort(function(a, b) {
        return a.instance_start < b.instance_start ? -1 : 1;
    });
    const laneEnd  = [];   // laneEnd[l] = last occupied date in lane l
    const yAdjOf   = [];   // parallel to sorted[]

    sorted.forEach(function(ev) {
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

    sorted.forEach(function(ev, i) {
        const color = ev.color || EVENT_COLOR;
        const start = ev.instance_start;
        const end   = ev.instance_end;
        const yAdj  = yAdjOf[i];
        const key   = 'evt-' + i;

        if (compact) {
            // ── Compact: subtle line + small circle badge ────────────────────
            annotations[key] = {
                type: 'line',
                scaleID: 'x',
                value: start,
                borderColor: hexToRgba(color, 0.25),
                borderWidth: 1,
                borderDash: [2, 5],
                label: {
                    display: true,
                    content: '●',
                    position: 'start',
                    backgroundColor: color,
                    color: '#fff',
                    font: { size: 7, weight: '700', family: 'Lora' },
                    padding: { x: 3, y: 2 },
                    borderRadius: 99,
                    yAdjust: yAdj,
                },
            };
        } else if (end && end !== start) {
            // ── Full: multi-day shaded box ───────────────────────────────────
            annotations[key] = {
                type: 'box',
                xMin: start,
                xMax: end,
                backgroundColor: hexToRgba(color, 0.08),
                borderColor: color,
                borderWidth: 1,
                label: {
                    display: true,
                    content: ev.name,
                    position: { x: 'start', y: 'start' },
                    backgroundColor: hexToRgba(color, 0.88),
                    color: '#fff',
                    font: { size: 9, family: 'Lora' },
                    padding: { x: 5, y: 3 },
                    borderRadius: 3,
                    yAdjust: yAdj,
                },
            };
        } else {
            // ── Full: single-day vertical line ───────────────────────────────
            annotations[key] = {
                type: 'line',
                scaleID: 'x',
                value: start,
                borderColor: color,
                borderWidth: 1.5,
                borderDash: [4, 3],
                label: {
                    display: true,
                    content: ev.name,
                    position: 'start',
                    backgroundColor: hexToRgba(color, 0.88),
                    color: '#fff',
                    font: { size: 9, family: 'Lora' },
                    padding: { x: 5, y: 3 },
                    borderRadius: 3,
                    yAdjust: yAdj,
                },
            };
        }
    });

    return annotations;
}

// Rebuilds annotations for the current visible date range after zoom or pan.
// This triggers the compact ↔ full switch automatically.
function updateAnnotationsOnZoom({ chart }) {
    if (!highlightEnabled || !chart.scales.x) return;
    const tsToDate = function(ts) {
        const d = new Date(ts);
        return d.getFullYear()
            + '-' + String(d.getMonth() + 1).padStart(2, '0')
            + '-' + String(d.getDate()).padStart(2, '0');
    };
    chart.options.plugins.annotation.annotations = buildAnnotations(
        tsToDate(chart.scales.x.min),
        tsToDate(chart.scales.x.max)
    );
    chart.update('none');
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

// ── Render chart ──────────────────────────────────────────────────────────────
function renderChart(historical) {
    currentHist = historical;

    if (!historical.length) {
        showChartState('error', 'No sales data for this selection.');
        return;
    }

    showChartState('chart');

    const labels      = historical.map(r => r.date);
    const values      = historical.map(r => r.actual);
    const visibleFrom = labels[0]                 ?? null;
    const visibleTo   = labels[labels.length - 1] ?? null;

    // Dataset label shown in tooltips (not in the legend — legend is hidden).
    const datasetLabel = activeProductId ? activeProductName : 'Sales';

    if (demandChart) demandChart.destroy();

    demandChart = new Chart(document.getElementById('demand-chart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: datasetLabel,
                data: values,
                borderColor: '#1A6933',
                backgroundColor: 'rgba(26,105,51,0.08)',
                borderWidth: 2,
                pointRadius: 0,
                fill: true,
                tension: 0.3,
            }],
        },
        options: {
            responsive: true,
            interaction: { mode: 'x', intersect: false },
            plugins: {
                // Legend hidden — selected product is shown via #chart-selected-product instead.
                legend: { display: false },
                zoom: {
                    zoom: {
                        wheel:          { enabled: true },
                        pinch:          { enabled: true },
                        mode:           'x',
                        onZoomComplete: updateAnnotationsOnZoom,
                    },
                    pan: {
                        enabled:        true,
                        mode:           'x',
                        onPanComplete:  updateAnnotationsOnZoom,
                    },
                },
                tooltip: {
                    backgroundColor: '#261F0E',
                    titleColor: '#D2C8AE',
                    bodyColor: '#F0E8D0',
                    padding: 10,
                    callbacks: {
                        label: function(ctx) {
                            if (ctx.parsed.y === null) return null;
                            return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + ' units sold';
                        }
                    }
                },
                annotation: {
                    annotations: highlightEnabled
                        ? buildAnnotations(visibleFrom, visibleTo)
                        : {},
                },
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        minUnit: 'day',
                        tooltipFormat: 'yyyy-MM-dd',
                        displayFormats: {
                            day:   'MMM d',
                            week:  'MMM d',
                            month: 'MMM yyyy',
                        },
                    },
                    ticks: {
                        color: 'rgba(38,31,14,0.45)',
                        font: { family: 'Lora', size: 11 },
                        maxTicksLimit: 10,
                        maxRotation: 0,
                        callback: function(value, index, ticks) {
                            const d = new Date(value);
                            // Only show two-line format at day unit; at week/month
                            // the default formatted string is used instead.
                            const span = ticks.length > 1
                                ? ticks[ticks.length - 1].value - ticks[0].value
                                : 0;
                            const avgGap = ticks.length > 1
                                ? span / (ticks.length - 1)
                                : Infinity;
                            // avgGap < ~10 days → we're at day-level granularity
                            if (avgGap < 10 * 86400000) {
                                const month   = d.toLocaleString('default', { month: 'short' });
                                const day     = d.getDate();
                                const weekday = d.toLocaleString('default', { weekday: 'short' });
                                return [month + ' ' + day, weekday];
                            }
                            // week / month level — let Chart.js use the displayFormat string
                            return this.getLabelForValue(value);
                        },
                    },
                    grid: { color: 'rgba(38,31,14,0.06)' },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: 'rgba(38,31,14,0.45)',
                        font: { family: 'Lora', size: 11 },
                    },
                    grid: { color: 'rgba(38,31,14,0.06)' },
                },
            },
        },
    });
}

function resetZoom() {
    if (demandChart) demandChart.resetZoom();
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

    // Build initial annotations: forecast-start line + any visible events if toggled
    const visibleFrom = historical.length ? historical[0].date    : forecast[0].date;
    const visibleTo   = forecast.length   ? forecast[forecast.length - 1].date : null;
    const annotations = Object.assign(
        { forecastStart: buildForecastStartAnnotation(forecast[0].date) },
        fcHighlightEnabled ? buildAnnotations(visibleFrom, visibleTo) : {}
    );

    // Rebuild main chart
    if (fcChart) fcChart.destroy();

    let initialMin = historical.length ? historical[0].date : (forecast.length ? forecast[0].date : null);
    if (forecast.length) {
        const d = new Date(forecast[0].date);
        d.setMonth(d.getMonth() - 6);
        initialMin = d.toISOString().slice(0, 10);
    }

    fcChart = new Chart(document.getElementById('fc-chart'), {
        type: 'line',
        data: {
            datasets: [
                // Historical — solid green line
                {
                    label: 'Historical',
                    data: (function () {
                        const map = {};
                        historical.forEach(function (r) { map[r.date] = r.actual; });
                        return Object.keys(map).sort().map(function (d) { return { x: d, y: map[d] }; });
                    }()),
                    borderColor: '#1A6933',
                    borderWidth: 2,
                    backgroundColor: 'transparent',
                    pointRadius: 0,
                    fill: false,
                    tension: 0.3,
                },
                // Confidence upper — invisible border, fills DOWN to the lower dataset
                {
                    label: '_upper',
                    data: forecast.map(function (r) { return { x: r.date, y: r.upper }; }),
                    borderColor: 'transparent',
                    backgroundColor: 'rgba(255,87,34,0.13)',
                    borderWidth: 0,
                    pointRadius: 0,
                    fill: '+1',
                    tension: 0.3,
                },
                // Confidence lower — no fill, marks the bottom of the band
                {
                    label: '_lower',
                    data: forecast.map(function (r) { return { x: r.date, y: r.lower }; }),
                    borderColor: 'transparent',
                    borderWidth: 0,
                    pointRadius: 0,
                    fill: false,
                    tension: 0.3,
                },
                // Projected Demand — dashed orange line on top of band
                {
                    label: 'Projected Demand',
                    data: (function () {
                        const map = {};
                        forecast.forEach(function (r) { map[r.date] = r.predicted; });
                        return Object.keys(map).sort().map(function (d) { return { x: d, y: map[d] }; });
                    }()),
                    borderColor: '#FF5722',
                    borderWidth: 2,
                    borderDash: [6, 3],
                    backgroundColor: 'transparent',
                    pointRadius: 0,
                    fill: false,
                    tension: 0.3,
                },
            ],
        },
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
                },
                tooltip: {
                    backgroundColor: '#261F0E',
                    titleColor: '#D2C8AE',
                    bodyColor: '#F0E8D0',
                    padding: 10,
                    filter: function (item) {
                        return item.dataset.label !== '_upper' && item.dataset.label !== '_lower';
                    },
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
                    time: {
                        minUnit: 'day',
                        tooltipFormat: 'yyyy-MM-dd',
                        displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM yyyy' },
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
                    grid: { color: 'rgba(38,31,14,0.06)' },
                },
            },
        },
    });

    renderWeeklyChart(forecast);
    renderNewsvendorExplanation(opt, fcCostPrice, fcSellingPrice, fcCurrentStock);
}

// ── Forecast-start annotation (always shown) ──────────────────────────────────
function buildForecastStartAnnotation(startDate) {
    return {
        type: 'line',
        scaleID: 'x',
        value: startDate,
        borderColor: 'rgba(38,31,14,0.25)',
        borderWidth: 1,
        borderDash: [4, 4],
        label: {
            display: true,
            content: 'Forecast',
            position: 'start',
            backgroundColor: 'rgba(38,31,14,0.72)',
            color: '#F0E8D0',
            font: { size: 9, family: 'Lora' },
            padding: { x: 5, y: 3 },
            borderRadius: 3,
            yAdjust: -4,
        },
    };
}

// ── Events toggle on modal chart ──────────────────────────────────────────────
function toggleFcEvents() {
    fcHighlightEnabled = !fcHighlightEnabled;
    updateFcEventsBtn();
    if (!fcChart) return;
    const visibleFrom = fcChart.scales.x
        ? tsToDateStr(fcChart.scales.x.min) : null;
    const visibleTo = fcChart.scales.x
        ? tsToDateStr(fcChart.scales.x.max) : null;
    fcChart.options.plugins.annotation.annotations = Object.assign(
        { forecastStart: buildForecastStartAnnotation(fcForecastRows[0].date) },
        fcHighlightEnabled ? buildAnnotations(visibleFrom, visibleTo) : {}
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
    chart.options.plugins.annotation.annotations = Object.assign(
        { forecastStart: buildForecastStartAnnotation(fcForecastRows[0].date) },
        buildAnnotations(tsToDateStr(chart.scales.x.min), tsToDateStr(chart.scales.x.max))
    );
    chart.update('none');
}

// Shared timestamp → 'YYYY-MM-DD' helper used by both chart zoom handlers.
function tsToDateStr(ts) {
    const d = new Date(ts);
    return d.getFullYear()
        + '-' + String(d.getMonth() + 1).padStart(2, '0')
        + '-' + String(d.getDate()).padStart(2, '0');
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