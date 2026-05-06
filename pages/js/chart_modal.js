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
    var _chart = null, _weeklyChart = null;
    var _st = {
        activeYears:  new Set(),
        forecastOnly: false,
        eventsOn:     false,
        fcStart:      null,
        disabledIds:  new Set(),
        nvOpen:       true,
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

    // ── results HTML builder ──────────────────────────────────────────────────
    function _resultsHTML(cfg) {
        var saveBtn    = cfg.onSave     ? '<button id="cm-save-btn" class="cm-primary-btn">Save Forecast</button>' : '';
        var againBtn   = cfg.onRunAgain ? '<button id="cm-run-again-btn" class="cm-ghost-btn-lg">← Run Again</button>' : '';
        var actionRow  = (cfg.onSave || cfg.onRunAgain)
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
                        '<button id="cm-events-btn" class="cm-toggle-btn">' +
                            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
                            ' Events' +
                        '</button>' +
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
            '<div class="cm-weekly-wrap">' +
                '<p class="cm-section-label">Weekly Demand Forecast</p>' +
                '<canvas id="cm-weekly-canvas" style="max-height:170px"></canvas>' +
            '</div>' +
            actionRow;
    }

    // ── wire interactive elements ─────────────────────────────────────────────
    function _wire(cfg) {
        document.getElementById('cm-events-btn').addEventListener('click', _toggleEvents);
        document.getElementById('cm-fo-btn').addEventListener('click', _toggleForecastOnly);
        document.getElementById('cm-zoom-btn').addEventListener('click', function () { if (_chart) _chart.resetZoom(); });
        document.getElementById('cm-nv-toggle').addEventListener('click', _toggleNv);
        var saveBtn  = document.getElementById('cm-save-btn');
        var againBtn = document.getElementById('cm-run-again-btn');
        if (saveBtn  && cfg.onSave)     saveBtn.addEventListener('click', cfg.onSave);
        if (againBtn && cfg.onRunAgain) againBtn.addEventListener('click', cfg.onRunAgain);
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

        container.innerHTML = _resultsHTML(cfg);
        _wire(cfg);

        var bandLegend = document.getElementById('cm-band-legend');
        if (bandLegend) bandLegend.style.display = cfg.hasBand ? '' : 'none';

        _renderChart(cfg.historical, cfg.forecast, cfg.hasBand);
        _renderStats(cfg.meta);

        if (cfg.meta && cfg.meta.cost_price) {
            _renderNv(cfg.meta);
        } else {
            var nvSec = container.querySelector('.cm-nv-section');
            if (nvSec) nvSec.style.display = 'none';
        }

        _renderWeekly(cfg.forecast);
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
        // update title in case product name wasn't known at openLoading time
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

    // ── chart rendering ───────────────────────────────────────────────────────
    function _nd(d) {
        if (!d) return null;
        var n = '2000' + d.slice(4);
        return n === '2000-02-29' ? '2000-02-28' : n;
    }

    function _renderChart(historical, forecast, hasBand) {
        var byYear   = groupByYearNorm(historical);
        var years    = Object.keys(byYear).sort();
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

        var initMin = minN;
        if (_st.fcStart) {
            var d = new Date(_st.fcStart);
            d.setMonth(d.getMonth() - 6);
            initMin = d.toISOString().slice(0, 10);
        }

        var ann = _st.fcStart ? { fcStart: buildForecastStartAnnotation(_st.fcStart) } : {};

        _chart = new Chart(document.getElementById('cm-canvas'), {
            type: 'line',
            data: { datasets: datasets },
            options: {
                responsive: true,
                interaction: { mode: 'x', intersect: false },
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
                            title: function (items) { return items.length ? new Date(items[0].parsed.x).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : ''; },
                            label: function (ctx)   { return ctx.parsed.y == null ? null : ' ' + ctx.dataset.label + ': ' + Math.round(ctx.parsed.y) + ' units'; },
                        },
                    },
                    annotation: { annotations: ann },
                },
                scales: {
                    x: {
                        type: 'time', min: initMin, max: maxT,
                        time: { minUnit: 'day', tooltipFormat: 'MMM d', displayFormats: { day: 'MMM d', week: 'MMM d', month: 'MMM', year: 'MMM' } },
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

        _buildYearPills(years);
    }

    // ── year pills ────────────────────────────────────────────────────────────
    function _buildYearPills(years) {
        var sel = document.getElementById('cm-year-sel');
        if (!sel || years.length <= 1) return;
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
            if (ds.label === 'Projected Demand' || ds.label === '_upper' || ds.label === '_lower') return;
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
        if (!ch || !ch.scales.x) return;
        var base = _st.fcStart ? { fcStart: buildForecastStartAnnotation(_st.fcStart) } : {};
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

    // ── weekly bar chart ──────────────────────────────────────────────────────
    function _renderWeekly(rows) {
        if (!rows || !rows.length) return;
        var wm = {};
        rows.forEach(function (r) {
            var d = new Date(r.date), dow = d.getDay();
            var mon = new Date(d); mon.setDate(d.getDate() - (dow === 0 ? 6 : dow - 1));
            var key = mon.toISOString().slice(0, 10);
            wm[key] = (wm[key] || 0) + r.predicted;
        });
        var weeks = Object.keys(wm).sort();
        _weeklyChart = new Chart(document.getElementById('cm-weekly-canvas'), {
            type: 'bar',
            data: {
                labels:   weeks.map(function (w) { return new Date(w + 'T00:00:00').toLocaleString('default', { month: 'short', day: 'numeric' }); }),
                datasets: [{ label: 'Forecast', data: weeks.map(function (w) { return Math.round(wm[w]); }), backgroundColor: 'rgba(255,87,34,0.65)', borderColor: '#FF5722', borderWidth: 1, borderRadius: 4 }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#261F0E', titleColor: '#D2C8AE', bodyColor: '#F0E8D0', padding: 10,
                        callbacks: {
                            title: function (i) { return 'Week of ' + i[0].label; },
                            label: function (c) { return ' ' + c.parsed.y + ' units predicted'; },
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

    function _destroyCharts() {
        if (_chart)       { _chart.destroy();       _chart       = null; }
        if (_weeklyChart) { _weeklyChart.destroy();  _weeklyChart = null; }
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
