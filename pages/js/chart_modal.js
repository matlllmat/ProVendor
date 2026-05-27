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
    // ── Per-product input persistence (session-scoped) ───────────────────────
    // Keyed by cfg.productId. Cleared on logout by pvLogout() in csrf.js.
    var _PI_KEY = 'provendor_pi_v1';
    function _piGet(pid) {
        if (!pid) return {};
        try { var all = JSON.parse(sessionStorage.getItem(_PI_KEY) || '{}'); return all[pid] || {}; }
        catch (e) { return {}; }
    }
    function _piSet(pid, partial) {
        if (!pid) return;
        try {
            var all = JSON.parse(sessionStorage.getItem(_PI_KEY) || '{}');
            all[pid] = Object.assign(all[pid] || {}, partial);
            sessionStorage.setItem(_PI_KEY, JSON.stringify(all));
        } catch (e) {}
    }

    // ── Forecast accuracy chip ───────────────────────────────────────────────
    // Hits api/run_product_accuracy.php which serves a cached value when fresh,
    // or fires a fresh backtest via Flask. The chip renders a single user-
    // friendly number: "Historical accuracy: 82% — tested on the last 30 days."
    // The endpoint also caches `residual_rho` on the product row, which
    // run_optimize.php reads back when computing the Newsvendor recommendation.
    // Builds the refresh-button HTML used inside the chip. Adjacent button so
    // the click handler can re-call _loadAccuracyChip(cfg, true) to force a
    // fresh backtest, bypassing the cache.
    function _refreshBtnHTML() {
        return ' <button type="button" class="cm-accuracy-refresh" title="Re-run backtest now">'
             +     '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">'
             +         '<polyline points="23 4 23 10 17 10"/>'
             +         '<polyline points="1 20 1 14 7 14"/>'
             +         '<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>'
             +     '</svg>'
             + '</button>';
    }

    // Hides the bottom metrics panel — used when accuracy isn't applicable
    // (no productId, reports flow, insufficient history, fetch failure).
    function _hideMetricsPanel() {
        var panel = document.getElementById('cm-metrics-panel');
        if (panel) panel.style.display = 'none';
    }

    // Fills the three metric cards from the same payload that drives the chip.
    function _renderMetricsPanel(data) {
        var panel = document.getElementById('cm-metrics-panel');
        if (!panel) return;
        panel.style.display = '';

        var fmtPct  = function (v) { return v != null ? v.toFixed(1) + '%'    : '—'; };
        var fmtUnit = function (v) { return v != null ? v.toFixed(1)           : '—'; };

        var mapeEl = document.getElementById('cm-metric-mape');
        var maeEl  = document.getElementById('cm-metric-mae');
        var rmseEl = document.getElementById('cm-metric-rmse');
        if (mapeEl) mapeEl.textContent = fmtPct(data.mape);
        if (maeEl)  maeEl.textContent  = fmtUnit(data.mae);
        if (rmseEl) rmseEl.textContent = fmtUnit(data.rmse);

        var footer = document.getElementById('cm-metrics-footer');
        if (footer) {
            var bits = [];
            if (data.horizon_days)     bits.push('Tested on ' + data.horizon_days + ' days of held-out sales.');
            if (data.residual_rho != null) bits.push('Residual ρ = ' + data.residual_rho.toFixed(2) + ' (drives Newsvendor σ correction).');
            footer.textContent = bits.join(' ');
        }
    }

    function _loadAccuracyChip(cfg, forceRefresh) {
        var chip = document.getElementById('cm-accuracy-chip');
        if (!chip) return;
        if (!cfg.productId || !cfg.accuracyBase) {
            chip.style.display = 'none';
            _hideMetricsPanel();
            return;
        }

        chip.style.display = '';
        chip.className     = 'cm-accuracy-chip cm-accuracy-loading';
        chip.textContent   = forceRefresh ? 'Refreshing accuracy…' : 'Checking historical accuracy…';

        var body = new FormData();
        body.append('product_id', cfg.productId);
        if (forceRefresh) body.append('refresh', '1');

        fetch(cfg.accuracyBase + '/api/run_product_accuracy.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    // Insufficient history is normal for new products — show a soft note.
                    chip.className = 'cm-accuracy-chip cm-accuracy-note';
                    chip.innerHTML = _escHtml(data.error) + _refreshBtnHTML();
                    _wireRefreshBtn(chip, cfg);
                    _hideMetricsPanel();
                    return;
                }
                var pct     = Math.round(data.accuracy_pct);
                var horizon = data.horizon_days || 0;
                var tone    = pct >= 80 ? 'good' : pct >= 60 ? 'okay' : 'low';
                chip.className = 'cm-accuracy-chip cm-accuracy-' + tone;
                chip.innerHTML =
                      '<strong>Historical accuracy: ' + pct + '%</strong>'
                    + ' &nbsp;·&nbsp; tested on the last ' + horizon + ' days of real sales'
                    + _refreshBtnHTML();
                chip.title = 'MAPE-based: 100 − mean absolute % error on a held-out window.\n'
                           + 'MAE = ' + (data.mae != null ? Math.round(data.mae) : '—') + ' units/day (average miss).\n'
                           + 'RMSE = ' + (data.rmse != null ? Math.round(data.rmse) : '—') + ' units/day (penalizes large misses).\n'
                           + 'Residual ρ = ' + (data.residual_rho != null ? data.residual_rho.toFixed(2) : '0')
                           + ' (used to widen σ in the Newsvendor model).';
                _wireRefreshBtn(chip, cfg);
                _renderMetricsPanel(data);
            })
            .catch(function () {
                chip.className = 'cm-accuracy-chip cm-accuracy-note';
                chip.innerHTML = 'Accuracy check unavailable.' + _refreshBtnHTML();
                _wireRefreshBtn(chip, cfg);
                _hideMetricsPanel();
            });
    }

    function _wireRefreshBtn(chip, cfg) {
        var btn = chip.querySelector('.cm-accuracy-refresh');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            _loadAccuracyChip(cfg, true);
        });
    }

    // Minimal HTML escape — only used for error strings from the server so an
    // unexpected payload can't break out of the chip markup.
    function _escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function _hydrateRestockForm(cfg) {
        if (!cfg.productId) return;
        var saved   = _piGet(cfg.productId);
        var costEl  = document.getElementById('cm-rs-cost');
        var priceEl = document.getElementById('cm-rs-price');
        var stockEl = document.getElementById('cm-rs-stock');

        // Pre-fill anything saved (input may not exist if locked by stored product price).
        if (costEl  && saved.cost  != null && saved.cost  !== '') costEl.value  = saved.cost;
        if (priceEl && saved.price != null && saved.price !== '') priceEl.value = saved.price;
        if (stockEl && saved.stock != null && saved.stock !== '') stockEl.value = saved.stock;

        // Save on every keystroke so closing the modal at any point preserves state.
        if (costEl)  costEl.addEventListener('input',  function () { _piSet(cfg.productId, { cost:  costEl.value  }); });
        if (priceEl) priceEl.addEventListener('input', function () { _piSet(cfg.productId, { price: priceEl.value }); });
        if (stockEl) stockEl.addEventListener('input', function () { _piSet(cfg.productId, { stock: stockEl.value }); });
    }


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
        fcComponents:       {},  // normalized-date → component breakdown (used by daily view + reasoning panel)
        fcBucketComponents: {},  // bar-chart dataIndex → aggregated component breakdown for the bucket
        showOptimal:        false, // toggled by the Newsvendor chart overlay button (only after restock generates)
    };

    // Sums Prophet component values for a single forecast day into a bar-chart bucket.
    function _aggregateInto(map, idx, comp) {
        if (!comp) return;
        if (!map[idx]) map[idx] = { trend: 0, weekly: 0, yearly: 0, events: [] };
        var agg = map[idx];
        agg.trend  += comp.trend  || 0;
        agg.weekly += comp.weekly || 0;
        agg.yearly += comp.yearly || 0;
        (comp.events || []).forEach(function (ev) {
            var existing = null;
            for (var i = 0; i < agg.events.length; i++) {
                if (agg.events[i].id === ev.id) { existing = agg.events[i]; break; }
            }
            if (existing) existing.value += ev.value;
            else          agg.events.push({ id: ev.id, name: ev.name, value: ev.value });
        });
    }

    // ── modal DOM (created once, reused) ──────────────────────────────────────
    var _MODAL_HTML =
        '<div id="cm-modal" class="fixed inset-0 z-[1000] flex items-center justify-center hidden" role="dialog" aria-modal="true">' +
            '<div id="cm-backdrop" class="absolute inset-0" style="background:rgba(38,31,14,0.55)"></div>' +
            '<div class="cm-card">' +
                '<div class="cm-header">' +
                    '<div style="min-width:0">' +
                        '<p id="cm-label" class="cm-label"></p>' +
                        '<h2 id="cm-title" class="cm-title">—</h2>' +
                        // Accuracy chip — populated by _loadAccuracyChip(cfg)
                        // once the backtest result comes back from the server.
                        '<div id="cm-accuracy-chip" class="cm-accuracy-chip" style="display:none"></div>' +
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
        var dbCost  = cfg.productCostPrice    != null && cfg.productCostPrice    > 0 ? cfg.productCostPrice    : null;
        var dbPrice = cfg.productSellingPrice != null && cfg.productSellingPrice > 0 ? cfg.productSellingPrice : null;

        // Always renders an editable input. When the product has a price in the DB,
        // the DB value is pre-filled as the default — the user can override it for
        // this forecast run without affecting the stored record.
        function priceInput(id, label, defaultValue) {
            var valAttr = defaultValue != null ? ' value="' + defaultValue.toFixed(2) + '"' : '';
            var hint    = defaultValue != null
                ? '<p class="cm-rs-hint">Default from product profile — edit to override for this run</p>'
                : '';
            return '<div class="cm-rs-field">' +
                '<label class="cm-rs-label" for="' + id + '">' + label + '</label>' +
                '<div class="cm-rs-input-wrap">' +
                    '<span class="cm-rs-input-affix">₱</span>' +
                    '<input type="number" id="' + id + '" class="cm-rs-input" min="0" step="0.01" placeholder="0.00"' + valAttr + '>' +
                '</div>' +
                hint +
            '</div>';
        }

        var costField  = priceInput('cm-rs-cost',  'Cost Price',    dbCost);
        var priceField = priceInput('cm-rs-price', 'Selling Price', dbPrice);
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
                        '<span id="cm-nv-legend" class="cm-legend-item" style="display:none">' +
                            '<svg width="18" height="10" viewBox="0 0 18 10"><line x1="0" y1="5" x2="18" y2="5" stroke="#FF1493" stroke-width="2.5" stroke-dasharray="3 3"/></svg>' +
                            ' Newsvendor Order ' +
                            '<span class="cm-legend-info" data-tip="Prophet&rsquo;s forecast scaled to the Newsvendor-optimal order quantity for your margin. Above the forecast = order extra (safety stock); below = order less (accept some stockout risk).">ⓘ</span>' +
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
                        '<button id="cm-nv-overlay-btn" class="cm-toggle-btn cm-nv-toggle" style="display:none" title="Overlay the Newsvendor-recommended order quantity on the chart">' +
                            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                                '<circle cx="12" cy="12" r="10"/>' +
                                '<circle cx="12" cy="12" r="6"/>' +
                                '<circle cx="12" cy="12" r="2"/>' +
                            '</svg>' +
                            ' Newsvendor' +
                        '</button>' +
                        '<button id="cm-zoom-btn" class="cm-ghost-btn">' +
                            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                            ' Reset Zoom' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="cm-year-sel" class="cm-year-selector"></div>' +
                '<div id="cm-nv-banner" class="cm-nv-banner" style="display:none">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                        '<circle cx="12" cy="12" r="10"/>' +
                        '<circle cx="12" cy="12" r="6"/>' +
                        '<circle cx="12" cy="12" r="2"/>' +
                    '</svg>' +
                    '<span><strong>Newsvendor view active</strong> — green line/bars show Prophet&rsquo;s forecast scaled to your optimal order quantity.</span>' +
                '</div>' +
                '<canvas id="cm-canvas" style="max-height:280px"></canvas>' +
            '</div>' +

            // Forecast reasoning — populated by _renderReasoning() from per-day components.
            '<div id="cm-reasoning" class="cm-reasoning" style="display:none"></div>' +

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

            // Forecast accuracy metrics — three standard error metrics computed
            // by /forecast/product/evaluate. Populated by _loadAccuracyChip()
            // (same fetch as the header chip). Hidden until data arrives.
            '<div id="cm-metrics-panel" class="cm-metrics-panel" style="display:none">' +
                '<div class="cm-metrics-header">' +
                    '<p class="cm-metrics-eyebrow">Forecast Accuracy</p>' +
                    '<h3 class="cm-metrics-title">How well does this model predict for this product?</h3>' +
                    '<p class="cm-metrics-sub">Three standard error metrics, computed on a held-out window of the most recent sales. ' +
                       'These tell different stories — read all three together rather than picking one.</p>' +
                '</div>' +
                '<div class="cm-metrics-grid">' +
                    '<div class="cm-metric-card">' +
                        '<p class="cm-metric-label">MAPE</p>' +
                        '<p class="cm-metric-value" id="cm-metric-mape">—</p>' +
                        '<p class="cm-metric-unit">average % error</p>' +
                        '<p class="cm-metric-explain">' +
                            '<strong>Mean Absolute Percentage Error.</strong> How far off the forecast is on average, ' +
                            'as a percentage of actual sales. <strong>Lower is better.</strong>' +
                        '</p>' +
                        '<ul class="cm-metric-scale">' +
                            '<li><span class="cm-metric-tag cm-tag-good">under&nbsp;15%</span> excellent — trust the model</li>' +
                            '<li><span class="cm-metric-tag cm-tag-ok">15&ndash;30%</span> typical for retail demand</li>' +
                            '<li><span class="cm-metric-tag cm-tag-bad">over&nbsp;40%</span> model is struggling for this product</li>' +
                        '</ul>' +
                        '<p class="cm-metric-hint">Avoid for slow-moving items &mdash; a single zero-sale day inflates the average.</p>' +
                    '</div>' +
                    '<div class="cm-metric-card">' +
                        '<p class="cm-metric-label">MAE</p>' +
                        '<p class="cm-metric-value" id="cm-metric-mae">—</p>' +
                        '<p class="cm-metric-unit">units/day off</p>' +
                        '<p class="cm-metric-explain">' +
                            '<strong>Mean Absolute Error.</strong> Average number of units the forecast missed by, in ' +
                            'the same scale as your sales. <strong>Compare it to your typical daily volume</strong> &mdash; ' +
                            'the same MAE means different things at different scales.' +
                        '</p>' +
                        '<ul class="cm-metric-scale">' +
                            '<li><span class="cm-metric-tag cm-tag-good">MAE&nbsp;5 on 50/day</span> ~10% miss — good</li>' +
                            '<li><span class="cm-metric-tag cm-tag-bad">MAE&nbsp;5 on 8/day</span> ~60% miss — bad</li>' +
                        '</ul>' +
                        '<p class="cm-metric-hint">More trustworthy than MAPE on low-volume products with lots of zero-sale days.</p>' +
                    '</div>' +
                    '<div class="cm-metric-card">' +
                        '<p class="cm-metric-label">RMSE</p>' +
                        '<p class="cm-metric-value" id="cm-metric-rmse">—</p>' +
                        '<p class="cm-metric-unit">units/day off</p>' +
                        '<p class="cm-metric-explain">' +
                            '<strong>Root Mean Square Error.</strong> Same units as MAE, but it <strong>punishes ' +
                            'big single-day misses harder</strong>. Always read it next to MAE.' +
                        '</p>' +
                        '<ul class="cm-metric-scale">' +
                            '<li><span class="cm-metric-tag cm-tag-good">RMSE&nbsp;≈&nbsp;MAE</span> errors are small &amp; consistent</li>' +
                            '<li><span class="cm-metric-tag cm-tag-bad">RMSE&nbsp;»&nbsp;MAE</span> occasional huge misses — model has blind spots</li>' +
                        '</ul>' +
                        '<p class="cm-metric-hint">A big gap usually means the model is missing an event or sudden trend shift.</p>' +
                    '</div>' +
                '</div>' +
                '<p id="cm-metrics-footer" class="cm-metrics-footer"></p>' +
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

        var nvOverlayBtn = document.getElementById('cm-nv-overlay-btn');
        if (nvOverlayBtn) nvOverlayBtn.addEventListener('click', _toggleNvOverlay);

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
        var costInput  = document.getElementById('cm-rs-cost');
        var priceInput = document.getElementById('cm-rs-price');
        var stockInput = document.getElementById('cm-rs-stock');
        var errEl      = document.getElementById('cm-restock-error');
        var btn        = document.getElementById('cm-restock-btn');

        var cost  = costInput  ? parseFloat(costInput.value)  || 0 : 0;
        var price = priceInput ? parseFloat(priceInput.value) || 0 : 0;
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

        // Reveal the Newsvendor chart overlay toggle and turn it on by default —
        // the user just generated this number, surface it on the chart immediately.
        _st.showOptimal = true;
        var nvBtn = document.getElementById('cm-nv-overlay-btn');
        if (nvBtn) {
            nvBtn.style.display = '';
            nvBtn.classList.add('cm-btn-on');
        }
        _applyNvBannerVisibility();
        _renderView();

        // Enable save now that we have something to save.
        var saveBtn = document.getElementById('cm-save-btn');
        if (saveBtn) {
            saveBtn.disabled    = false;
            saveBtn.style.opacity = '';
            saveBtn.style.cursor  = '';
        }
    }

    // ── Newsvendor overlay toggle ────────────────────────────────────────────
    function _toggleNvOverlay() {
        if (!_st.meta) return;             // shouldn't happen — button is hidden until restock generates
        _st.showOptimal = !_st.showOptimal;
        var btn = document.getElementById('cm-nv-overlay-btn');
        if (btn) btn.classList.toggle('cm-btn-on', _st.showOptimal);
        _applyNvBannerVisibility();
        _renderView();
    }

    function _applyNvBannerVisibility() {
        var banner = document.getElementById('cm-nv-banner');
        var legend = document.getElementById('cm-nv-legend');
        var wrap   = document.querySelector('.cm-chart-wrap');
        var active = _st.showOptimal && _st.meta;
        if (banner) banner.style.display = active ? '' : 'none';
        if (legend) legend.style.display = active ? '' : 'none';
        if (wrap)   wrap.classList.toggle('cm-chart-wrap-nv-active', !!active);
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

        // Index per-day Prophet components by normalized date so the tooltip and
        // reasoning panel can look them up without rescanning the forecast array.
        _st.fcComponents = {};
        (cfg.forecast || []).forEach(function (r) {
            if (r.components) _st.fcComponents[_nd(r.date)] = r.components;
        });

        // Newsvendor overlay starts hidden — only shown once restock is generated.
        _st.showOptimal = false;

        container.innerHTML = _resultsHTML(cfg);
        _wire(cfg);
        _hydrateRestockForm(cfg);
        _loadAccuracyChip(cfg);

        var bandLegend = document.getElementById('cm-band-legend');
        if (bandLegend) bandLegend.style.display = cfg.hasBand ? '' : 'none';

        _renderView();
        _renderReasoning(cfg.forecast);

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
        hideChartTooltip();
        // Hide the accuracy chip so the next open doesn't briefly flash the
        // previous product's number before the new fetch resolves.
        var chip = document.getElementById('cm-accuracy-chip');
        if (chip) { chip.style.display = 'none'; chip.textContent = ''; chip.className = 'cm-accuracy-chip'; }
    }

    // ── public: inline rendering (forecast results panel) ────────────────────
    var _inlineContainer = null;

    function renderIn(container, cfg) {
        _inlineContainer = container;
        _render(container, cfg);
    }

    function destroyIn() {
        _destroyCharts();
        hideChartTooltip();
        if (_inlineContainer) { _inlineContainer.innerHTML = ''; _inlineContainer = null; }
    }

    // ── view dispatcher + control visibility ──────────────────────────────────
    function _renderView() {
        var cfg = _st.cfg;
        if (!cfg) return;

        if (_chart) { _chart.destroy(); _chart = null; }
        _st.fcBucketComponents = {};  // each view repopulates this for its own bucket scheme

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

        // Year pills make sense everywhere except the single-bar Yearly view.
        if (yearSel)   yearSel.style.display   = _st.view === 'yearly' ? 'none' : '';
        if (eventsGrp) eventsGrp.style.display = _st.view === 'daily'  ? '' : 'none';
        if (foBtn)     foBtn.style.display     = _st.view === 'daily'  ? '' : 'none';
        if (bandLeg)   bandLeg.style.display   = (_st.view === 'daily' && cfg.hasBand) ? '' : 'none';
        // Reset Zoom is useful in every view now — bar charts support zoom too.
        if (zoomBtn)   zoomBtn.style.display   = '';
    }

    // Custom HTML tooltip handler. The actual implementation lives in
    // chart.shared.js (externalChartTooltip) so the Demand Analysis chart on
    // the forecast page can reuse the exact same look.
    function _externalTooltip(context) {
        return externalChartTooltip(context, _st);
    }

    // ── normalize date to base year 2000 (year overlay) ──────────────────────
    function _nd(d) {
        if (!d) return null;
        var n = '2000' + d.slice(4);
        return n === '2000-02-29' ? '2000-02-28' : n;
    }

    // ── DAILY VIEW — year-overlaid line chart, matches Demand Analysis ──────
    // Each historical year is its own line on a shared Jan→Dec axis (year 2000
    // base). Forecast dates are normalized the same way, so the projected
    // line sits at the matching month-position of the year cycle.
    function _renderDailyView(historical, forecast, hasBand) {
        var byYear    = groupByYearNorm(historical);
        var years     = Object.keys(byYear).sort();
        var allActive = _st.activeYears.size === 0;

        var histDS = years.map(function (yr, i) {
            var c = YEAR_COLORS[i % YEAR_COLORS.length];
            return {
                label: yr, data: byYear[yr],
                hidden: !(allActive || _st.activeYears.has(yr)),
                borderColor: c, backgroundColor: 'transparent',
                borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 3,
                fill: false, tension: 0.3,
            };
        });

        _st.fcStart = forecast.length ? _nd(forecast[0].date) : null;
        var fcDS = [];
        if (hasBand) {
            fcDS.push({ label: '_upper', data: forecast.map(function (r) { return { x: _nd(r.date), y: r.upper }; }), borderColor: 'transparent', backgroundColor: 'rgba(255,87,34,0.13)', borderWidth: 0, pointRadius: 0, fill: '+1', tension: 0.3 });
            fcDS.push({ label: '_lower', data: forecast.map(function (r) { return { x: _nd(r.date), y: r.lower }; }), borderColor: 'transparent', borderWidth: 0, pointRadius: 0, fill: false, tension: 0.3 });
        }
        fcDS.push({ label: 'Projected Demand', data: forecast.map(function (r) { return { x: _nd(r.date), y: r.predicted }; }), borderColor: '#FF5722', borderWidth: 3, borderDash: [6, 3], backgroundColor: 'transparent', pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.3 });

        // Newsvendor overlay — Prophet's forecast scaled to the optimal order total.
        if (_st.showOptimal && _st.meta && _st.meta.total_predicted > 0) {
            var scale = _st.meta.optimal_total / _st.meta.total_predicted;
            fcDS.push({
                label: 'Newsvendor Order',
                data: forecast.map(function (r) { return { x: _nd(r.date), y: r.predicted * scale }; }),
                borderColor: '#FF1493', borderWidth: 3, borderDash: [3, 3],
                backgroundColor: 'transparent',
                pointRadius: 0, pointHoverRadius: 4,
                fill: false, tension: 0.3,
            });
        }

        var datasets = histDS.concat(fcDS);

        var minN = '2000-12-31', maxN = '2000-01-01';
        datasets.forEach(function (ds) {
            if (ds.label === '_upper' || ds.label === '_lower') return;
            ds.data.forEach(function (p) {
                if (p.x && p.x < minN) minN = p.x;
                if (p.x && p.x > maxN) maxN = p.x;
            });
        });
        var PAD  = 3 * 86400000;
        var minT = new Date(minN).getTime() - PAD;
        var maxT = new Date(maxN).getTime() + PAD;

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
                        enabled: false,
                        external: _externalTooltip,
                    },
                    annotation: { annotations: ann },
                },
                scales: {
                    x: {
                        type: 'time', min: minT, max: maxT,
                        time: { minUnit: 'day', tooltipFormat: 'MMM d', displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM', year: 'MMM' } },
                        ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 }, maxTicksLimit: 10, maxRotation: 0 },
                        grid: { color: 'rgba(38,31,14,0.06)' },
                    },
                    y: { beginAtZero: true, ticks: { color: 'rgba(38,31,14,0.45)', font: { family: 'Lora', size: 11 } }, grid: { color: 'rgba(38,31,14,0.06)' } },
                },
            },
        });

        _buildYearPills(years);
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

        // Use null for buckets the year never reported in — Chart.js won't draw a
        // bar AND the tooltip will skip the line, so empty weeks for one year
        // don't crowd the hover or leave visual ghosts.
        var histDS = years.map(function (yr, i) {
            var c = YEAR_COLORS[i % YEAR_COLORS.length];
            var data = Array.from({ length: maxWeek }, function (_, w) {
                var v = byYear[yr][w + 1];
                return v !== undefined ? v : null;
            });
            return { label: yr, data: data, hidden: !(allActive || _st.activeYears.has(yr)),
                backgroundColor: hexToRgba(c, 0.75), borderColor: c, borderWidth: 1, borderRadius: 2,
                categoryPercentage: 0.85, barPercentage: 0.92 };
        });

        var fcByWeek = {};
        forecast.forEach(function (r) {
            var wk = weekOfYear(r.date);
            fcByWeek[wk] = (fcByWeek[wk] || 0) + r.predicted;
            // Aggregate Prophet components for this bar (dataIndex = wk - 1).
            _aggregateInto(_st.fcBucketComponents, wk - 1, r.components);
        });
        var fcData = Array.from({ length: maxWeek }, function (_, w) {
            var v = fcByWeek[w + 1];
            return v !== undefined ? v : null;
        });
        var fcDS = { label: 'Forecast', data: fcData, backgroundColor: 'rgba(255,87,34,0.78)', borderColor: '#FF5722', borderWidth: 1, borderRadius: 2,
            categoryPercentage: 0.85, barPercentage: 0.92 };

        var allDS = histDS.concat([fcDS]);

        if (_st.showOptimal && _st.meta && _st.meta.total_predicted > 0) {
            var scale = _st.meta.optimal_total / _st.meta.total_predicted;
            allDS.push({
                label: 'Newsvendor Order',
                data: fcData.map(function (v) { return v == null ? null : v * scale; }),
                backgroundColor: 'rgba(255,20,147,0.85)', borderColor: '#FF1493',
                borderWidth: 1, borderRadius: 2,
                categoryPercentage: 0.85, barPercentage: 0.92,
            });
        }

        _chart = _barChart(labels, allDS, function (items) { return items.length ? 'Week ' + (items[0].dataIndex + 1) : ''; });
        _buildYearPills(years);
    }

    // ── MONTHLY VIEW — bar chart, Jan–Dec grouped by year ─────────────────────
    function _renderMonthlyView(historical, forecast) {
        var MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        // Track which (year, month) pairs actually have data so months a year never
        // reported in stay null (no bar drawn, no tooltip line).
        var byYear = {};
        historical.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            var m  = parseInt(r.date.slice(5, 7), 10) - 1;
            if (!byYear[yr]) byYear[yr] = new Array(12).fill(null);
            byYear[yr][m] = (byYear[yr][m] || 0) + r.actual;
        });

        var years     = Object.keys(byYear).sort();
        var allActive = _st.activeYears.size === 0;

        var histDS = years.map(function (yr, i) {
            var c = YEAR_COLORS[i % YEAR_COLORS.length];
            return { label: yr, data: byYear[yr], hidden: !(allActive || _st.activeYears.has(yr)),
                backgroundColor: hexToRgba(c, 0.75), borderColor: c, borderWidth: 1, borderRadius: 4,
                categoryPercentage: 0.8, barPercentage: 0.9 };
        });

        var fcByMonth = new Array(12).fill(null);
        forecast.forEach(function (r) {
            var m = parseInt(r.date.slice(5, 7), 10) - 1;
            fcByMonth[m] = (fcByMonth[m] || 0) + r.predicted;
            // Aggregate Prophet components for this month (dataIndex = m).
            _aggregateInto(_st.fcBucketComponents, m, r.components);
        });
        var fcData = fcByMonth;
        var fcDS = { label: 'Forecast', data: fcData, backgroundColor: 'rgba(255,87,34,0.78)', borderColor: '#FF5722', borderWidth: 1, borderRadius: 4,
            categoryPercentage: 0.8, barPercentage: 0.9 };

        var allDS = histDS.concat([fcDS]);

        if (_st.showOptimal && _st.meta && _st.meta.total_predicted > 0) {
            var scale = _st.meta.optimal_total / _st.meta.total_predicted;
            allDS.push({
                label: 'Newsvendor Order',
                data: fcData.map(function (v) { return v == null ? null : v * scale; }),
                backgroundColor: 'rgba(255,20,147,0.85)', borderColor: '#FF1493',
                borderWidth: 1, borderRadius: 4,
                categoryPercentage: 0.8, barPercentage: 0.9,
            });
        }

        _chart = _barChart(MONTH_LABELS, allDS);
        _buildYearPills(years);
    }

    // ── YEARLY VIEW — single bar per year + forecast year as extra bar ───────
    function _renderYearlyView(historical, forecast) {
        var totals = {};
        historical.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            totals[yr] = (totals[yr] || 0) + r.actual;
        });

        var fcTotalsByYear  = {};
        var fcComponentsRows = []; // keep ref so we can aggregate after we know dataIndex
        forecast.forEach(function (r) {
            var yr = r.date.slice(0, 4);
            fcTotalsByYear[yr] = (fcTotalsByYear[yr] || 0) + r.predicted;
            fcComponentsRows.push(r);
        });

        // Merge label list (some forecast years may already exist in historical)
        var allYears = new Set(Object.keys(totals).concat(Object.keys(fcTotalsByYear)));
        var years    = Array.from(allYears).sort();
        var colors   = years.map(function (_, i) { return YEAR_COLORS[i % YEAR_COLORS.length]; });

        // Aggregate Prophet components into each year's bucket (dataIndex = position in years[]).
        fcComponentsRows.forEach(function (r) {
            var idx = years.indexOf(r.date.slice(0, 4));
            if (idx >= 0) _aggregateInto(_st.fcBucketComponents, idx, r.components);
        });

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

        var allDS = [histDS, fcDS];

        if (_st.showOptimal && _st.meta && _st.meta.total_predicted > 0) {
            var scale = _st.meta.optimal_total / _st.meta.total_predicted;
            allDS.push({
                label: 'Newsvendor Order',
                data: years.map(function (y) { return fcTotalsByYear[y] ? fcTotalsByYear[y] * scale : null; }),
                backgroundColor: 'rgba(255,20,147,0.85)', borderColor: '#FF1493',
                borderWidth: 1, borderRadius: 6,
                categoryPercentage: 0.7, barPercentage: 0.85,
            });
        }

        _chart = _barChart(years, allDS, function (items) { return items.length ? items[0].label : ''; });
    }

    // ── shared bar-chart factory ──────────────────────────────────────────────
    function _barChart(labels, datasets, titleCb) {
        return new Chart(document.getElementById('cm-canvas'), {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                // Without this, Chart.js reserves a slot in every category for
                // each dataset even when the value is null — leaving visible
                // gaps where one year had no data but another did.
                skipNull: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    zoom: {
                        zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
                        pan:  { enabled: true, mode: 'x' },
                    },
                    tooltip: {
                        enabled: false,
                        external: _externalTooltip,
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
        // Daily view uses the year-overlay axis (base year 2000) — match with normalize=true.
        ch.options.plugins.annotation.annotations = _st.eventsOn
            ? Object.assign(base, buildChartAnnotations(tsToDateStr(ch.scales.x.min), tsToDateStr(ch.scales.x.max), true, _st.disabledIds))
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

    // ── forecast reasoning panel ─────────────────────────────────────────────
    // Aggregates Prophet's per-day components into a "Why this forecast?" card.
    // Each prediction's yhat = trend + weekly + yearly + Σ event regressors —
    // summing each piece over the window tells the user what's driving the total.
    function _renderReasoning(forecast) {
        var container = document.getElementById('cm-reasoning');
        if (!container) return;
        if (!forecast || !forecast.length || !forecast[0] || !forecast[0].components) {
            container.style.display = 'none';
            return;
        }

        var total       = 0;
        var totalTrend  = 0, totalWeekly = 0, totalYearly = 0;
        var eventTotals = {};   // id → { name, total, days }

        forecast.forEach(function (r) {
            total += r.predicted || 0;
            if (!r.components) return;
            totalTrend  += r.components.trend  || 0;
            totalWeekly += r.components.weekly || 0;
            totalYearly += r.components.yearly || 0;
            (r.components.events || []).forEach(function (ev) {
                if (!eventTotals[ev.id]) eventTotals[ev.id] = { name: ev.name, total: 0, days: 0 };
                eventTotals[ev.id].total += ev.value;
                eventTotals[ev.id].days  += 1;
            });
        });

        var eventList   = Object.keys(eventTotals).map(function (k) { return eventTotals[k]; })
                              .sort(function (a, b) { return Math.abs(b.total) - Math.abs(a.total); });
        var totalEvents = eventList.reduce(function (s, e) { return s + e.total; }, 0);

        function signed(n)   { var r = Math.round(n); return (r >= 0 ? '+' : '') + r.toLocaleString(); }
        function unsigned(n) { return Math.round(n).toLocaleString(); }

        var html = ''
            + '<div class="cm-reasoning-header">'
                + '<div class="cm-reasoning-icon">'
                    + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">'
                        + '<circle cx="12" cy="12" r="10"/>'
                        + '<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>'
                        + '<line x1="12" y1="17" x2="12.01" y2="17"/>'
                    + '</svg>'
                + '</div>'
                + '<div class="cm-reasoning-header-text">'
                    + '<p class="cm-reasoning-eyebrow">Why this forecast?</p>'
                    + '<h3 class="cm-reasoning-title">What Prophet considered</h3>'
                    + '<p class="cm-reasoning-sub">Every prediction is the sum of these named pieces. Hover any forecast day on the chart to see its individual breakdown.</p>'
                + '</div>'
            + '</div>'
            + '<div class="cm-reasoning-cards">'
                + '<div class="cm-rsn-card" data-tone="brown">'
                    + '<p class="cm-rsn-value">' + unsigned(totalTrend) + '</p>'
                    + '<p class="cm-rsn-label">Baseline trend</p>'
                    + '<p class="cm-rsn-sub">long-term level</p>'
                + '</div>'
                + '<div class="cm-rsn-card" data-tone="blue">'
                    + '<p class="cm-rsn-value">' + signed(totalWeekly) + '</p>'
                    + '<p class="cm-rsn-label">Weekly effect</p>'
                    + '<p class="cm-rsn-sub">day-of-week net</p>'
                + '</div>'
                + '<div class="cm-rsn-card" data-tone="green">'
                    + '<p class="cm-rsn-value">' + signed(totalYearly) + '</p>'
                    + '<p class="cm-rsn-label">Yearly seasonality</p>'
                    + '<p class="cm-rsn-sub">month-of-year net</p>'
                + '</div>'
                + '<div class="cm-rsn-card" data-tone="orange">'
                    + '<p class="cm-rsn-value">' + signed(totalEvents) + '</p>'
                    + '<p class="cm-rsn-label">Your events</p>'
                    + '<p class="cm-rsn-sub">' + eventList.length + ' event' + (eventList.length === 1 ? '' : 's') + '</p>'
                + '</div>'
            + '</div>'
            + '<p class="cm-reasoning-equation"><strong>' + unsigned(total) + ' units total</strong> &nbsp;=&nbsp; '
                + unsigned(totalTrend) + ' baseline'
                + ' &nbsp;' + signed(totalWeekly).replace(/^([+\-])/, '$1 ').replace(/  /g, ' ') + ' weekly'
                + ' &nbsp;' + signed(totalYearly).replace(/^([+\-])/, '$1 ').replace(/  /g, ' ') + ' yearly'
                + ' &nbsp;' + signed(totalEvents).replace(/^([+\-])/, '$1 ').replace(/  /g, ' ') + ' events</p>';

        if (eventList.length) {
            html += '<div class="cm-reasoning-events">';
            html += '<p class="cm-reasoning-section-label">Event contributions across this window</p>';
            html += '<ul class="cm-rsn-event-list">';
            eventList.slice(0, 5).forEach(function (ev) {
                html += '<li>'
                    + '<span class="cm-rsn-event-name">' + ev.name + '</span>'
                    + '<span class="cm-rsn-event-days">' + ev.days + ' day' + (ev.days === 1 ? '' : 's') + '</span>'
                    + '<strong class="cm-rsn-event-val ' + (ev.total >= 0 ? 'is-pos' : 'is-neg') + '">' + signed(ev.total) + ' units</strong>'
                    + '</li>';
            });
            html += '</ul>';
            html += '</div>';
        }

        container.innerHTML   = html;
        container.style.display = '';
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

        // Disclosure block — explains the AR(1) correction status and the
        // residual independence assumption. Two cases:
        //   1. rho ≠ 0 → backtest detected day-to-day correlation; we widened σ.
        //   2. rho = 0 → no backtest yet (or zero observed); σ assumes
        //      independence and we say so plainly.
        var rho        = m.rho_used;
        var inflation  = m.std_inflation_factor;
        var noteHtml;
        if (rho != null && rho !== 0 && inflation != null && inflation !== 1) {
            var pctWider = Math.round((inflation - 1) * 100);
            noteHtml =
                '<strong>Extra safety buffer applied (+' + pctWider + '%).</strong> ' +
                'The standard Newsvendor formula assumes each day&rsquo;s demand is independent of the next. ' +
                'But when we backtested this product&rsquo;s forecasts, we noticed busy days come in <em>streaks</em> ' +
                '— typical for products driven by paydays, weekends, or events ' +
                '(lag-1 autocorrelation ρ = ' + rho.toFixed(2) + '). ' +
                'Independent-day math would understate how badly a run of mismatched days could line up, so we widened ' +
                'the uncertainty (σ) by <strong>' + pctWider + '%</strong> before computing your order. ' +
                '<strong>The recommended quantity already includes this buffer</strong> — you don&rsquo;t need to add anything on top.';
        } else {
            noteHtml =
                '<strong>No extra buffer needed — daily independence assumption.</strong> ' +
                'The Newsvendor formula assumes each day&rsquo;s demand is independent of the days around it. ' +
                'For this product, the backtest residuals didn&rsquo;t show meaningful day-to-day clustering, so no ' +
                'safety buffer was added on top of the standard math. ' +
                '<em>Caveat:</em> if you know this product spikes hard around predictable events (paydays, weekends, holidays) ' +
                'and busy days tend to come in runs, real-world stockout risk could be slightly higher than what the recommendation reflects.';
        }

        body.innerHTML =
              row('Price / Cost',     '₱' + p.toFixed(2) + ' selling &nbsp;·&nbsp; ₱' + c.toFixed(2) + ' cost &nbsp;·&nbsp; ₱' + mg.toFixed(2) + ' margin (' + cr + '%)')
            + row('Critical ratio',   '<strong>' + cr + '%</strong> — ' + strategy)
            + row('Under-stock cost', '₱' + mg.toFixed(2) + ' per unit — profit lost when you run out of stock')
            + row('Over-stock cost',  '₱' + c.toFixed(2)  + ' per unit — money tied up in unsold inventory')
            + (low != null ? row('Demand range (95%)', low + ' – ' + hi + ' units &nbsp;·&nbsp; avg ' + Math.round(tot) + ' units &nbsp;·&nbsp; σ = ' + Math.round(m.total_std) + ' units') : '')
            + row('Optimal supply',   m.optimal_total + ' units total &nbsp;·&nbsp; ' + (m.current_stock || 0) + ' on hand + <strong>' + m.restock_qty + ' to order</strong>')
            + '<div class="cm-nv-note">' + noteHtml + '</div>';
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
