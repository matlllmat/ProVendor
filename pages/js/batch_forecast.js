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
//   batch-nv-modal → table of products with inputs → confirm → batch Newsvendor API
//   results shown inline → auto-saved to DB

var BatchForecast = (function () {

    // ── Shared state ──────────────────────────────────────────────────────────

    var _inSelectMode = false;

    // id (string) → { id, name, sku, cost_price, selling_price, current_stock, db_cost, db_price }
    var _products = {};

    // Ordered list of selected product IDs (matches product-list display order).
    var _selectedIds = [];

    // Results from the last batch Prophet run: [{ product_id, product_name, success, forecast, historical }]
    var _prophetResults = [];

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

    function _updateToolbar() {
        var count   = _selectedIds.length;
        var countEl = document.getElementById('batch-selection-count');
        var nextBtn = document.getElementById('batch-next-btn');
        if (countEl) countEl.textContent = count + ' selected';
        if (nextBtn) nextBtn.disabled    = count === 0;
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
            onGenerateRestock:   null, // hides the per-product Newsvendor section
            onSave:              null,
        });
    }

    function closeChartModal() {
        ChartModal.destroyIn();
        var modal = document.getElementById('batch-chart-modal');
        if (modal) modal.classList.add('hidden');
        document.body.style.overflow = '';
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

            html += '<tr class="bnv-row" data-pid="' + r.product_id + '">'
                + '<td class="bnv-td bnv-td-name">' + _esc(r.product_name) + '</td>'
                + '<td class="bnv-td">'
                    + '<div class="bnv-input-wrap"><span class="bnv-input-affix">₱</span>'
                    + '<input type="number" class="bnv-input bnv-cost" min="0" step="0.01" placeholder="0.00"'
                    + (dbCost ? ' value="' + dbCost + '"' : '') + '>'
                    + '</div>'
                    + (dbCost ? '<p class="bnv-hint">From profile</p>' : '')
                + '</td>'
                + '<td class="bnv-td">'
                    + '<div class="bnv-input-wrap"><span class="bnv-input-affix">₱</span>'
                    + '<input type="number" class="bnv-input bnv-price" min="0" step="0.01" placeholder="0.00"'
                    + (dbPrice ? ' value="' + dbPrice + '"' : '') + '>'
                    + '</div>'
                    + (dbPrice ? '<p class="bnv-hint">From profile</p>' : '')
                + '</td>'
                + '<td class="bnv-td">'
                    + '<div class="bnv-input-wrap">'
                    + '<input type="number" class="bnv-input bnv-stock" min="0" step="1" placeholder="0" value="0">'
                    + '<span class="bnv-input-affix bnv-input-affix-right">units</span>'
                    + '</div>'
                + '</td>'
                + '<td class="bnv-td" id="bnv-status-' + r.product_id + '">'
                    + '<span class="bnv-status-pending">—</span>'
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
            + 'Results will be saved to Reports automatically.';
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

    function _runNewsvendor() {
        var successes = _prophetResults.filter(function (r) { return r.success; });

        var productsPayload = [];
        successes.forEach(function (r) {
            var row = document.querySelector('#bnv-body .bnv-row[data-pid="' + r.product_id + '"]');
            if (!row) return;
            var cost  = parseFloat(row.querySelector('.bnv-cost').value)  || 0;
            var price = parseFloat(row.querySelector('.bnv-price').value) || 0;
            var stock = parseInt(row.querySelector('.bnv-stock').value, 10) || 0;

            if (cost > 0 && price > 0 && price > cost) {
                productsPayload.push({
                    id:            r.product_id,
                    cost_price:    cost,
                    selling_price: price,
                    current_stock: stock,
                    forecast:      r.forecast,
                });
                var statusEl = document.getElementById('bnv-status-' + r.product_id);
                if (statusEl) statusEl.innerHTML = '<span class="bnv-status-running">Running…</span>';
            } else {
                var statusEl = document.getElementById('bnv-status-' + r.product_id);
                if (statusEl) statusEl.innerHTML = '<span class="bnv-status-skip">Skipped — missing price</span>';
            }
        });

        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = true; genBtn.textContent = 'Generating…'; }

        if (!productsPayload.length) {
            _showNvError('No products with valid prices to generate.');
            return;
        }

        fetch(BATCH_BASE_URL + '/api/run_batch_newsvendor.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ products: productsPayload }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { _showNvError(data.error); return; }
            _showNvResults(data.results || []);
        })
        .catch(function () { _showNvError('Network error. Please try again.'); });
    }

    function _showNvError(msg) {
        var errEl = document.getElementById('bnv-error');
        if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = false; genBtn.textContent = 'Generate All →'; }
    }

    function _showNvResults(results) {
        var genBtn = document.getElementById('bnv-gen-btn');
        if (genBtn) { genBtn.disabled = true; genBtn.textContent = 'Done ✓'; }

        results.forEach(function (r) {
            var statusEl = document.getElementById('bnv-status-' + r.product_id);
            if (!statusEl) return;

            if (r.success) {
                statusEl.innerHTML =
                      '<span class="bnv-status-ok">Saved ✓</span>'
                    + '<span class="bnv-result-detail">'
                    + 'Order: <strong>' + Number(r.restock_qty).toLocaleString() + ' units</strong>'
                    + ' &nbsp;·&nbsp; ₱' + Number(r.est_profit).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' profit'
                    + '</span>';
            } else {
                statusEl.innerHTML = '<span class="bnv-status-err">Failed: ' + _esc(r.error || 'Unknown error') + '</span>';
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
        closeChartModal:      closeChartModal,
        openNvModal:          openNvModal,
        closeNvModal:         closeNvModal,
        confirmRunNewsvendor: confirmRunNewsvendor,
    };

}());
