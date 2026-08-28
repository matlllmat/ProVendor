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
        _st.infoAvail.accuracy = false;
        _updateInfoTabs();
    }

    // Fills the three metric cards from the same payload that drives the chip.
    function _renderMetricsPanel(data) {
        var panel = document.getElementById('cm-metrics-panel');
        if (!panel) return;

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

        _st.infoAvail.accuracy = true;
        _updateInfoTabs();
    }

    // Accuracy reporting can be switched off app-wide (PV_SHOW_ACCURACY, set from
    // SHOW_ACCURACY_FEATURES in config/bootstrap.php). Defaults to on so this
    // module still behaves normally if the flag was never emitted.
    function _accuracyEnabled() {
        return (typeof window.PV_SHOW_ACCURACY === 'undefined') ? true : !!window.PV_SHOW_ACCURACY;
    }

    function _loadAccuracyChip(cfg, forceRefresh) {
        var chip = document.getElementById('cm-accuracy-chip');
        if (!chip) return;
        // Hidden: skip the chip, the panel, and the backtest request entirely.
        if (!_accuracyEnabled()) {
            chip.style.display = 'none';
            _hideMetricsPanel();
            return;
        }
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
        refineOpen:   false,  // "Adjust price & stock" fold inside the Restock tab
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
        '<div id="cm-modal" class="fixed inset-0 z-[1200] flex items-center justify-center hidden" role="dialog" aria-modal="true">' +
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
        var againBtn  = cfg.onRunAgain ? '<button id="cm-run-again-btn" class="cm-ghost-btn-lg">← Run Again</button>' : '';
        var actionRow = cfg.onRunAgain
            ? '<div class="cm-action-row">' + againBtn + '</div>'
            : '';

        return '<div class="cm-chart-wrap">' +
                // The legend gets its own line ABOVE the controls: it is a read-out of
                // what the chart is drawing, not something you operate, so it belongs with
                // the chart rather than in among the buttons.
                '<div class="cm-legend">' +
                    '<span id="cm-hist-legend" class="cm-legend-item">' +
                        '<svg width="18" height="10" viewBox="0 0 18 10"><line x1="0" y1="5" x2="18" y2="5" stroke="#1A6933" stroke-width="2"/></svg>' +
                        ' Historical ' +
                        '<span class="cm-legend-info" data-tip="Actual units sold each day before this forecast.">i</span>' +
                    '</span>' +
                    '<span id="cm-pd-legend" class="cm-legend-item">' +
                        '<svg width="18" height="10" viewBox="0 0 18 10"><line x1="0" y1="5" x2="18" y2="5" stroke="#FF5722" stroke-width="2" stroke-dasharray="5 3"/></svg>' +
                        ' Projected Demand ' +
                        '<span class="cm-legend-info" data-tip="Units the model predicts will be needed each day. This drives the recommended order quantity.">i</span>' +
                    '</span>' +
                    '<span id="cm-band-legend" class="cm-legend-item">' +
                        '<span style="display:inline-block;width:18px;height:10px;border-radius:3px;background:rgba(255,87,34,0.2)"></span>' +
                        ' Confidence band ' +
                        '<span class="cm-legend-info" data-tip="The likely range around each day&rsquo;s projected demand. A wider band means more uncertainty.">i</span>' +
                    '</span>' +
                    '<span id="cm-nv-legend" class="cm-legend-item">' +
                        '<svg width="18" height="10" viewBox="0 0 18 10"><line x1="0" y1="5" x2="18" y2="5" stroke="#FF1493" stroke-width="2.5" stroke-dasharray="3 3"/></svg>' +
                        ' Newsvendor Order ' +
                        '<span class="cm-legend-info" data-tip="The forecast scaled to the Newsvendor-optimal order quantity for your margin.">i</span>' +
                    '</span>' +
                '</div>' +

                '<div class="cm-chart-controls">' +
                    // The controls line: the view tabs on the left (what you are looking
                    // AT) and the Options menu pinned to the far right for everything
                    // that merely refines the view.
                    '<div class="cm-view-tabs">' +
                        '<button type="button" class="cm-view-tab cm-view-tab-on" data-view="daily">Daily</button>' +
                        '<button type="button" class="cm-view-tab"                data-view="weekly">Weekly</button>' +
                        '<button type="button" class="cm-view-tab"                data-view="monthly">Monthly</button>' +
                        '<button type="button" class="cm-view-tab"                data-view="yearly">Yearly</button>' +
                    '</div>' +

                    // Right-hand action group. Reset zoom lives OUTSIDE the menu — it is
                    // an action, not a setting, and it is needed exactly when you are
                    // deep in a zoom and least want to go hunting through a popover.
                    // It stays put whether or not the chart is zoomed: a control that
                    // comes and goes is harder to rely on than one that is simply there.
                    '<div class="cm-chart-actions">' +
                    '<button type="button" id="cm-zoom-btn" class="cm-zoom-btn">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                        ' Reset zoom' +
                    '</button>' +

                    '<div class="cm-opt-wrap">' +
                        '<button type="button" id="cm-opt-btn" class="cm-opt-btn" aria-expanded="false">' +
                            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="8" cy="18" r="2" fill="currentColor" stroke="none"/></svg>' +
                            ' Options' +
                        '</button>' +

                        '<div id="cm-opt-panel" class="cm-opt-panel" hidden>' +
                            '<p class="cm-opt-title">Show on chart</p>' +
                            '<label class="cm-opt-row" id="cm-opt-row-history">' +
                                '<input type="checkbox" id="cm-opt-history">' +
                                '<span class="cm-opt-swatch is-history"></span>' +
                                '<span class="cm-opt-label">Historical years</span>' +
                            '</label>' +
                            '<label class="cm-opt-row">' +
                                '<input type="checkbox" id="cm-opt-forecast" checked>' +
                                '<span class="cm-opt-swatch is-forecast"></span>' +
                                '<span class="cm-opt-label">Forecast + band</span>' +
                            '</label>' +
                            '<label class="cm-opt-row" id="cm-opt-row-nv" hidden>' +
                                '<input type="checkbox" id="cm-opt-nv">' +
                                '<span class="cm-opt-swatch is-nv"></span>' +
                                '<span class="cm-opt-label">Newsvendor order</span>' +
                            '</label>' +
                            '<label class="cm-opt-row" id="cm-opt-row-events">' +
                                '<input type="checkbox" id="cm-opt-events">' +
                                '<span class="cm-opt-swatch is-events"></span>' +
                                '<span class="cm-opt-label">Event markers</span>' +
                                '<button type="button" id="cm-events-filter" class="cm-opt-sub" title="Choose which events">Filter</button>' +
                            '</label>' +

                            '<div class="cm-opt-sep"></div>' +
                            '<div id="cm-opt-extra"></div>' +
                        '</div>' +
                    '</div>' +
                    '</div>' +   // /.cm-chart-actions

                '</div>' +   // /.cm-chart-controls — must close here, or the canvas
                             // and #cm-info get absorbed into the control row and the
                             // detail tabs disappear whenever the chart is hidden.
                '<div id="cm-year-sel" class="cm-year-selector"></div>' +
                '<canvas id="cm-canvas" style="max-height:280px"></canvas>' +
            '</div>' +   // /.cm-chart-wrap — #cm-info is its SIBLING, not its child

            // ── Detail organizer: tabs, collapsed by default so the chart stays clean.
            //    Each tab reveals one panel; clicking the active tab collapses it. ──
            '<div id="cm-info" class="cm-info" style="display:none">' +
                '<div class="cm-info-tabs">' +
                    // The selected-product tag leads the row; the tabs follow on the right.
                    '<div id="cm-info-slot" class="cm-info-slot"></div>' +
                    '<div class="cm-info-tabgroup">' +
                        '<button type="button" class="cm-info-tab" data-tab="restock">' +
                            'Restock<span class="cm-info-caret" aria-hidden="true"></span>' +
                        '</button>' +
                        '<button type="button" class="cm-info-tab" data-tab="why">' +
                            'Why this forecast<span class="cm-info-caret" aria-hidden="true"></span>' +
                        '</button>' +
                        '<button type="button" class="cm-info-tab" data-tab="accuracy">' +
                            'Accuracy<span class="cm-info-caret" aria-hidden="true"></span>' +
                        '</button>' +
                    '</div>' +
                '</div>' +

            // Forecast reasoning — populated by _renderReasoning() from per-day components.
            '<div id="cm-reasoning" class="cm-reasoning cm-info-panel" data-panel="why" style="display:none"></div>' +

            // Restock insight — initial form, then results after submission.
            '<div id="cm-restock-section" class="cm-info-panel" data-panel="restock" style="display:none">' +
                _restockFormHTML(cfg) +
                '<div id="cm-restock-results" style="display:none">' +
                    '<div class="cm-stats-grid">' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Total Forecast</p><p id="cm-s-demand" class="cm-stat-value"></p><p class="cm-stat-sub">units predicted</p></div>' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Current Stock</p><p id="cm-s-stock" class="cm-stat-value"></p><p class="cm-stat-sub">units on hand</p></div>' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Order Qty</p><p id="cm-s-order" class="cm-stat-value cm-stat-accent"></p><p class="cm-stat-sub">units to order</p></div>' +
                        '<div class="cm-stat-card"><p class="cm-stat-label">Est. Profit</p><p id="cm-s-profit" class="cm-stat-value cm-stat-green"></p><p class="cm-stat-sub">at forecast demand</p></div>' +
                    '</div>' +
                    // Adjust cost / price / stock and recompute. Prices default from
                    // the product's saved values but are editable so the profit is
                    // accurate; the edit sticks (persisted by the forecast page).
                    // "Reset to imported price" restores the value the dataset provided.
                    '<div class="cm-refine">' +
                        '<button type="button" id="cm-refine-toggle" class="cm-refine-head">' +
                            '<span class="cm-refine-title">Adjust price &amp; stock</span>' +
                            '<svg id="cm-refine-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>' +
                        '</button>' +
                        '<div id="cm-refine-body" class="cm-refine-body" style="display:none">' +
                        '<div class="cm-refine-fields">' +
                            '<div class="cm-refine-field">' +
                                '<label class="cm-refine-label" for="cm-refine-cost">Cost Price</label>' +
                                '<div class="cm-rs-input-wrap"><span class="cm-rs-input-affix">₱</span>' +
                                    '<input type="number" id="cm-refine-cost" class="cm-rs-input" min="0" step="0.01" placeholder="0.00">' +
                                '</div>' +
                                '<p class="cm-refine-status" id="cm-refine-cost-status"></p>' +
                            '</div>' +
                            '<div class="cm-refine-field">' +
                                '<label class="cm-refine-label" for="cm-refine-price">Selling Price</label>' +
                                '<div class="cm-rs-input-wrap"><span class="cm-rs-input-affix">₱</span>' +
                                    '<input type="number" id="cm-refine-price" class="cm-rs-input" min="0" step="0.01" placeholder="0.00">' +
                                '</div>' +
                                '<p class="cm-refine-status" id="cm-refine-price-status"></p>' +
                            '</div>' +
                            '<div class="cm-refine-field">' +
                                '<label class="cm-refine-label" for="cm-refine-stock">Current stock</label>' +
                                '<div class="cm-rs-input-wrap">' +
                                    '<input type="number" id="cm-refine-stock" class="cm-rs-input" min="0" step="1" value="0">' +
                                    '<span class="cm-rs-input-affix cm-rs-input-affix-right">units</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="cm-refine-actions">' +
                            '<button type="button" id="cm-refine-btn" class="cm-ghost-btn-lg">Update order</button>' +
                            '<button type="button" id="cm-refine-reset" class="cm-refine-reset" style="display:none"></button>' +
                        '</div>' +
                        '<p id="cm-refine-msg" class="cm-msg" style="display:none;margin-top:0.5rem"></p>' +
                        '</div>' +   // /#cm-refine-body
                    '</div>' +
                    '<div class="cm-nv-section">' +
                        '<button id="cm-nv-toggle" class="cm-nv-header">' +
                            '<span class="cm-nv-title">Newsvendor Model — How this was calculated</span>' +
                            '<svg id="cm-nv-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>' +
                        '</button>' +
                        '<div id="cm-nv-body" class="cm-nv-body" style="display:none"></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +

            // Forecast accuracy metrics — populated by _loadAccuracyChip() (same fetch
            // as the header chip). Shown directly inside its tab (no inner collapse).
            '<div id="cm-metrics-panel" class="cm-metrics-panel cm-info-panel" data-panel="accuracy" style="display:none">' +
                    '<p class="cm-metrics-eyebrow">Forecast Accuracy</p>' +
                    '<h3 class="cm-metrics-title">How well does this model predict for this product?</h3>' +
                    '<p class="cm-metrics-sub">Three standard error metrics, computed on a held-out window of the most recent sales. ' +
                       'These tell different stories — read all three together rather than picking one.</p>' +
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
            '</div>' + // /cm-metrics-panel (accuracy tab)
            '</div>' + // /#cm-info

            actionRow;
    }

    // ── wire interactive elements ─────────────────────────────────────────────
    function _wire(cfg) {
        document.getElementById('cm-opt-events').addEventListener('change', _toggleEvents);
        var filterBtn = document.getElementById('cm-events-filter');
        if (filterBtn) {
            filterBtn.addEventListener('click', function (e) {
                // "Filter" only opens the event checklist. It must not switch the markers
                // on as a side effect: choosing WHICH events matter is a separate decision
                // from whether they are drawn, and flipping the checkbox for the owner
                // makes the control lie about its own state.
                // preventDefault() as well as stopPropagation() because this button sits
                // inside the checkbox's <label> — without it, the click can reach the
                // label and toggle the very checkbox we are avoiding.
                e.preventDefault();
                e.stopPropagation();
                toggleEventsChecklist(filterBtn, _st.disabledIds, function () { _applyAnnotations(); });
            });
        }
        document.getElementById('cm-opt-history').addEventListener('change', _toggleForecastOnly);
        document.getElementById('cm-opt-forecast').addEventListener('change', _toggleForecast);
        _wireOptionsMenu();
        document.getElementById('cm-zoom-btn').addEventListener('click', function () { if (_chart) _chart.resetZoom(); });

        var nvOverlayBtn = document.getElementById('cm-opt-nv');
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

        // Price/stock refine button (shown once restock results are visible)
        var refineBtn = document.getElementById('cm-refine-btn');
        if (refineBtn) refineBtn.addEventListener('click', function () { _refineStock(cfg); });

        // "Reset to imported price" — asks for confirmation with full details first.
        var resetBtn = document.getElementById('cm-refine-reset');
        if (resetBtn) resetBtn.addEventListener('click', function () { _confirmResetPricing(cfg); });

        // Recolour the cost/price fields (green = from data, orange = customized) live.
        ['cm-refine-cost', 'cm-refine-price'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', _updateRefinePriceStatus);
        });

        // Wire newsvendor toggle once results panel exists (it's there but hidden)
        var nvToggle = document.getElementById('cm-nv-toggle');
        if (nvToggle) nvToggle.addEventListener('click', _toggleNv);
        var refineToggle = document.getElementById('cm-refine-toggle');
        if (refineToggle) refineToggle.addEventListener('click', _toggleRefine);

        // Detail tabs (Restock / Why this forecast / Accuracy) — collapsed by default.
        document.querySelectorAll('#cm-info .cm-info-tab').forEach(function (btn) {
            btn.addEventListener('click', function () { _setInfoTab(btn.dataset.tab); });
        });

        var againBtn = document.getElementById('cm-run-again-btn');
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

        // Sync the refine inputs to the values this result used. The control only
        // works when the product has a valid margin — hide it otherwise.
        var refine   = document.querySelector('.cm-refine');
        var costInp  = document.getElementById('cm-refine-cost');
        var priceInp = document.getElementById('cm-refine-price');
        var stockInp = document.getElementById('cm-refine-stock');
        var priced   = meta.cost_price > 0 && meta.selling_price > meta.cost_price;
        if (refine)   refine.style.display = (priced && _st.cfg && _st.cfg.onGenerateRestock) ? '' : 'none';
        if (costInp)  costInp.value  = meta.cost_price    != null ? Number(meta.cost_price).toFixed(2)    : '';
        if (priceInp) priceInp.value = meta.selling_price != null ? Number(meta.selling_price).toFixed(2) : '';
        if (stockInp) stockInp.value = (meta.current_stock != null ? meta.current_stock : 0);
        var refineMsg = document.getElementById('cm-refine-msg');
        if (refineMsg) refineMsg.style.display = 'none';

        // "Reset to imported price" — shown only when the effective price differs
        // from the imported original (i.e. the owner has edited it).
        var resetBtn = document.getElementById('cm-refine-reset');
        if (resetBtn) {
            var oc = _st.cfg ? _st.cfg.productOrigCost  : null;
            var op = _st.cfg ? _st.cfg.productOrigPrice : null;
            var edited = (oc != null && op != null) &&
                (Math.abs((meta.cost_price || 0) - oc) > 0.005 || Math.abs((meta.selling_price || 0) - op) > 0.005);
            resetBtn.style.display = edited ? '' : 'none';
            if (edited) { resetBtn.disabled = false; resetBtn.textContent = 'Reset to imported (₱' + Number(op).toFixed(2) + ')'; }
        }

        // Colour the cost/price fields: green = matches imported data, orange = customized.
        _updateRefinePriceStatus();

        // Reveal the Newsvendor overlay toggle, but keep it OFF by default — the
        // default chart shows only the forecasted demand. The user turns on the
        // Newsvendor overlay from the button when they want to see it.
        _st.showOptimal = false;
        var nvRow = document.getElementById('cm-opt-row-nv');
        if (nvRow) nvRow.hidden = false;                       // only meaningful once a restock exists
        var nvBox = document.getElementById('cm-opt-nv');
        if (nvBox) nvBox.checked = false;
        _applyNvOverlayState();
        _renderView();
    }

    // ── Price / stock refine ──────────────────────────────────────────────────
    // Re-runs the Newsvendor model with the entered cost / selling price / stock,
    // then re-renders in place. The forecast page persists the edited price so it
    // sticks; the imported price stays recoverable via _resetPricing().
    function _refineStock(cfg) {
        if (!cfg || !cfg.onGenerateRestock) return;
        var costEl  = document.getElementById('cm-refine-cost');
        var priceEl = document.getElementById('cm-refine-price');
        var stockEl = document.getElementById('cm-refine-stock');
        var btn     = document.getElementById('cm-refine-btn');
        var msg     = document.getElementById('cm-refine-msg');

        var cost  = costEl  ? (parseFloat(costEl.value)  || 0) : 0;
        var price = priceEl ? (parseFloat(priceEl.value) || 0) : 0;
        var stock = stockEl ? (parseInt(stockEl.value, 10) || 0) : 0;

        function err(m) { if (msg) { msg.className = 'cm-msg cm-msg-error'; msg.textContent = m; msg.style.display = ''; } }
        if (cost  <= 0)    { err('Enter a cost price.'); return; }
        if (price <= cost) { err('Selling price must be greater than cost price.'); return; }

        if (btn) { btn.disabled = true; btn.textContent = 'Updating…'; }
        if (msg) msg.style.display = 'none';

        cfg.onGenerateRestock(
            { cost_price: cost, selling_price: price, current_stock: stock },
            function (result) {
                if (btn) { btn.disabled = false; btn.textContent = 'Update order'; }
                if (!result || result.error) {
                    err((result && result.error) || 'Could not update the order.');
                    return;
                }
                _showRestockResults(result);
            }
        );
    }

    // Restore the imported cost/price and re-run. Persisting the imported value back
    // to cost_price (via the forecast page) makes it equal orig again — i.e. reverts.
    function _resetPricing(cfg) {
        if (!cfg || cfg.productOrigCost == null || cfg.productOrigPrice == null) return;
        var costEl  = document.getElementById('cm-refine-cost');
        var priceEl = document.getElementById('cm-refine-price');
        if (costEl)  costEl.value  = Number(cfg.productOrigCost).toFixed(2);
        if (priceEl) priceEl.value = Number(cfg.productOrigPrice).toFixed(2);
        _refineStock(cfg);
    }

    // Ask before reverting — spell out exactly what the numbers become.
    function _confirmResetPricing(cfg) {
        if (!cfg || cfg.productOrigCost == null || cfg.productOrigPrice == null) return;
        var oc = Number(cfg.productOrigCost).toFixed(2);
        var op = Number(cfg.productOrigPrice).toFixed(2);
        var curC = _st.meta && _st.meta.cost_price    != null ? Number(_st.meta.cost_price).toFixed(2)    : oc;
        var curP = _st.meta && _st.meta.selling_price != null ? Number(_st.meta.selling_price).toFixed(2) : op;

        var msg = 'This puts this product back to the price from your imported data — '
            + 'cost ₱' + oc + ' and selling price ₱' + op + '. '
            + 'Your customized values (cost ₱' + curC + ', selling ₱' + curP + ') will be replaced, '
            + 'and the order quantity and estimated profit will be recalculated. You can edit again anytime.';

        if (typeof showConfirm === 'function') {
            showConfirm({
                title:        'Reset to imported price?',
                message:      msg,
                confirmText:  'Reset price',
                confirmStyle: 'warning',
                onConfirm:    function () { _resetPricing(cfg); },
            });
        } else {
            _resetPricing(cfg);
        }
    }

    // Colour a cost/selling field green when it matches the imported (database)
    // value and orange when it's been customized; keep a small caption in sync.
    function _priceFieldStatus(inputId, statusId, orig) {
        var inp = document.getElementById(inputId);
        var st  = document.getElementById(statusId);
        if (!inp) return;
        var wrap   = inp.closest('.cm-rs-input-wrap');
        var val    = parseFloat(inp.value);
        var hasOrig = orig != null && !isNaN(orig);
        var isOrig  = hasOrig && !isNaN(val) && Math.abs(val - orig) < 0.005;

        inp.classList.toggle('is-original', isOrig);
        inp.classList.toggle('is-custom', !isOrig);
        if (wrap) { wrap.classList.toggle('is-original', isOrig); wrap.classList.toggle('is-custom', !isOrig); }
        if (st) {
            st.textContent = isOrig ? 'From your data' : 'Customized';
            st.className    = 'cm-refine-status ' + (isOrig ? 'is-original' : 'is-custom');
        }
    }

    function _updateRefinePriceStatus() {
        var cfg = _st.cfg || {};
        _priceFieldStatus('cm-refine-cost',  'cm-refine-cost-status',  cfg.productOrigCost);
        _priceFieldStatus('cm-refine-price', 'cm-refine-price-status', cfg.productOrigPrice);
    }

    // ── Newsvendor overlay toggle ────────────────────────────────────────────
    function _toggleNvOverlay() {
        if (!_st.meta) return;             // shouldn't happen — button is hidden until restock generates
        _st.showOptimal = !_st.showOptimal;
        var box = document.getElementById('cm-opt-nv');
        if (box) box.checked = _st.showOptimal;
        _applyNvOverlayState();
        // Needs the rebuild: each view builder adds the Newsvendor dataset only when
        // the overlay is on. Zoom is carried across.
        _renderView(true);
    }

    // Shows/hides the Newsvendor legend item + chart tint based on whether the
    // overlay is active. (The old on-chart "Newsvendor view active" banner was
    // removed — the legend + toggle button state are enough.)
    function _applyNvOverlayState() {
        var legend = document.getElementById('cm-nv-legend');
        var wrap   = document.querySelector('.cm-chart-wrap');
        var active = _st.showOptimal && _st.meta;
        // Dimmed rather than hidden, so the legend row never changes shape.
        if (legend) legend.classList.toggle('is-off', !active);
        if (wrap)   wrap.classList.toggle('cm-chart-wrap-nv-active', !!active);
    }

    // ── detail tabs (collapsed by default) ───────────────────────────────────
    // Activates a tab / panel. Clicking the already-active tab collapses it
    // (name === current → null), so "nothing shown" is a valid state.
    function _setInfoTab(name) {
        var info = document.getElementById('cm-info');
        if (!info) return;
        if (_st.infoTab === name) name = null; // toggle collapse
        _st.infoTab = name;
        info.querySelectorAll('.cm-info-tab').forEach(function (b) {
            b.classList.toggle('cm-info-tab-on', b.dataset.tab === name);
        });
        info.querySelectorAll('.cm-info-panel').forEach(function (p) {
            p.style.display = (p.dataset.panel === name) ? '' : 'none';
        });
    }

    // Shows a tab button only when its content is available, hides the whole
    // organizer when nothing is available, and collapses the active tab if it
    // just became unavailable.
    function _updateInfoTabs() {
        var info = document.getElementById('cm-info');
        if (!info) return;
        var any = false;
        ['restock', 'why', 'accuracy'].forEach(function (name) {
            var avail = !!(_st.infoAvail && _st.infoAvail[name]);
            if (name === 'accuracy' && !_accuracyEnabled()) avail = false;
            if (avail) any = true;
            var btn = info.querySelector('.cm-info-tab[data-tab="' + name + '"]');
            if (btn) btn.style.display = avail ? '' : 'none';
        });
        info.style.display = any ? '' : 'none';
        if (_st.infoTab && !(_st.infoAvail && _st.infoAvail[_st.infoTab])) _setInfoTab(null);
    }

    // ── render results into a container ──────────────────────────────────────
    function _render(container, cfg) {
        _destroyCharts();
        _st.activeYears  = new Set();
        _st.forecastOnly = true;   // default: show only the projected line, not the year lines
        _st.eventsOn     = false;
        _st.fcStart      = null;
        _st.disabledIds  = cfg.disabledEventIds || new Set();
        _st.nvOpen       = false;
        _st.refineOpen   = false;   // the stat cards lead; editing folds behind them
        _st.view         = 'daily';
        _st.cfg          = cfg;
        _st.meta         = null;

        // Detail tabs start collapsed ("hidden first"); availability is filled in
        // below as each section's content is rendered.
        _st.infoTab   = null;
        _st.infoAvail = { restock: false, why: false, accuracy: false };

        // Index per-day Prophet components by normalized date so the tooltip and
        // reasoning panel can look them up without rescanning the forecast array.
        _st.fcComponents = {};
        (cfg.forecast || []).forEach(function (r) {
            if (r.components) _st.fcComponents[_nd(r.date)] = r.components;
        });

        // Newsvendor overlay starts hidden — only shown once restock is generated.
        _st.showOptimal = false;
        // Forecast line + band shown by default; its own toggle can hide it,
        // independent of the Newsvendor overlay.
        _st.showForecast = true;

        container.innerHTML = _resultsHTML(cfg);
        _wire(cfg);
        _hydrateRestockForm(cfg);
        _loadAccuracyChip(cfg);

        var bandLegend = document.getElementById('cm-band-legend');
        if (bandLegend) bandLegend.style.display = cfg.hasBand ? '' : 'none';

        _renderView();
        _renderReasoning(cfg.forecast);

        // The Restock tab is available when there's a saved restock (priced product)
        // or a way to generate one (the form). A view-only product with neither has
        // no Restock tab.
        _st.infoAvail.restock = !!(cfg.meta && cfg.meta.cost_price) || !!cfg.onGenerateRestock;

        // Priced products already have saved restock data — fill the results in
        // (still behind the collapsed Restock tab). Unpriced show the generate form.
        if (cfg.meta && cfg.meta.cost_price) {
            _showRestockResults(cfg.meta);
        }

        _updateInfoTabs();

        // Restock opens by default — the suggested order is the answer the owner came
        // for, so making them click to reach it buries the point of the page. Set here
        // rather than in the initial state because the tab only becomes available once
        // _st.infoAvail.restock is known, a few lines above.
        if (_st.infoAvail.restock) _setInfoTab('restock');
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
        _ensureModal(); // ensures #cm-accuracy-chip exists so _loadAccuracyChip can populate the metrics panel
        _inlineContainer = container;
        _render(container, cfg);
    }

    function destroyIn() {
        _destroyCharts();
        hideChartTooltip();
        if (_inlineContainer) { _inlineContainer.innerHTML = ''; _inlineContainer = null; }
    }

    // ── view dispatcher + control visibility ──────────────────────────────────
    // Rebuilds the chart from scratch. `preserveZoom` carries the owner's current
    // viewport across the rebuild — see _capturedRange() for when that applies.
    function _renderView(preserveZoom) {
        var cfg = _st.cfg;
        if (!cfg) return;

        var keep = preserveZoom ? _capturedRange() : null;

        if (_chart) { _chart.destroy(); _chart = null; }
        _st.fcBucketComponents = {};  // each view repopulates this for its own bucket scheme

        // Every Show-on-chart rebuild lands instantly, zoomed or not: it is only
        // changing which series are drawn, and re-animating that reads as a reload.
        // (The viewport carry-over below is narrower - it additionally requires that
        // the owner actually chose a viewport - so these two are not the same test.)
        _instantBuild = !!preserveZoom;
        try {
            switch (_st.view) {
                case 'weekly':  _renderWeeklyView(cfg.historical, cfg.forecast); break;
                case 'monthly': _renderMonthlyView(cfg.historical, cfg.forecast); break;
                case 'yearly':  _renderYearlyView(cfg.historical, cfg.forecast); break;
                default:        _renderDailyView(cfg.historical, cfg.forecast, cfg.hasBand); break;
            }
        } finally {
            _instantBuild = false;
        }

        _syncViewControls();
        _restoreRange(keep);
    }

    // Reflects the current toggle state onto whatever chart already exists: which
    // series are drawn, which controls apply to this view, and how the legend reads.
    // Deliberately does NOT rebuild, so anything that only changes visibility can
    // call this alone and leave the owner's zoom/pan untouched.
    function _syncViewControls() {
        var cfg = _st.cfg;
        if (!cfg) return;

        // Toggle controls relevant to the active view.
        var yearSel    = document.getElementById('cm-year-sel');
        var histRow    = document.getElementById('cm-opt-row-history');
        var bandLeg    = document.getElementById('cm-band-legend');

        // Year pills make sense everywhere except the single-bar Yearly view — and
        // are moot in forecast-only mode (all year series are hidden).
        if (yearSel)   yearSel.style.display   = (_st.view === 'yearly' || (_st.view === 'daily' && _st.forecastOnly)) ? 'none' : '';
        var evRow = document.getElementById('cm-opt-row-events');
        if (evRow) evRow.hidden = (_st.view !== 'daily');
        // Legend entries are never added or removed — an inactive series is just
        // faded. That keeps the row's geometry identical whatever is switched on,
        // and doubles as an at-a-glance read-out of what the chart is showing.
        // Function DECLARATION so it can be called from anywhere in this function.
        function dim(el, active) {
            if (!el) return;
            el.classList.toggle('is-off', !active);
        }

        // Historical year lines only exist in the daily view.
        if (histRow) histRow.hidden = (_st.view !== 'daily');
        var histBox = document.getElementById('cm-opt-history');
        if (histBox) histBox.checked = !_st.forecastOnly;

        dim(bandLeg, _st.view === 'daily' && cfg.hasBand && _st.showForecast);
        dim(document.getElementById('cm-hist-legend'), _st.view === 'daily' && !_st.forecastOnly);

        // ── Forecast line/band visibility — an independent toggle, like Newsvendor.
        // Hide the projected datasets by label (works across all view builders)
        // without disturbing the Newsvendor overlay or the historical lines.
        var FC_LABELS = { 'Projected Demand': 1, 'Projected (elapsed)': 1, 'Forecast': 1, 'Forecast portion': 1, '_upper': 1, '_lower': 1 };
        if (_chart) {
            _chart.data.datasets.forEach(function (ds, i) {
                if (FC_LABELS[ds.label]) _chart.setDatasetVisibility(i, _st.showForecast);
            });
            _chart.update('none');
        }
        dim(document.getElementById('cm-pd-legend'), _st.showForecast);
        var fcBox = document.getElementById('cm-opt-forecast');
        if (fcBox) fcBox.checked = _st.showForecast;

    }

    // True only while a rebuild triggered by a Show-on-chart toggle is running.
    var _instantBuild = false;

    // Chart.js replays its full 1s entry animation on every construction, so a
    // rebuild made the whole chart visibly redraw itself — which reads as "it reset"
    // even though the viewport never moved (measured: x stayed put frame for frame,
    // but every line swept back in over a second). A toggle should look like a series
    // appearing, not like the chart reloading, so toggle-driven rebuilds land instantly.
    // A genuinely fresh render — new product, new view — still animates.
    function _entryAnimation() {
        return _instantBuild ? false : Chart.defaults.animation;
    }

    // ── viewport preservation ────────────────────────────────────────────────
    // Destroying a chart throws away its zoom/pan, so toggling a series used to snap
    // the owner back to the full window — losing the exact stretch of days they had
    // zoomed in to read. These carry the viewport across a rebuild.
    //
    // An UNTOUCHED chart returns null on purpose: it has no viewport the owner chose,
    // so it should still get the fresh fit its new set of series deserves (turning the
    // historical years on is supposed to widen the axis back out to the full span).
    function _capturedRange() {
        if (!_chart || !_chart.scales || !_chart.scales.x) return null;
        if (typeof _chart.isZoomedOrPanned === 'function' && !_chart.isZoomedOrPanned()) return null;
        return { min: _chart.scales.x.min, max: _chart.scales.x.max };
    }

    function _restoreRange(range) {
        if (!range || !_chart || typeof _chart.zoomScale !== 'function') return;
        _chart.zoomScale('x', { min: range.min, max: range.max }, 'none');
        // Event markers are drawn for the visible window only, and a programmatic
        // zoom doesn't fire onZoomComplete, so they need re-deriving by hand.
        if (_st.eventsOn) _applyAnnotations();
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

    // Today as a local 'YYYY-MM-DD' — the boundary between the elapsed (dimmed)
    // and remaining (highlighted) parts of a forecast window.
    function _todayYMD() {
        var d = new Date();
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
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
                hidden: _st.forecastOnly ? true : !(allActive || _st.activeYears.has(yr)),
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
        // Split the projected line at "today": days already elapsed render dimmed
        // (the forecast that was made, kept as a record), the rest bright.
        var today   = _todayYMD();
        var pastPts = [], futurePts = [];
        forecast.forEach(function (r) {
            var pt = { x: _nd(r.date), y: r.predicted };
            if (r.date < today) pastPts.push(pt);
            else                futurePts.push(pt);
        });
        // Bridge the boundary so the line stays continuous across "today".
        if (pastPts.length && futurePts.length) {
            futurePts = [pastPts[pastPts.length - 1]].concat(futurePts);
        }
        if (pastPts.length) {
            fcDS.push({ label: 'Projected (elapsed)', data: pastPts, borderColor: 'rgba(255,87,34,0.28)', borderWidth: 2.5, borderDash: [6, 3], backgroundColor: 'transparent', pointRadius: 0, pointHoverRadius: 3, fill: false, tension: 0.3 });
        }
        fcDS.push({ label: 'Projected Demand', data: futurePts, borderColor: '#FF5722', borderWidth: 3, borderDash: [6, 3], backgroundColor: 'transparent', pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.3 });

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
            // Forecast-only mode fits the x-axis to just the forecast window (skip
            // the historical year series) so the projected line fills the chart
            // instead of sitting as a small segment on a full Jan–Dec axis.
            if (_st.forecastOnly && years.indexOf(ds.label) !== -1) return;
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
                animation: _entryAnimation(),
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
                animation: _entryAnimation(),
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
    // Re-renders the view so the x-axis refits: forecast-only tightens to the
    // forecast window; turning it off restores the full historical span.
    function _toggleForecastOnly() {
        _st.forecastOnly = !_st.forecastOnly;
        // Needs the rebuild: this toggle refits the x-axis (see _renderDailyView),
        // which is baked in at build time. Zoom is carried across.
        _renderView(true);
    }

    // Show / hide the forecast (projected demand line + confidence band). Independent
    // of the Newsvendor overlay — either, both, or neither can be shown.
    function _toggleForecast() {
        _st.showForecast = !_st.showForecast;
        // Pure visibility change — the forecast datasets are already in the chart and
        // the fitted axis doesn't depend on them, so there is nothing to rebuild.
        _syncViewControls();
    }

    // ── events toggle ─────────────────────────────────────────────────────────
    // ── Options menu ─────────────────────────────────────────────────────────
    // Everything that merely refines the chart lives in here, so the card itself
    // only ever shows the two controls that change WHAT you are looking at.
    function _wireOptionsMenu() {
        var btn   = document.getElementById('cm-opt-btn');
        var panel = document.getElementById('cm-opt-panel');
        if (!btn || !panel) return;

        function close() {
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            btn.classList.remove('is-open');
        }
        function open() {
            panel.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            btn.classList.add('is-open');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.hidden ? open() : close();
        });

        // Clicks inside the panel must not close it (they are the whole point),
        // but anywhere else should.
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', function (e) {
            // The event checklist that "Filter" opens is rendered OUTSIDE this panel,
            // so its clicks reach the document like any outside click would. Closing
            // on those would pull the menu out from under a checklist the owner opened
            // from it and is actively using.
            if (e.target && e.target.closest && e.target.closest('.event-filter-panel')) return;
            close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }

    function _toggleEvents() {
        _st.eventsOn = !_st.eventsOn;
        var btn = document.getElementById('cm-opt-events');
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
    // "Adjust price & stock" folds the same way the Newsvendor explainer below it
    // does. The four stat cards answer the question on their own; editing price or
    // stock is a follow-up action, so it should not be the first thing in view.
    function _toggleRefine() {
        _st.refineOpen = !_st.refineOpen;
        var body = document.getElementById('cm-refine-body');
        var chev = document.getElementById('cm-refine-chev');
        if (body) body.style.display   = _st.refineOpen ? '' : 'none';
        if (chev) chev.style.transform = _st.refineOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
    }

    function _toggleNv() {
        _st.nvOpen = !_st.nvOpen;
        var body = document.getElementById('cm-nv-body');
        var chev = document.getElementById('cm-nv-chev');
        if (body) body.style.display   = _st.nvOpen ? '' : 'none';
        if (chev) chev.style.transform = _st.nvOpen ? 'rotate(0deg)' : 'rotate(-90deg)';
    }

    // ── forecast reasoning panel ─────────────────────────────────────────────
    // Aggregates Prophet's per-day components into a "Why this forecast?" card.
    // Each prediction's yhat = trend + weekly + yearly + Σ event regressors —
    // summing each piece over the window tells the user what's driving the total.
    function _renderReasoning(forecast) {
        var container = document.getElementById('cm-reasoning');
        if (!container) return;
        if (!forecast || !forecast.length || !forecast[0] || !forecast[0].components) {
            _st.infoAvail.why = false;
            _updateInfoTabs();
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

        container.innerHTML = html;
        _st.infoAvail.why = true;
        _updateInfoTabs();
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

        var rho       = m.rho_used;
        var inflation = m.std_inflation_factor;
        var hasBuffer = rho != null && rho !== 0
                     && inflation != null && inflation > 1
                     && m.total_std != null;

        // Bottom section differs depending on whether AR(1) correction was applied.
        var stdSection;

        if (hasBuffer) {
            // Derive what the standard (uncorrected) result would have been.
            // q* = μ + z·σ  →  z = (optimal_total − μ) / corrected_std
            // uncorrected_q = μ + z · original_std = μ + (optimal_total − μ) / inflation
            var pctWider         = Math.round((inflation - 1) * 100);
            var correctedStd     = m.total_std;
            var originalStd      = correctedStd / inflation;
            var uncorrOptimal    = Math.round(tot + ((m.optimal_total || 0) - tot) / inflation);
            var uncorrRestock    = Math.max(0, uncorrOptimal - (m.current_stock || 0));

            var stdLow  = Math.max(0, Math.round(tot - 1.96 * originalStd));
            var stdHi   = Math.round(tot + 1.96 * originalStd);
            var bufLow  = Math.max(0, Math.round(tot - 1.96 * correctedStd));
            var bufHi   = Math.round(tot + 1.96 * correctedStd);

            stdSection =
                '<div class="cm-nv-comparison">'
              + '<div class="cm-nv-cmp-title">Order quantity — standard vs. safety-buffered σ</div>'

              + '<div class="cm-nv-cmp-row">'
              +   '<div class="cm-nv-cmp-cell cm-nv-cmp-label-cell"><span class="cm-nv-cmp-tag">Standard</span></div>'
              +   '<div class="cm-nv-cmp-cell">σ = ±' + Math.round(originalStd) + ' units<br><span class="cm-nv-cmp-range">' + stdLow + '–' + stdHi + ' range (95%)</span></div>'
              +   '<div class="cm-nv-cmp-cell cm-nv-cmp-arrow">→</div>'
              +   '<div class="cm-nv-cmp-cell cm-nv-cmp-result">'
              +     uncorrOptimal.toLocaleString() + ' total'
              +     '&nbsp;&nbsp;<span class="cm-nv-cmp-restock">+' + uncorrRestock.toLocaleString() + ' to order</span>'
              +   '</div>'
              + '</div>'

              + '<div class="cm-nv-cmp-row cm-nv-cmp-used-row">'
              +   '<div class="cm-nv-cmp-cell cm-nv-cmp-label-cell"><span class="cm-nv-cmp-tag cm-nv-cmp-tag-used">+' + pctWider + '% buffer</span></div>'
              +   '<div class="cm-nv-cmp-cell">σ = ±' + Math.round(correctedStd) + ' units<br><span class="cm-nv-cmp-range">' + bufLow + '–' + bufHi + ' range (95%)</span></div>'
              +   '<div class="cm-nv-cmp-cell cm-nv-cmp-arrow">→</div>'
              +   '<div class="cm-nv-cmp-cell cm-nv-cmp-result">'
              +     '<strong>' + (m.optimal_total || 0).toLocaleString() + ' total'
              +     '&nbsp;&nbsp;+' + (m.restock_qty || 0).toLocaleString() + ' to order</strong>'
              +     '&nbsp;<span class="cm-nv-cmp-used-badge">used ✓</span>'
              +   '</div>'
              + '</div>'

              + '<p class="cm-nv-cmp-why">'
              +   'Residual ρ = ' + rho.toFixed(2) + ' — busy days come in streaks for this product, '
              +   'so σ was widened by ' + pctWider + '% to guard against back-to-back demand mismatches. '
              +   'The buffered order is the one recommended.'
              + '</p>'
              + '</div>';
        } else {
            var low = m.total_std != null ? Math.max(0, Math.round(tot - 1.96 * m.total_std)) : null;
            var hi  = m.total_std != null ? Math.round(tot + 1.96 * m.total_std) : null;

            stdSection =
                (low != null ? row('Demand range (95%)', low + ' – ' + hi + ' units &nbsp;·&nbsp; avg ' + Math.round(tot) + ' units &nbsp;·&nbsp; σ = ' + Math.round(m.total_std) + ' units') : '')
              + row('Optimal supply', m.optimal_total + ' units total &nbsp;·&nbsp; ' + (m.current_stock || 0) + ' on hand + <strong>' + m.restock_qty + ' to order</strong>')
              + '<div class="cm-nv-note">'
              +   '<strong>No extra buffer — daily independence assumed.</strong> '
              +   'Backtesting didn\'t detect meaningful day-to-day demand clustering for this product, so σ was not widened. '
              +   '<em>Caveat:</em> if this product spikes around predictable events and busy days tend to run in streaks, '
              +   'real-world stockout risk could be slightly higher than shown.'
              + '</div>';
        }

        body.innerHTML =
              row('Price / Cost',     '₱' + p.toFixed(2) + ' selling &nbsp;·&nbsp; ₱' + c.toFixed(2) + ' cost &nbsp;·&nbsp; ₱' + mg.toFixed(2) + ' margin (' + cr + '%)')
            + row('Critical ratio',   '<strong>' + cr + '%</strong> — ' + strategy)
            + row('Under-stock cost', '₱' + mg.toFixed(2) + ' per unit — profit lost when you run out of stock')
            + row('Over-stock cost',  '₱' + c.toFixed(2)  + ' per unit — money tied up in unsold inventory')
            + stdSection;
    }

    function _destroyCharts() {
        if (_chart) { _chart.destroy(); _chart = null; }
    }

    return {
        open:        open,
        openLoading: openLoading,
        showResults: showResults,
        close:       close,
        renderIn:    renderIn,
        destroyIn:   destroyIn,
    };
})();
