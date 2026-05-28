// pages/js/batch_forecast.js
// Manages the batch forecast flow on the forecast page.
// Depends on BATCH_BASE_URL (injected by forecast.view.php) and globals from the
// forecast.view.php inline script: fcBatchMode, openForecastModal, closeForecastModal,
// setFcPanel, showFcInputError.
//
// Flow:
//   normal → (Batch Forecast btn) → selecting
//   selecting → (Next →) → opens existing fc-modal with batch label
//   fc-modal submitted → runForecast() delegates to BatchForecast.runProphet()
//   runProphet → batch Prophet API → opens batch-chart-modal with product tabs
//   batch-chart-modal → ChartModal.renderIn() per tab → "Generate Newsvendor for All →"
//   batch-nv-modal → table of products with inputs → confirm → per-product run_optimize.php
//   results shown on chart tabs → "Save All Forecasts" button triggers manual save

var BatchForecast = (function () {

    // ── Shared state ──────────────────────────────────────────────────────────

    var _inSelectMode = false;

    // id (string) → { id, name, sku, cost_price, selling_price, current_stock, db_cost, db_price }
    var _products = {};

    // Ordered list of selected product IDs (matches product-list display order).
    var _selectedIds = [];

    // Results from the last batch Prophet run.
    // Each entry: { product_id, product_name, success, forecast, historical,
    //               optResult?, optInputs? }
    // optResult: raw JSON from run_optimize.php
    // optInputs: { cost_price, selling_price, current_stock }
    var _prophetResults = [];

    // Tracks which product IDs have been successfully saved in this batch session.
    // Reset each time a new batch is opened so stale state never carries over.
    var _savedProductIds = {};

    // Currently active tab index in the chart modal.
    var _activeTabIdx = 0;

    // ── Session storage — persists selections across search-triggered reloads ──

    var _STORE_KEY = 'pv_batch_v1';

    function _saveToStorage() {
        try {
            sessionStorage.setItem(_STORE_KEY, JSON.stringify({
                inSelectMode: _inSelectMode,
                selectedIds:  _selectedIds,
                products:     _products,
            }));
        } catch (e) {}
    }

    function _loadFromStorage() {
        try {
            var saved = JSON.parse(sessionStorage.getItem(_STORE_KEY) || 'null');
            if (!saved) return false;
            _selectedIds = saved.selectedIds || [];
            _products    = saved.products    || {};
            return !!saved.inSelectMode;
        } catch (e) { return false; }
    }

    function _clearStorage() {
        try { sessionStorage.removeItem(_STORE_KEY); } catch (e) {}
    }

    // ── Selection mode ────────────────────────────────────────────────────────

    function enterMode() {
        _inSelectMode = true;
        var wasInMode = _loadFromStorage();
        _inSelectMode = true; // _loadFromStorage may have reset it

        document.getElementById('batch-toolbar').style.display = '';
        document.body.classList.add('batch-select-mode');

        _selectedIds.forEach(function (id) { _markRow(id, true); });
        _updateToolbar();
        _saveToStorage();
    }

    function exitMode() {
        _inSelectMode = false;
        document.getElementById('batch-toolbar').style.display = 'none';
        document.body.classList.remove('batch-select-mode');
        document.querySelectorAll('.batch-row-check').forEach(function (el) {
            el.classList.remove('checked');
        });
        document.querySelectorAll('.product-row').forEach(function (r) {
            r.classList.remove('batch-selected');
        });
        _clearStorage();
        _selectedIds = [];
        _products    = {};
    }

    function toggleRow(productId) {
        if (!_inSelectMode) return;
        var id  = String(productId);
        var idx = _selectedIds.indexOf(id);
        if (idx >= 0) {
            _selectedIds.splice(idx, 1);
            delete _products[id];
            _markRow(id, false);
        } else {
            var row    = document.querySelector('.product-row[data-product-id="' + id + '"]');
            if (!row) return;
            var dbCost  = row.dataset.costPrice    ? parseFloat(row.dataset.costPrice)    : null;
            var dbPrice = row.dataset.sellingPrice ? parseFloat(row.dataset.sellingPrice) : null;
            _products[id] = {
                id:            parseInt(id, 10),
                name:          row.dataset.productName || '',
                sku:           row.dataset.productSku  || '',
                cost_price:    dbCost  != null ? dbCost  : '',
                selling_price: dbPrice != null ? dbPrice : '',
                current_stock: '',
                db_cost:       dbCost,
                db_price:      dbPrice,
            };
            _selectedIds.push(id);
            _markRow(id, true);
        }
        _updateToolbar();
        _saveToStorage();
    }

    function selectAllVisible() {
        var toAdd = [];
        document.querySelectorAll('.product-row[data-product-id]').forEach(function (row) {
            if (row.style.display === 'none') return;
            var id = String(row.dataset.productId);
            if (_selectedIds.indexOf(id) === -1) toAdd.push({ id: id, row: row });
        });

        if (toAdd.length === 0) {
            document.querySelectorAll('.product-row[data-product-id]').forEach(function (row) {
                if (row.style.display === 'none') return;
                var id  = String(row.dataset.productId);
                var idx = _selectedIds.indexOf(id);
                if (idx >= 0) { _selectedIds.splice(idx, 1); delete _products[id]; _markRow(id, false); }
            });
        } else {
            toAdd.forEach(function (item) {
                var dbCost  = item.row.dataset.costPrice    ? parseFloat(item.row.dataset.costPrice)    : null;
                var dbPrice = item.row.dataset.sellingPrice ? parseFloat(item.row.dataset.sellingPrice) : null;
                _products[item.id] = {
                    id:            parseInt(item.id, 10),
                    name:          item.row.dataset.productName || '',
                    sku:           item.row.dataset.productSku  || '',
                    cost_price:    dbCost  != null ? dbCost  : '',
                    selling_price: dbPrice != null ? dbPrice : '',
                    current_stock: '',
                    db_cost:       dbCost,
                    db_price:      dbPrice,
                };
                _selectedIds.push(item.id);
                _markRow(item.id, true);
            });
        }
        _updateToolbar();
        _saveToStorage();
    }

    function _markRow(id, checked) {
        var chk = document.getElementById('bchk-' + id);
        if (chk) chk.classList.toggle('checked', checked);
        var row = document.querySelector('.product-row[data-product-id="' + id + '"]');
        if (row) row.classList.toggle('batch-selected', checked);
    }

    function _allVisibleSelected() {
        var anyVisible = false;
        var allSelected = true;
        document.querySelectorAll('.product-row[data-product-id]').forEach(function(row) {
            if (row.style.display === 'none') return;
            anyVisible = true;
            if (_selectedIds.indexOf(String(row.dataset.productId)) === -1) allSelected = false;
        });
        return anyVisible && allSelected;
    }

    function _updateToolbar() {
        var count   = _selectedIds.length;
        var countEl = document.getElementById('batch-selection-count');
        var nextBtn = document.getElementById('batch-next-btn');
        var selBtn  = document.querySelector('.batch-select-all-btn');
        if (countEl) countEl.textContent = count + ' selected';
        if (nextBtn) nextBtn.disabled    = count === 0;
        if (selBtn)  selBtn.textContent  = _allVisibleSelected() ? 'Unselect All visible' : 'Select All visible';
    }

    function selectedCount() {
        return _selectedIds.length;
    }

    // ── Open the existing fc-modal in batch mode ──────────────────────────────
    // Sets the global fcBatchMode flag (from forecast.view.php inline script)
    // then delegates to openForecastModal() which handles the rest.

    function openFcModal() {
        if (_selectedIds.length === 0) return;
        window.fcBatchMode = true;
        openForecastModal(); // global from forecast.view.php
    }

    // ── Run batch Prophet — called by runForecast() in forecast.view.php ──────
    // At this point the fc-modal is showing the loading spinner.

    function runProphet(fromDate, toDate) {
        fetch(BATCH_BASE_URL + '/api/run_batch_prophet.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                product_ids: _selectedIds.map(Number),
                from_date:   fromDate,
                to_date:     toDate,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showFcInputError(data.error); return; }

            var successes = (data.results || []).filter(function (r) { return r.success; });
            if (!successes.length) {
                showFcInputError('All products failed to forecast. Check that Flask is running and each product has sales history.');
                return;
            }

            _prophetResults = data.results || [];
            closeForecastModal(); // closes fc-modal, resets fcBatchMode
            _openChartModal();
        })
        .catch(function () { showFcInputError('Network error. Please try again.'); });
    }

    // ── Batch chart modal ─────────────────────────────────────────────────────

    function _openChartModal() {
        _activeTabIdx = 0;
        var modal = document.getElementById('batch-chart-modal');
        if (!modal) return;

        var successes = _prophetResults.filter(function (r) { return r.success; });

        var titleEl = document.getElementById('bcm-title');
        if (titleEl) {
            var failed = _prophetResults.length - successes.length;
            titleEl.textContent = successes.length + ' product' + (successes.length === 1 ? '' : 's') + ' forecast'
                + (failed > 0 ? ' (' + failed + ' failed)' : '');
        }

        // Reset save-all button state and saved-ID tracking for a fresh batch run
        _savedProductIds = {};
        var saveAllBtn = document.getElementById('bcm-save-all-btn');
        if (saveAllBtn) {
            saveAllBtn.style.display = 'none';
            saveAllBtn.disabled      = false;
            saveAllBtn.textContent   = 'Save Forecast';
        }

        _buildTabs(successes);
        _renderTab(0);

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function _buildTabs(successes) {
        var tabsEl = document.getElementById('bcm-tabs');
        if (!tabsEl) return;
        tabsEl.innerHTML = '';
        successes.forEach(function (r, i) {
            var btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'bcm-tab' + (i === 0 ? ' active' : '');
            btn.textContent = r.product_name;
            btn.addEventListener('click', (function (idx) {
                return function () { _switchTab(idx); };
            })(i));
            tabsEl.appendChild(btn);
        });
    }

    function _switchTab(idx) {
        var successes = _prophetResults.filter(function (r) { return r.success; });
        if (idx < 0 || idx >= successes.length) return;
        _activeTabIdx = idx;

        document.querySelectorAll('#bcm-tabs .bcm-tab').forEach(function (btn, i) {
            btn.classList.toggle('active', i === idx);
        });

        _renderTab(idx);
    }

    function _renderTab(idx) {
        var successes = _prophetResults.filter(function (r) { return r.success; });
        var result = successes[idx];
        if (!result) return;

        var chartArea = document.getElementById('bcm-chart-area');
        if (!chartArea) return;

        ChartModal.destroyIn();

        var p = _products[String(result.product_id)] || {};

        // Build meta from stored optimization result, if available.
        var meta = null;
        if (result.optResult && result.optInputs) {
            meta = {
                total_predicted:      result.optResult.total_predicted,
                restock_qty:          result.optResult.restock_qty,
                current_stock:        result.optInputs.current_stock,
                cost_price:           result.optInputs.cost_price,
                selling_price:        result.optInputs.selling_price,
                total_std:            result.optResult.total_std,
                optimal_total:        result.optResult.optimal_total,
                est_profit:           result.optResult.est_profit,
                rho_used:             result.optResult.rho_used             != null ? result.optResult.rho_used             : undefined,
                std_inflation_factor: result.optResult.std_inflation_factor != null ? result.optResult.std_inflation_factor : undefined,
            };
        }

        ChartModal.renderIn(chartArea, {
            label:               'Demand Forecast',
            title:               result.product_name,
            productId:           result.product_id,
            accuracyBase:        BATCH_BASE_URL,
            historical:          result.historical || [],
            forecast:            result.forecast   || [],
            hasBand:             true,
            productCostPrice:    p.db_cost  != null ? p.db_cost  : null,
            productSellingPrice: p.db_price != null ? p.db_price : null,
            disabledEventIds:    new Set(),
            onRunAgain:          null,
            onGenerateRestock:   null,
            meta:                meta,
            onSave:              meta ? function () {
                _saveProduct(result, meta).then(function (data) {
                    if (data && !data.error) {
                        _savedProductIds[result.product_id] = true;
                        var btn = document.getElementById('cm-save-btn');
                        if (btn) {
                            btn.disabled         = true;
                            btn.textContent      = 'Saved ✓';
                            btn.style.opacity    = '1';
                            btn.style.cursor     = 'default';
                            btn.style.color      = '#1A6933';
                        }
                    }
                });
            } : null,
        });
    }

    function confirmCloseChartModal() {
        var hasResults = _prophetResults.some(function (r) { return r.success; });
        if (!hasResults) { closeChartModal(); return; }

        showConfirm({
            title:        'Close forecast results?',
            message:      'Your forecast results have not been saved. Closing will discard all data from this session.',
            confirmText:  'Close anyway',
            confirmStyle: 'danger',
            onConfirm:    function () { closeChartModal(); },
        });
    }

    function closeChartModal() {
        ChartModal.destroyIn();
        var modal = document.getElementById('batch-chart-modal');
        if (modal) modal.classList.add('hidden');
        document.body.style.overflow = '';
        exitMode();
    }

    // ── Batch newsvendor modal ────────────────────────────────────────────────

    function openNvModal() {
        var modal = document.getElementById('batch-nv-modal');
        if (!modal) return;

        var successes = _prophetResults.filter(function (r) { return r.success; });
        if (!successes.length) return;

        _buildNvTable(successes);

        var errEl = document.getElementById('bnv-error');
        if (errEl) errEl.style.display = 'none';

        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = false; genBtn.textContent = 'Generate All →'; }

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function _buildNvTable(successes) {
        var body = document.getElementById('bnv-body');
        if (!body) return;

        var html = '<table class="bnv-table"><thead><tr>'
            + '<th class="bnv-th">Product</th>'
            + '<th class="bnv-th">Cost Price</th>'
            + '<th class="bnv-th">Selling Price</th>'
            + '<th class="bnv-th">Stock on Hand</th>'
            + '<th class="bnv-th">Result</th>'
            + '</tr></thead><tbody>';

        successes.forEach(function (r) {
            var p       = _products[String(r.product_id)] || {};
            var dbCost  = p.db_cost  != null ? p.db_cost.toFixed(2)  : '';
            var dbPrice = p.db_price != null ? p.db_price.toFixed(2) : '';

            // Pre-fill with previously entered inputs if this product was already run.
            var prevCost  = r.optInputs ? r.optInputs.cost_price.toFixed(2)    : dbCost;
            var prevPrice = r.optInputs ? r.optInputs.selling_price.toFixed(2) : dbPrice;
            var prevStock = r.optInputs ? r.optInputs.current_stock             : 0;

            html += '<tr class="bnv-row" data-pid="' + r.product_id + '">'
                + '<td class="bnv-td bnv-td-name">' + _esc(r.product_name) + '</td>'
                + '<td class="bnv-td">'
                    + '<div class="bnv-input-wrap"><span class="bnv-input-affix">₱</span>'
                    + '<input type="number" class="bnv-input bnv-cost" min="0" step="0.01" placeholder="0.00"'
                    + (prevCost ? ' value="' + prevCost + '"' : '') + '>'
                    + '</div>'
                    + (dbCost ? '<p class="bnv-hint">From profile</p>' : '')
                + '</td>'
                + '<td class="bnv-td">'
                    + '<div class="bnv-input-wrap"><span class="bnv-input-affix">₱</span>'
                    + '<input type="number" class="bnv-input bnv-price" min="0" step="0.01" placeholder="0.00"'
                    + (prevPrice ? ' value="' + prevPrice + '"' : '') + '>'
                    + '</div>'
                    + (dbPrice ? '<p class="bnv-hint">From profile</p>' : '')
                + '</td>'
                + '<td class="bnv-td">'
                    + '<div class="bnv-input-wrap">'
                    + '<input type="number" class="bnv-input bnv-stock" min="0" step="1" placeholder="0" value="' + prevStock + '">'
                    + '<span class="bnv-input-affix bnv-input-affix-right">units</span>'
                    + '</div>'
                + '</td>'
                + '<td class="bnv-td" id="bnv-status-' + r.product_id + '">'
                    + (r.optResult
                        ? '<span class="bnv-status-ok">Ready ✓</span>'
                        : '<span class="bnv-status-pending">—</span>')
                + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        body.innerHTML = html;
    }

    function closeNvModal() {
        var modal = document.getElementById('batch-nv-modal');
        if (modal) modal.classList.add('hidden');
    }

    function confirmRunNewsvendor() {
        var successes = _prophetResults.filter(function (r) { return r.success; });
        var n = successes.length;

        var missing = 0;
        successes.forEach(function (r) {
            var row = document.querySelector('#bnv-body .bnv-row[data-pid="' + r.product_id + '"]');
            if (!row) return;
            var cost  = parseFloat(row.querySelector('.bnv-cost').value)  || 0;
            var price = parseFloat(row.querySelector('.bnv-price').value) || 0;
            if (cost <= 0 || price <= 0 || price <= cost) missing++;
        });

        var msg = 'Generating Newsvendor restock insights for ' + n + ' product' + (n === 1 ? '' : 's') + '. '
            + 'Results will appear on the chart — save manually when ready.';
        if (missing > 0) {
            msg += '\n\n' + missing + ' product' + (missing === 1 ? ' is' : 's are')
                + ' missing valid cost / selling price and will be skipped.';
        }

        showConfirm({
            title:        'Generate Newsvendor for All?',
            message:      msg,
            confirmText:  'Generate All',
            confirmStyle: 'primary',
            onConfirm:    function () { _runNewsvendor(); },
        });
    }

    // Runs run_optimize.php per product sequentially. Does NOT save to DB.
    // Stores optResult / optInputs on each _prophetResults entry.
    function _runNewsvendor() {
        var successes = _prophetResults.filter(function (r) { return r.success; });

        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = true; genBtn.textContent = 'Generating…'; }

        var toRun = [];
        successes.forEach(function (r) {
            var row = document.querySelector('#bnv-body .bnv-row[data-pid="' + r.product_id + '"]');
            if (!row) return;
            var cost  = parseFloat(row.querySelector('.bnv-cost').value)  || 0;
            var price = parseFloat(row.querySelector('.bnv-price').value) || 0;
            var stock = parseInt(row.querySelector('.bnv-stock').value, 10) || 0;

            var statusEl = document.getElementById('bnv-status-' + r.product_id);
            if (cost > 0 && price > 0 && price > cost) {
                toRun.push({ result: r, cost: cost, price: price, stock: stock });
                if (statusEl) statusEl.innerHTML = '<span class="bnv-status-running">Queued…</span>';
            } else {
                if (statusEl) statusEl.innerHTML = '<span class="bnv-status-skip">Skipped — missing price</span>';
            }
        });

        if (!toRun.length) {
            _showNvError('No products with valid prices to generate.');
            return;
        }

        var chain = Promise.resolve();
        toRun.forEach(function (item) {
            chain = chain.then(function () {
                var statusEl = document.getElementById('bnv-status-' + item.result.product_id);
                if (statusEl) statusEl.innerHTML = '<span class="bnv-status-running">Running…</span>';

                var fd = new FormData();
                fd.append('forecast',      JSON.stringify(item.result.forecast));
                fd.append('cost_price',    item.cost);
                fd.append('selling_price', item.price);
                fd.append('current_stock', item.stock);
                fd.append('product_id',    item.result.product_id);

                return fetch(BATCH_BASE_URL + '/api/run_optimize.php', {
                    method: 'POST',
                    body:   fd,
                })
                .then(function (r) { return r.json(); })
                .then(function (optData) {
                    if (optData.error) {
                        if (statusEl) statusEl.innerHTML = '<span class="bnv-status-err">Failed: ' + _esc(optData.error) + '</span>';
                        return;
                    }

                    // Store on the matching _prophetResults entry so _renderTab can read it.
                    var global = _prophetResults.find(function (r) { return r.product_id === item.result.product_id; });
                    if (global) {
                        global.optResult = optData;
                        global.optInputs = { cost_price: item.cost, selling_price: item.price, current_stock: item.stock };
                    }

                    if (statusEl) {
                        statusEl.innerHTML =
                              '<span class="bnv-status-ok">Ready ✓</span>'
                            + '<span class="bnv-result-detail">'
                            + 'Order: <strong>' + Number(optData.restock_qty).toLocaleString() + ' units</strong>'
                            + ' &nbsp;·&nbsp; ₱' + Number(optData.est_profit).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' profit'
                            + '</span>';
                    }
                })
                .catch(function () {
                    if (statusEl) statusEl.innerHTML = '<span class="bnv-status-err">Network error</span>';
                });
            });
        });

        chain.then(function () { _onNvDone(); });
    }

    // Called after all per-product optimizations finish.
    function _onNvDone() {
        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = false; genBtn.textContent = 'Regenerate →'; }

        // Re-render the active chart tab so NV results appear immediately.
        _renderTab(_activeTabIdx);
        _showSaveAllBtn();

        // Close the NV modal after a short pause so the user sees the final statuses.
        setTimeout(function () { closeNvModal(); }, 1400);
    }

    function _showNvError(msg) {
        var errEl = document.getElementById('bnv-error');
        if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = false; genBtn.textContent = 'Generate All →'; }
    }

    // ── Per-product save ──────────────────────────────────────────────────────

    function _saveProduct(result, meta) {
        var fd = new FormData();
        fd.append('product_id',    result.product_id);
        fd.append('forecast_data', JSON.stringify(result.forecast));
        fd.append('restock_qty',   meta.restock_qty);
        fd.append('cost_price',    meta.cost_price);
        fd.append('selling_price', meta.selling_price);
        fd.append('current_stock', meta.current_stock);
        fd.append('total_std',     meta.total_std);
        fd.append('optimal_total', meta.optimal_total);
        fd.append('est_profit',    meta.est_profit);
        if (meta.rho_used             != null) fd.append('rho_used',             meta.rho_used);
        if (meta.std_inflation_factor != null) fd.append('std_inflation_factor', meta.std_inflation_factor);

        return fetch(BATCH_BASE_URL + '/api/save_forecast.php', {
            method: 'POST',
            body:   fd,
        }).then(function (r) { return r.json(); });
    }

    // ── Reveal the "Save Forecasts" button in the chart modal footer ─────────

    function _showSaveAllBtn() {
        var btn = document.getElementById('bcm-save-all-btn');
        if (btn) btn.style.display = '';
    }

    // ── Save modal ────────────────────────────────────────────────────────────

    function openSaveModal() {
        var toSave = _prophetResults.filter(function (r) { return r.success && r.optResult && r.optInputs; });
        if (!toSave.length) return;

        var modal = document.getElementById('batch-save-modal');
        if (!modal) return;

        _buildSaveList(toSave);

        var errEl = document.getElementById('bsv-error');
        if (errEl) errEl.style.display = 'none';

        var saveBtn = document.getElementById('bsv-save-btn');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Selected →'; }

        modal.classList.remove('hidden');
    }

    function _buildSaveList(toSave) {
        var body = document.getElementById('bsv-body');
        if (!body) return;

        var html = '<label class="bsv-select-all-row">'
            + '<input type="checkbox" id="bsv-check-all" class="bsv-check" checked>'
            + '<span class="bsv-select-all-label">Select All</span>'
            + '</label>';

        toSave.forEach(function (r) {
            var alreadySaved = !!_savedProductIds[r.product_id];
            if (alreadySaved) {
                html += '<label class="bsv-row bsv-row-saved">'
                    + '<input type="checkbox" class="bsv-check bsv-product-check" value="' + r.product_id + '" checked disabled>'
                    + '<div class="bsv-row-info">'
                    + '<span class="bsv-name">' + _esc(r.product_name) + '</span>'
                    + '<span class="bsv-detail-saved">Saved ✓</span>'
                    + '</div>'
                    + '</label>';
            } else {
                var detail = 'Order: <strong>' + Number(r.optResult.restock_qty).toLocaleString() + ' units</strong>'
                    + ' &nbsp;·&nbsp; ₱' + Number(r.optResult.est_profit).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' profit';
                html += '<label class="bsv-row">'
                    + '<input type="checkbox" class="bsv-check bsv-product-check" value="' + r.product_id + '" checked>'
                    + '<div class="bsv-row-info">'
                    + '<span class="bsv-name">' + _esc(r.product_name) + '</span>'
                    + '<span class="bsv-detail">' + detail + '</span>'
                    + '</div>'
                    + '</label>';
            }
        });

        body.innerHTML = html;

        var checkAll = document.getElementById('bsv-check-all');
        // Only count/toggle non-disabled (not-yet-saved) checkboxes.
        var productChecks = body.querySelectorAll('.bsv-product-check:not([disabled])');

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                productChecks.forEach(function (chk) { chk.checked = checkAll.checked; });
            });
        }

        productChecks.forEach(function (chk) {
            chk.addEventListener('change', function () {
                var total   = productChecks.length;
                var checked = body.querySelectorAll('.bsv-product-check:not([disabled]):checked').length;
                if (checkAll) checkAll.checked = total === checked;
            });
        });
    }

    function closeSaveModal() {
        var modal = document.getElementById('batch-save-modal');
        if (modal) modal.classList.add('hidden');
    }

    function confirmSaveSelected() {
        var body = document.getElementById('bsv-body');
        if (!body) return;

        var selected = [];
        body.querySelectorAll('.bsv-product-check:checked').forEach(function (chk) {
            selected.push(parseInt(chk.value, 10));
        });

        var errEl = document.getElementById('bsv-error');
        if (!selected.length) {
            if (errEl) { errEl.textContent = 'Select at least one product to save.'; errEl.style.display = ''; }
            return;
        }
        if (errEl) errEl.style.display = 'none';

        var saveBtn = document.getElementById('bsv-save-btn');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

        var toSave = _prophetResults.filter(function (r) {
            return r.success && r.optResult && r.optInputs && selected.indexOf(r.product_id) !== -1;
        });

        var errors = 0;
        var chain  = Promise.resolve();

        toSave.forEach(function (result) {
            chain = chain.then(function () {
                var meta = {
                    total_predicted:      result.optResult.total_predicted,
                    restock_qty:          result.optResult.restock_qty,
                    current_stock:        result.optInputs.current_stock,
                    cost_price:           result.optInputs.cost_price,
                    selling_price:        result.optInputs.selling_price,
                    total_std:            result.optResult.total_std,
                    optimal_total:        result.optResult.optimal_total,
                    est_profit:           result.optResult.est_profit,
                    rho_used:             result.optResult.rho_used,
                    std_inflation_factor: result.optResult.std_inflation_factor,
                };
                return _saveProduct(result, meta)
                    .then(function (data) {
                        if (data.error) errors++;
                        else _savedProductIds[result.product_id] = true;
                    })
                    .catch(function () { errors++; });
            });
        });

        chain.then(function () {
            if (errors === 0) {
                closeSaveModal();
            } else {
                // Rebuild list so already-saved items show "Saved ✓" even on partial failure.
                var toSaveAll = _prophetResults.filter(function (r) { return r.success && r.optResult && r.optInputs; });
                _buildSaveList(toSaveAll);
                if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Retry (' + errors + ' failed)'; }
                if (errEl)   { errEl.textContent = errors + ' product' + (errors === 1 ? '' : 's') + ' failed to save. Retry or check your connection.'; errEl.style.display = ''; }
            }
        });
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    function _esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // ── Init: wire row click handlers + restore session state on reload ────────

    document.addEventListener('DOMContentLoaded', function () {
        // Intercept row clicks in capture phase so batch mode takes priority
        // over the existing per-product forecast handler (bubble phase).
        document.querySelectorAll('.product-row[data-product-id]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (!_inSelectMode) return;
                e.stopImmediatePropagation();
                BatchForecast.toggleRow(parseInt(row.dataset.productId, 10));
            }, true);
        });

        // If the page was reloaded while in select mode (e.g. from a search),
        // re-enter batch mode and restore the previous selection.
        var shouldRestore = _loadFromStorage();
        if (shouldRestore) {
            _inSelectMode = false;
            enterMode();
        }
    });

    return {
        enterMode:            enterMode,
        exitMode:             exitMode,
        toggleRow:            toggleRow,
        selectAllVisible:     selectAllVisible,
        selectedCount:        selectedCount,
        openFcModal:          openFcModal,
        runProphet:           runProphet,
        confirmCloseChartModal: confirmCloseChartModal,
        closeChartModal:      closeChartModal,
        openNvModal:          openNvModal,
        closeNvModal:         closeNvModal,
        confirmRunNewsvendor: confirmRunNewsvendor,
        openSaveModal:        openSaveModal,
        closeSaveModal:       closeSaveModal,
        confirmSaveSelected:  confirmSaveSelected,
    };

}());
