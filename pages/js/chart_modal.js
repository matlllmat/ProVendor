// pages/js/chart_modal.js
// Unified forecast/reports chart modal.
// Requires chart.shared.js (YEAR_COLORS, groupByYearNorm, buildForecastStartAnnotation,
//   buildChartAnnotations, tsToDateStr, hexToRgba) loaded before this file.
// Requires Chart.js + chartjs-adapter-date-fns + chartjs-plugin-zoom + chartjs-plugin-annotation.
//
// Public API:
//   ChartModal.open(cfg)           — standalone overlay (reports page)
//   ChartModal.openLoading(l, t)   — open overlay with spinner, then call showResults
//   ChartModal.showResults(cfg)    — fill results into already-open overlay
//   ChartModal.close()             — close overlay
//   ChartModal.renderIn(el, cfg)   — render results into existing element (forecast page)
//   ChartModal.destroyIn()         — destroy charts rendered via renderIn
//   ChartModal.setSaveBtnState(d, t) — disable/re-enable + relabel the save button
//   ChartModal.showSaveMsg(type, html) — show error/success message in action row

var ChartModal = (function () {

    // ── private state ─────────────────────────────────────────────────────────
    var _chart = null;
    var _st = {
        activeYears:  new Set(),
        forecastOnly: false,
        eventsOn:     false,
        fcStart:      null,
        disabledIds:  new Set(),
        nvOpen:       true,
        view:         'daily',   // 'daily' | 'weekly' | 'monthly' | 'yearly'
        cfg:          null,      // last config (kept so view-switch can re-render)
        meta:         null,      // newsvendor result; null until user requests insight
    };

    // ── modal DOM (created once, reused) ──────────────────────────────────────
    var _MODAL_HTML =
        '<div id="cm-modal" class="fixed inset-0 z-[1000] flex items-center justify-center hidden" role="dialog" aria-modal="true">' +
            '<div id="cm-backdrop" class="absolute inset-0" style="background:rgba(38,31,14,0.55)"></div>' +
            '<div class="cm-card">' +
                '<div class="cm-header">' +
                    '<div style="min-width:0">' +
                        '<p id="cm-label" class="cm-label"></p>' +
                        '<h2 id="cm-title" class="cm-title">—</h2>' +
                    '</div>' +
                    '<button id="cm-close" class="cm-close" title="Close">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                    '</button>' +
                '</div>' +
                '<div id="cm-body"></div>' +
            '</div>' +
        '</div>';

    function _ensureModal() {
        if (document.getElementById('cm-modal')) return;
        var wrap = document.createElement('div');
        wrap.innerHTML = _MODAL_HTML;
        document.body.appendChild(wrap.firstElementChild);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var m = document.getElementById('cm-modal');
                if (m && !m.classList.contains('hidden')) close();
            }
        });
    }

    // ── loading state HTML ────────────────────────────────────────────────────
    var _LOADING_HTML =
        '<div class="cm-loading">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite">' +
                '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>' +
            '</svg>' +
            'Loading chart…' +
        '</div>';

    // ── restock form HTML (auto-fills locked fields when product has prices) ─
    function _restockFormHTML(cfg) {
        var hasCost  = cfg.productCostPrice    != null && cfg.productCostPrice    > 0;
        var hasPrice = cfg.productSellingPrice != null && cfg.productSellingPrice > 0;

        function lockedField(label, value) {
            return '<div class="cm-rs-field">' +
                '<label class="cm-rs-label">' + label + '</label>' +
                '<div class="cm-rs-input-wrap cm-rs-input-wrap-locked" title="Auto-filled from your product profile">' +
                    '<span class="cm-rs-input-affix">₱</span>' +
                    '<input type="text" class="cm-rs-input" value="' + value.toFixed(2) + '" readonly>' +
                    '<span class="cm-rs-lock-icon">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                            '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>' +
                            '<path d="M7 11V7a5 5 0 0 1 10 0v4"/>' +
                        '</svg>' +
                    '</span>' +
                '</div>' +
            '</div>';
        }
        function priceInput(id, label) {
            return '<div class="cm-rs-field">' +
                '<label class="cm-rs-label" for="' + id + '">' + label + '</label>' +
                '<div class="cm-rs-input-wrap">' +
                    '<span class="cm-rs-input-affix">₱</span>' +
                    '<input type="number" id="' + id + '" class="cm-rs-input" min="0" step="0.01" placeholder="0.00">' +
                '</div>' +
            '</div>';
        }

        var costField  = hasCost  ? lockedField('Cost Price',    cfg.productCostPrice)    : priceInput('cm-rs-cost',  'Cost Price');
        var priceField = hasPrice ? lockedField('Selling Price', cfg.productSellingPrice) : priceInput('cm-rs-price', 'Selling Price');
        var stockField =
            '<div class="cm-rs-field">' +
                '<label class="cm-rs-label" for="cm-rs-stock">Current Stock on Hand</label>' +
                '<div class="cm-rs-input-wrap">' +
                    '<input type="number" id="cm-rs-stock" class="cm-rs-input" min="0" step="1" placeholder="0" value="0">' +
                    '<span class="cm-rs-input-affix cm-rs-input-affix-right">units</span>' +
                '</div>' +
            '</div>';

        return '<div id="cm-restock-form" class="cm-restock-form">' +
            '<div class="cm-restock-header">' +
                '<div style="min-width:0">' +
                    '<p class="cm-restock-eyebrow">Step 2 — Restock Insight</p>' +
                    '<h3 class="cm-restock-title">Get a restock recommendation</h3>' +
                    '<p class="cm-restock-sub">Run the Newsvendor model on this forecast to compute the optimal order quantity.</p>' +
                '</div>' +
                '<div class="cm-restock-icon">' +
                    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' +
                        '<rect x="2" y="6" width="20" height="14" rx="2"/>' +
                        '<path d="M16 6V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>' +
                        '<line x1="12" y1="11" x2="12" y2="17"/>' +
                        '<line x1="9" y1="14" x2="15" y2="14"/>' +
                    '</svg>' +
                '</div>' +
            '</div>' +
            '<div class="cm-restock-fields">' + costField + priceField + stockField + '</div>' +
            '<div id="cm-restock-error" class="cm-msg cm-msg-error" style="display:none"></div>' +
            '<button id="cm-restock-btn" class="cm-primary-btn">Generate Restock Insight →</button>' +
        '</div>';
    }

    // ── results HTML ──────────────────────────────────────────────────────────
    function _resultsHTML(cfg) {
        var saveBtn   = cfg.onSave     ? '<button id="cm-save-btn" class="cm-primary-btn" disabled style="opacity:0.45;cursor:not-allowed">Save Forecast</button>' : '';
        var againBtn  = cfg.onRunAgain ? '<button id="cm-run-again-btn" class="cm-ghost-btn-lg">← Run Again</button>' : '';
        var actionRow = (cfg.onSave || cfg.onRunAgain)
            ? '<div id="cm-save-msg" class="cm-msg" style="display:none;margin:0 1.5rem 0.75rem"></div>' +
              '<div class="cm-action-row">' + againBtn + saveBtn + '</div>'
            : '';

        return '<div class="cm-chart-wrap">' +
                '<div class="cm-chart-controls">' +
                    '<div class="cm-legend">' +
                        '<span class="cm-legend-item">' +
                            '<svg width="18" height="10" viewBox="0 0 18 10"><line x1="0" y1="5" x2="18" y2="5" stroke="#1A6933" stroke-width="2"/></svg>' +
                            ' Historical ' +
                            '<span class="cm-legend-info" data-tip="Actual units sold each day before this forecast — the real demand pattern the model learned from.">ⓘ</span>' +
                        '</span>' +
                        '<span class="cm-legend-item">' +
                            '<svg width="18" height="10" viewBox="0 0 18 10"><line x1="0" y1="5" x2="18" y2="5" stroke="#FF5722" stroke-width="2" stroke-dasharray="5 3"/></svg>' +
                            ' Projected Demand ' +
                            '<span class="cm-legend-info" data-tip="Units the model predicts will be needed each day. This drives the recommended order quantity.">ⓘ</span>' +
                        '</span>' +
                        '<span id="cm-band-legend" class="cm-legend-item">' +
                            '<span style="display:inline-block;width:18px;height:10px;border-radius:3px;background:rgba(255,87,34,0.2)"></span>' +
                            ' Confidence band' +
                        '</span>' +
                    '</div>' +
                    '<div class="cm-chart-btns">' +
                        '<div class="cm-view-tabs">' +
                            '<button type="button" class="cm-view-tab cm-view-tab-on" data-view="daily">Daily</button>' +
                            '<button type="button" class="cm-view-tab"                data-view="weekly">Weekly</button>' +
                            '<button type="button" class="cm-view-tab"                data-view="monthly">Monthly</button>' +
                            '<button type="button" class="cm-view-tab"                data-view="yearly">Yearly</button>' +
                        '</div>' +
                        '<div class="event-btn-group">' +
                            '<button id="cm-events-btn" class="cm-toggle-btn" style="border-radius:999px 0 0 999px;border-right:none">' +
                                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
                                ' Events' +
                            '</button>' +
                            '<button id="cm-events-filter" class="event-filter-trigger" title="Filter events">' +
                                '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>' +
                            '</button>' +
                        '</div>' +
                        '<button id="cm-fo-btn" class="cm-toggle-btn">Forecast Only</button>' +
                        '<button id="cm-zoom-btn" class="cm-ghost-btn">' +
                            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                            ' Reset Zoom' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="cm-year-sel" class="cm-year-selector"></div>' +
                '<canvas id="cm-canvas" style="max-height:280px"></canvas>' +
            '</div>' +

            // Restock insight — initial form, then results after submission.
            '<div id="cm-restock-section">' +
                _restockFormHTML(cfg) +
                '<div id="cm-restock-results" style="display:none">' +
                    '<div class="cm-stats-grid">' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Total Forecast</p><p id="cm-s-demand" class="cm-stat-value"></p><p class="cm-stat-sub">units predicted</p></div>' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Current Stock</p><p id="cm-s-stock" class="cm-stat-value"></p><p class="cm-stat-sub">units on hand</p></div>' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Order Qty</p><p id="cm-s-order" class="cm-stat-value cm-stat-accent"></p><p class="cm-stat-sub">units to order</p></div>' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Est. Profit</p><p id="cm-s-profit" class="cm-stat-value cm-stat-green"></p><p class="cm-stat-sub">at forecast demand</p></div>' +
                    '</div>' +
                    '<div class="cm-nv-section">' +
                        '<button id="cm-nv-toggle" class="cm-nv-header">' +
                            '<span class="cm-nv-title">Newsvendor Model — How this was calculated</span>' +
                            '<svg id="cm-nv-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0"><polyline points="6 9 12 15 18 9"/></svg>' +
                        '</button>' +
                        '<div id="cm-nv-body" class="cm-nv-body"></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            actionRow;
    }

    // ── wire interactive elements ─────────────────────────────────────────────
    function _wire(cfg) {
        document.getElementById('cm-events-btn').addEventListener('click', _toggleEvents);
        var filterBtn = document.getElementById('cm-events-filter');
        if (filterBtn) {
            filterBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var opened = toggleEventsChecklist(filterBtn, _st.disabledIds, function () { _applyAnnotations(); });
                if (opened && !_st.eventsOn) _toggleEvents();
            });
        }
        document.getElementById('cm-fo-btn').addEventListener('click', _toggleForecastOnly);
        document.getElementById('cm-zoom-btn').addEventListener('click', function () { if (_chart) _chart.resetZoom(); });

        // View tabs (Daily / Weekly / Monthly / Yearly)
        document.querySelectorAll('.cm-view-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var view = btn.dataset.view;
                if (view === _st.view) return;
                document.querySelectorAll('.cm-view-tab').forEach(function (b) { b.classList.remove('cm-view-tab-on'); });
                btn.classList.add('cm-view-tab-on');
                _st.view = view;
                _renderView();
            });
        });

        // Restock form submission
        var rb = document.getElementById('cm-restock-btn');
        if (rb) rb.addEventListener('click', function () { _submitRestock(cfg); });

        // Wire newsvendor toggle once results panel exists (it's there but hidden)
        var nvToggle = document.getElementById('cm-nv-toggle');
        if (nvToggle) nvToggle.addEventListener('click', _toggleNv);

        var saveBtn  = document.getElementById('cm-save-btn');
        var againBtn = document.getElementById('cm-run-again-btn');
        if (saveBtn  && cfg.onSave)     saveBtn.addEventListener('click', cfg.onSave);
        if (againBtn && cfg.onRunAgain) againBtn.addEventListener('click', cfg.onRunAgain);
    }

    // ── restock form submission ───────────────────────────────────────────────
    function _submitRestock(cfg) {
        var hasCost  = cfg.productCostPrice    != null && cfg.productCostPrice    > 0;
        var hasPrice = cfg.productSellingPrice != null && cfg.productSellingPrice > 0;

        var costInput  = document.getElementById('cm-rs-cost');
        var priceInput = document.getElementById('cm-rs-price');
        var stockInput = document.getElementById('cm-rs-stock');
        var errEl      = document.getElementById('cm-restock-error');
        var btn        = document.getElementById('cm-restock-btn');

        var cost  = hasCost  ? cfg.productCostPrice    : (costInput  ? parseFloat(costInput.value)  || 0 : 0);
        var price = hasPrice ? cfg.productSellingPrice : (priceInput ? parseFloat(priceInput.value) || 0 : 0);
        var stock = stockInput ? parseInt(stockInput.value, 10) || 0 : 0;

        function showErr(msg) {
            errEl.className   = 'cm-msg cm-msg-error';
            errEl.textContent = msg;
            errEl.style.display = '';
            if (btn) { btn.disabled = false; btn.textContent = 'Generate Restock Insight →'; }
        }

        if (cost  <= 0)   { showErr('Please enter a cost price.');                          return; }
        if (price <= 0)   { showErr('Please enter a selling price.');                       return; }
        if (price <= cost){ showErr('Selling price must be greater than cost price.');      return; }

        // Loading state
        errEl.className   = 'cm-msg';
        errEl.style.background = 'rgba(38,31,14,0.04)';
        errEl.style.border     = '1px solid #D2C8AE';
        errEl.style.color      = '#261F0E';
        errEl.textContent = 'Running Newsvendor model…';
        errEl.style.display = '';
        if (btn) { btn.disabled = true; btn.textContent = 'Calculating…'; }

        if (!cfg.onGenerateRestock) {
            showErr('Restock callback not wired.');
            return;
        }

        cfg.onGenerateRestock(
            { cost_price: cost, selling_price: price, current_stock: stock },
            function (result) {
                if (!result || result.error) {
                    showErr((result && result.error) || 'Could not calculate restock insight.');
                    return;
                }
                _showRestockResults(result);
            }
        );
    }

    // ── reveal stats + newsvendor after a successful restock generation ──────
    function _showRestockResults(meta) {
        _st.meta = meta;
        var form    = document.getElementById('cm-restock-form');
        var results = document.getElementById('cm-restock-results');
        if (form)    form.style.display    = 'none';
        if (results) results.style.display = '';

        _renderStats(meta);
        _renderNv(meta);

        // Enable save now that we have something to save.
        var saveBtn = document.getElementById('cm-save-btn');
        if (saveBtn) {
            saveBtn.disabled    = false;
            saveBtn.style.opacity = '';
            saveBtn.style.cursor  = '';
        }
    }

    // ── render results into a container ──────────────────────────────────────
    function _render(container, cfg) {
        _destroyCharts();
        _st.activeYears  = new Set();
        _st.forecastOnly = false;
        _st.eventsOn     = false;
        _st.fcStart      = null;
        _st.disabledIds  = cfg.disabledEventIds || new Set();
        _st.nvOpen       = true;
        _st.view         = 'daily';
        _st.cfg          = cfg;
        _st.meta         = null;

        container.innerHTML = _resultsHTML(cfg);
        _wire(cfg);

        var bandLegend = document.getElementById('cm-band-legend');
        if (bandLegend) bandLegend.style.display = cfg.hasBand ? '' : 'none';

        _renderView();

        // Reports page passes meta directly (saved forecasts already have restock data).
        // Skip the form and show the results immediately.
        if (cfg.meta && cfg.meta.cost_price) {
            _showRestockResults(cfg.meta);
        } else if (!cfg.onGenerateRestock) {
            // No way to generate insight and no pre-existing data — hide the whole section.
            var section = document.getElementById('cm-restock-section');
            if (section) section.style.display = 'none';
        }
    }

    // ── public: standalone modal (reports) ───────────────────────────────────
    function open(cfg) {
        _ensureModal();
        document.getElementById('cm-label').textContent  = cfg.label || 'Demand Forecast';
        document.getElementById('cm-title').textContent  = cfg.title || '—';
        document.getElementById('cm-backdrop').onclick   = close;
        document.getElementById('cm-close').onclick      = close;
        _render(document.getElementById('cm-body'), cfg);
        document.getElementById('cm-modal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function openLoading(label, title) {
        _ensureModal();
        document.getElementById('cm-label').textContent  = label || 'Demand Plan';
        document.getElementById('cm-title').textContent  = title || '—';
        document.getElementById('cm-backdrop').onclick   = close;
        document.getElementById('cm-close').onclick      = close;
        document.getElementById('cm-body').innerHTML     = _LOADING_HTML;
        document.getElementById('cm-modal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function showResults(cfg) {
        var body = document.getElementById('cm-body');
        if (!body) return;
        if (cfg.title) document.getElementById('cm-title').textContent = cfg.title;
        _render(body, cfg);
    }

    function close() {
        var m = document.getElementById('cm-modal');
        if (m) m.classList.add('hidden');
        document.body.style.overflow = '';
        _destroyCharts();
    }

    // ── public: inline rendering (forecast results panel) ────────────────────
    var _inlineContainer = null;

    function renderIn(container, cfg) {
        _inlineContainer = container;
        _render(container, cfg);
    }

    function destroyIn() {
        _destroyCharts();
        if (_inlineContainer) { _inlineContainer.innerHTML = ''; _inlineContainer = null; }
    }

    // ── view dispatcher + control visibility ──────────────────────────────────
    function _renderView() {
        var cfg = _st.cfg;
        if (!cfg) return;

        if (_chart) { _chart.destroy(); _chart = null; }

        switch (_st.view) {
            case 'weekly':  _renderWeeklyView(cfg.historical, cfg.forecast); break;
            case 'monthly': _renderMonthlyView(cfg.historical, cfg.forecast); break;
            case 'yearly':  _renderYearlyView(cfg.historical, cfg.forecast); break;
            default:        _renderDailyView(cfg.historical, cfg.forecast, cfg.hasBand); break;
        }

        // Toggle controls relevant to the active view.
        var yearSel    = document.getElementById('cm-year-sel');
        var eventsGrp  = document.querySelector('.cm-chart-controls .event-btn-group');
        var foBtn      = document.getElementById('cm-fo-btn');
        var zoomBtn    = document.getElementById('cm-zoom-btn');
        var bandLeg    = document.getElementById('cm-band-legend');

        // Year pills only make sense for the aggregated views (weekly / monthly).
        if (yearSel)   yearSel.style.display   = (_st.view === 'weekly' || _st.view === 'monthly') ? '' : 'none';
        if (eventsGrp) eventsGrp.style.display = _st.view === 'daily'  ? '' : 'none';
        if (foBtn)     foBtn.style.display     = _st.view === 'daily'  ? '' : 'none';
        if (zoomBtn)   zoomBtn.style.display   = _st.view === 'daily'  ? '' : 'none';
        if (bandLeg)   bandLeg.style.display   = (_st.view === 'daily' && cfg.hasBand) ? '' : 'none';
    }

    // ── normalize date to base year 2000 (year overlay) ──────────────────────
    function _nd(d) {
        if (!d) return null;
        var n = '2000' + d.slice(4);
        return n === '2000-02-29' ? '2000-02-28' : n;
    }

    // ── DAILY VIEW — single continuous timeline, historical → forecast ───────
    // No year overlay here: the modal is showing a forecast, so the user needs
    // to see the projected period sit naturally at the end of their history.
    function _renderDailyView(historical, forecast, hasBand) {
        var histData = historical.map(function (r) { return { x: r.date, y: r.actual }; });
        var histDS = [{
            label: 'Historical',
            data: histData,
            borderColor: '#1A6933',
            backgroundColor: 'transparent',
            borderWidth: 1.5,
            pointRadius: 0, pointHoverRadius: 3,
            fill: false, tension: 0.3,
        }];

        _st.fcStart = forecast.length ? forecast[0].date : null;
        var fcDS = [];
        if (hasBand) {
            fcDS.push({ label: '_upper', data: forecast.map(function (r) { return { x: r.date, y: r.upper }; }), borderColor: 'transparent', backgroundColor: 'rgba(255,87,34,0.13)', borderWidth: 0, pointRadius: 0, fill: '+1', tension: 0.3 });
            fcDS.push({ label: '_lower', data: forecast.map(function (r) { return { x: r.date, y: r.lower }; }), borderColor: 'transparent', borderWidth: 0, pointRadius: 0, fill: false, tension: 0.3 });
        }
        fcDS.push({ label: 'Projected Demand', data: forecast.map(function (r) { return { x: r.date, y: r.predicted }; }), borderColor: '#FF5722', borderWidth: 3, borderDash: [6, 3], backgroundColor: 'transparent', pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.3 });

        var datasets = histDS.concat(fcDS);

        // Real-date bounds across all visible datasets
        var minDate = null, maxDate = null;
        datasets.forEach(function (ds) {
            if (ds.label === '_upper' || ds.label === '_lower') return;
            ds.data.forEach(function (p) {
                if (!p.x) return;
                if (!minDate || p.x < minDate) minDate = p.x;
                if (!maxDate || p.x > maxDate) maxDate = p.x;
            });
        });
        var PAD  = 3 * 86400000;
        var minT = minDate ? new Date(minDate).getTime() - PAD : 0;
        var maxT = maxDate ? new Date(maxDate).getTime() + PAD : 0;

        // Initial zoom: 12 months of context before the forecast (clamped to actual data).
        var initMin = minDate;
        if (_st.fcStart) {
            var d = new Date(_st.fcStart + 'T00:00:00');
            d.setMonth(d.getMonth() - 12);
            var dStr = d.toISOString().slice(0, 10);
            if (minDate && dStr > minDate) initMin = dStr;
        }

        var ann = _st.fcStart ? { fcStart: buildForecastStartAnnotation(_st.fcStart) } : {};

        _chart = new Chart(document.getElementById('cm-canvas'), {
            type: 'line',
            data: { datasets: datasets },
            options: {
                responsive: true,
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                plugins: {
                    legend: { display: false },
                    zoom: {
                        zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x', onZoomComplete: _onZoom },
                        pan:  { enabled: true, mode: 'x', onPanComplete: _onZoom },
                        limits: { x: { min: minT, max: maxT } },
                    },
                    tooltip: {
                        backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10,
                        filter: function (item) { return item.dataset.label !== '_upper' && item.dataset.label !== '_lower'; },
                        callbacks: {
                            title: function (items) { return items.length ? new Date(items[0].parsed.x).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : ''; },
                            label: function (ctx)   { return ctx.parsed.y == null ? null : ' ' + ctx.dataset.label + ': ' + Math.round(ctx.parsed.y) + ' units'; },
                            afterBody: function (items) {
                                if (!items.length) return null;
                                var realDate = tsToDateStr(items[0].parsed.x);
                                var seen = new Set(), evts = [];
                                (CHART_EVENTS || []).forEach(function (ev) {
                                    if (_st.disabledIds.has(ev.id)) return;
                                    var end = ev.instance_end || ev.instance_start;
                                    if (realDate >= ev.instance_start && realDate <= end && !seen.has(ev.name)) {
                                        seen.add(ev.name);
                                        evts.push(ev);
                                    }
                                });
                                if (!evts.length) return null;
                                return [' ', '📅 ' + evts.map(function (e) { return e.name; }).join(', ')];
                            },
                        },
                    },
                    annotation: { annotations: ann },
                },
                scales: {
                    x: {
                        type: 'time', min: initMin, max: maxT,
                        time: { minUnit: 'day', tooltipFormat: 'MMM d, yyyy', displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM yyyy', year: 'yyyy' } },
                        ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 }, maxTicksLimit: 8, maxRotation: 0 },
                        grid: { color: 'rgba(38,31,14,0.06)' },
                    },
                    y: { beginAtZero: true, ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } }, grid: { color: 'rgba(38,31,14,0.06)' } },
                },
            },
        });

        // No year pills in daily view — it's one continuous timeline now.
        var sel = document.getElementById('cm-year-sel');
        if (sel) sel.innerHTML = '';
        if (_st.eventsOn) _applyAnnotations();
    }

    // ── WEEKLY VIEW — bar chart, ISO weeks grouped by year ────────────────────
    function _renderWeeklyView(historical, forecast) {
        function weekOfYear(dateStr) {
            var d = new Date(dateStr + 'T00:00:00');
            var target = new Date(d.valueOf());
            var dayNr = (d.getDay() + 6) % 7;
            target.setDate(target.getDate() - dayNr + 3);
            var firstThu = target.valueOf();
            target.setMonth(0, 1);
            if (target.getDay() !== 4) target.setMonth(0, 1 + ((4 - target.getDay()) + 7) % 7);
            return 1 + Math.ceil((firstThu - target) / 604800000);
        }

        var byYear  = {};
        var maxWeek = 52;
        historical.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            var wk = weekOfYear(r.date);
            if (wk > maxWeek) maxWeek = wk;
            if (!byYear[yr]) byYear[yr] = {};
            byYear[yr][wk] = (byYear[yr][wk] || 0) + r.actual;
        });

        var labels    = Array.from({ length: maxWeek }, function (_, i) { return 'W' + (i + 1); });
        var years     = Object.keys(byYear).sort();
        var allActive = _st.activeYears.size === 0;

        var histDS = years.map(function (yr, i) {
            var c = YEAR_COLORS[i % YEAR_COLORS.length];
            var data = Array.from({ length: maxWeek }, function (_, w) { return byYear[yr][w + 1] || 0; });
            return { label: yr, data: data, hidden: !(allActive || _st.activeYears.has(yr)),
                backgroundColor: hexToRgba(c, 0.75), borderColor: c, borderWidth: 1, borderRadius: 2,
                categoryPercentage: 0.85, barPercentage: 0.92 };
        });

        var fcByWeek = {};
        forecast.forEach(function (r) {
            var wk = weekOfYear(r.date);
            fcByWeek[wk] = (fcByWeek[wk] || 0) + r.predicted;
        });
        var fcData = Array.from({ length: maxWeek }, function (_, w) { return fcByWeek[w + 1] || null; });
        var fcDS = { label: 'Forecast', data: fcData, backgroundColor: 'rgba(255,87,34,0.78)', borderColor: '#FF5722', borderWidth: 1, borderRadius: 2,
            categoryPercentage: 0.85, barPercentage: 0.92 };

        _chart = _barChart(labels, histDS.concat([fcDS]), function (items) { return items.length ? 'Week ' + (items[0].dataIndex + 1) : ''; });
        _buildYearPills(years);
    }

    // ── MONTHLY VIEW — bar chart, Jan–Dec grouped by year ─────────────────────
    function _renderMonthlyView(historical, forecast) {
        var MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var byYear = {};
        historical.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            var m  = parseInt(r.date.slice(5, 7), 10) - 1;
            if (!byYear[yr]) byYear[yr] = new Array(12).fill(0);
            byYear[yr][m] += r.actual;
        });

        var years     = Object.keys(byYear).sort();
        var allActive = _st.activeYears.size === 0;

        var histDS = years.map(function (yr, i) {
            var c = YEAR_COLORS[i % YEAR_COLORS.length];
            return { label: yr, data: byYear[yr], hidden: !(allActive || _st.activeYears.has(yr)),
                backgroundColor: hexToRgba(c, 0.75), borderColor: c, borderWidth: 1, borderRadius: 4,
                categoryPercentage: 0.8, barPercentage: 0.9 };
        });

        var fcByMonth = new Array(12).fill(0);
        var fcHasMonth = new Array(12).fill(false);
        forecast.forEach(function (r) {
            var m = parseInt(r.date.slice(5, 7), 10) - 1;
            fcByMonth[m]  += r.predicted;
            fcHasMonth[m] = true;
        });
        var fcData = fcByMonth.map(function (v, i) { return fcHasMonth[i] ? v : null; });
        var fcDS = { label: 'Forecast', data: fcData, backgroundColor: 'rgba(255,87,34,0.78)', borderColor: '#FF5722', borderWidth: 1, borderRadius: 4,
            categoryPercentage: 0.8, barPercentage: 0.9 };

        _chart = _barChart(MONTH_LABELS, histDS.concat([fcDS]));
        _buildYearPills(years);
    }

    // ── YEARLY VIEW — single bar per year + forecast year as extra bar ───────
    function _renderYearlyView(historical, forecast) {
        var totals = {};
        historical.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            totals[yr] = (totals[yr] || 0) + r.actual;
        });

        var fcTotalsByYear = {};
        forecast.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            fcTotalsByYear[yr] = (fcTotalsByYear[yr] || 0) + r.predicted;
        });

        // Merge label list (some forecast years may already exist in historical)
        var allYears = new Set(Object.keys(totals).concat(Object.keys(fcTotalsByYear)));
        var years    = Array.from(allYears).sort();
        var colors   = years.map(function (_, i) { return YEAR_COLORS[i % YEAR_COLORS.length]; });

        var histDS = {
            label: 'Historical',
            data:  years.map(function (y) { return totals[y] || 0; }),
            backgroundColor: colors.map(function (c) { return hexToRgba(c, 0.75); }),
            borderColor: colors, borderWidth: 1, borderRadius: 6,
            categoryPercentage: 0.7, barPercentage: 0.85,
        };
        var fcDS = {
            label: 'Forecast portion',
            data:  years.map(function (y) { return fcTotalsByYear[y] || null; }),
            backgroundColor: 'rgba(255,87,34,0.78)', borderColor: '#FF5722',
            borderWidth: 1, borderRadius: 6,
            categoryPercentage: 0.7, barPercentage: 0.85,
        };

        _chart = _barChart(years, [histDS, fcDS], function (items) { return items.length ? items[0].label : ''; });
    }

    // ── shared bar-chart factory ──────────────────────────────────────────────
    function _barChart(labels, datasets, titleCb) {
        return new Chart(document.getElementById('cm-canvas'), {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10,
                        callbacks: {
                            title: titleCb,
                            label: function (ctx) {
                                if (ctx.parsed.y == null) return null;
                                return ' ' + ctx.dataset.label + ': ' + Math.round(ctx.parsed.y).toLocaleString() + ' units';
                            },
                        },
                    },
                },
                scales: {
                    x: { ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 }, autoSkip: true, maxTicksLimit: 13, maxRotation: 0 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } }, grid: { color: 'rgba(38,31,14,0.06)' } },
                },
            },
        });
    }

    // ── year pills ────────────────────────────────────────────────────────────
    function _buildYearPills(years) {
        var sel = document.getElementById('cm-year-sel');
        if (!sel) return;
        if (years.length <= 1) { sel.innerHTML = ''; return; }
        sel.innerHTML = '';
        years.forEach(function (yr, i) {
            var btn = document.createElement('button');
            btn.className = 'cm-year-pill';
            btn.textContent = yr;
            btn.dataset.year = yr;
            btn.style.setProperty('--yc', YEAR_COLORS[i % YEAR_COLORS.length]);
            btn.addEventListener('click', function () { _toggleYear(yr); });
            sel.appendChild(btn);
        });
        _updateYearPills();
    }

    function _toggleYear(yr) {
        if (_st.activeYears.has(yr)) _st.activeYears.delete(yr); else _st.activeYears.add(yr);
        _updateYearPills();
        if (!_chart) return;
        var all = _st.activeYears.size === 0;
        _chart.data.datasets.forEach(function (ds) {
            if (ds.label === 'Projected Demand' || ds.label === 'Forecast' || ds.label === 'Historical' || ds.label === '_upper' || ds.label === '_lower') return;
            ds.hidden = !(all || _st.activeYears.has(ds.label));
        });
        _chart.update();
    }

    function _updateYearPills() {
        var all = _st.activeYears.size === 0;
        document.querySelectorAll('#cm-year-sel .cm-year-pill').forEach(function (b) {
            var sel = _st.activeYears.has(b.dataset.year);
            b.classList.toggle('cm-pill-on',    all || sel);
            b.classList.toggle('cm-pill-muted', !all && !sel);
        });
    }

    // ── forecast-only toggle ──────────────────────────────────────────────────
    function _toggleForecastOnly() {
        _st.forecastOnly = !_st.forecastOnly;
        var btn = document.getElementById('cm-fo-btn');
        if (btn) btn.classList.toggle('cm-btn-on', _st.forecastOnly);
        if (!_chart) return;
        var all = _st.activeYears.size === 0;
        _chart.data.datasets.forEach(function (ds) {
            if (ds.label === 'Projected Demand' || ds.label === '_upper' || ds.label === '_lower') return;
            if (ds.label === 'Historical') {
                ds.hidden = _st.forecastOnly;
                return;
            }
            ds.hidden = _st.forecastOnly ? true : !(all || _st.activeYears.has(ds.label));
        });
        _chart.update();
    }

    // ── events toggle ─────────────────────────────────────────────────────────
    function _toggleEvents() {
        _st.eventsOn = !_st.eventsOn;
        var btn = document.getElementById('cm-events-btn');
        if (btn) btn.classList.toggle('cm-btn-on', _st.eventsOn);
        _applyAnnotations();
    }

    function _onZoom(arg) { if (_st.eventsOn) _applyAnnotations(arg.chart); }

    function _applyAnnotations(c) {
        var ch = c || _chart;
        if (!ch || !ch.scales || !ch.scales.x) return;
        if (_st.view !== 'daily') return;  // annotations only apply to the time-axis daily view
        var base = _st.fcStart ? { fcStart: buildForecastStartAnnotation(_st.fcStart) } : {};
        // Daily view is a real timeline now — pass normalize=false so event dates aren't remapped to year 2000.
        ch.options.plugins.annotation.annotations = _st.eventsOn
            ? Object.assign(base, buildChartAnnotations(tsToDateStr(ch.scales.x.min), tsToDateStr(ch.scales.x.max), false, _st.disabledIds))
            : base;
        ch.update('none');
    }

    // ── newsvendor toggle ─────────────────────────────────────────────────────
    function _toggleNv() {
        _st.nvOpen = !_st.nvOpen;
        var body = document.getElementById('cm-nv-body');
        var chev = document.getElementById('cm-nv-chev');
        if (body) body.style.display      = _st.nvOpen ? '' : 'none';
        if (chev) chev.style.transform    = _st.nvOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
    }

    // ── stat cards ────────────────────────────────────────────────────────────
    function _renderStats(m) {
        if (!m) return;
        var u  = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        u('cm-s-demand', m.total_predicted != null ? Math.round(m.total_predicted).toLocaleString() + ' units' : '—');
        u('cm-s-stock',  m.current_stock  != null ? Number(m.current_stock).toLocaleString()  + ' units' : '—');
        u('cm-s-order',  m.restock_qty    != null ? Number(m.restock_qty).toLocaleString()    + ' units' : '—');
        u('cm-s-profit', m.est_profit     != null ? '₱' + m.est_profit.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—');
    }

    // ── newsvendor explanation ────────────────────────────────────────────────
    function _renderNv(m) {
        var body = document.getElementById('cm-nv-body');
        if (!body || !m.cost_price) return;
        var p = m.selling_price, c = m.cost_price, mg = p - c;
        var cr  = (mg / p * 100).toFixed(1);
        var tot = m.total_predicted || m.optimal_total || 0;
        var strategy = parseFloat(cr) >= 70 ? 'High margin — over-stocking is cheaper than a lost sale. Order aggressively.'
            : parseFloat(cr) >= 40          ? 'Balanced margin — order near expected demand.'
            :                                  'Tight margin — cost of unsold stock is high. Order conservatively.';
        function row(lbl, val) {
            return '<div class="cm-nv-row"><span class="cm-nv-label">' + lbl + '</span><span class="cm-nv-val">' + val + '</span></div>';
        }
        var low = m.total_std != null ? Math.max(0, Math.round(tot - 1.96 * m.total_std)) : null;
        var hi  = m.total_std != null ? Math.round(tot + 1.96 * m.total_std) : null;
        body.innerHTML =
              row('Price / Cost',     '₱' + p.toFixed(2) + ' selling &nbsp;·&nbsp; ₱' + c.toFixed(2) + ' cost &nbsp;·&nbsp; ₱' + mg.toFixed(2) + ' margin (' + cr + '%)')
            + row('Critical ratio',   '<strong>' + cr + '%</strong> — ' + strategy)
            + row('Under-stock cost', '₱' + mg.toFixed(2) + ' per unit — profit lost when you run out of stock')
            + row('Over-stock cost',  '₱' + c.toFixed(2)  + ' per unit — money tied up in unsold inventory')
            + (low != null ? row('Demand range (95%)', low + ' – ' + hi + ' units &nbsp;·&nbsp; avg ' + Math.round(tot) + ' units &nbsp;·&nbsp; σ = ' + Math.round(m.total_std) + ' units') : '')
            + row('Optimal supply',   m.optimal_total + ' units total &nbsp;·&nbsp; ' + (m.current_stock || 0) + ' on hand + <strong>' + m.restock_qty + ' to order</strong>');
    }

    function _destroyCharts() {
        if (_chart) { _chart.destroy(); _chart = null; }
    }

    // ── public helpers for save flow ──────────────────────────────────────────
    function setSaveBtnState(disabled, text) {
        var btn = document.getElementById('cm-save-btn');
        if (!btn) return;
        btn.disabled    = disabled;
        btn.textContent = text;
    }

    function showSaveMsg(type, html) {
        var el = document.getElementById('cm-save-msg');
        if (!el) return;
        el.className     = 'cm-msg cm-msg-' + type;
        el.innerHTML     = html;
        el.style.display = '';
    }

    return {
        open:            open,
        openLoading:     openLoading,
        showResults:     showResults,
        close:           close,
        renderIn:        renderIn,
        destroyIn:       destroyIn,
        setSaveBtnState: setSaveBtnState,
        showSaveMsg:     showSaveMsg,
    };
})();
