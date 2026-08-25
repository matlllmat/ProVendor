<?php
// includes/batch_pricing_modal.php
// "Batch edit" table for the forecast page: fill in cost price, selling price and
// current stock for the whole catalogue in one pass, instead of opening each
// product's restock panel one at a time.
//
// Self-contained: it loads its own data, so any authenticated page can include it
// (the forecast page and the dashboard's Suggested Restock both do) without having
// to prepare variables first. Only $pdo and a session are required.
//
// Everything is pre-filled from the database. Rows are colour-coded the same way
// the per-product control is (green = the imported value, orange = customized),
// and every edit is revertable: per row back to the imported price, or all rows
// back to how they were when the table was opened.

require_once __DIR__ . '/../queries/forecast.query.php';

$_bpRows      = getProducts($pdo, (int) $_SESSION['user_id'], '', '');
// Stock isn't a column on `products` — it lives on the latest saved forecast.
$_bpForecasts = getLatestForecasts($pdo, (int) $_SESSION['user_id']);

// Products missing a usable price float to the top — that's the gap this exists
// to close, so it shouldn't be buried under products that are already fine.
usort($_bpRows, function ($a, $b) {
    $aMissing = ($a['cost_price'] === null || $a['selling_price'] === null) ? 0 : 1;
    $bMissing = ($b['cost_price'] === null || $b['selling_price'] === null) ? 0 : 1;
    if ($aMissing !== $bMissing) return $aMissing <=> $bMissing;
    return strcasecmp($a['name'], $b['name']);
});

$_bpMissing = 0;
foreach ($_bpRows as $r) {
    if ($r['cost_price'] === null || $r['selling_price'] === null) $_bpMissing++;
}
?>

<div id="bp-overlay" class="bp-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="bp-title">
    <div class="bp-modal">

        <div class="bp-head">
            <div>
                <h2 id="bp-title" class="bp-title">Batch edit prices &amp; stock</h2>
                <p class="bp-sub">
                    Fill in what the Newsvendor model needs for every product at once.
                    <?php if ($_bpMissing > 0): ?>
                    <strong class="bp-sub-warn"><?php echo $_bpMissing; ?> product<?php echo $_bpMissing === 1 ? '' : 's'; ?> still missing a price</strong> —
                    <?php endif; ?>
                    changes only save when you press Save.
                </p>
            </div>
            <button type="button" class="bp-close" onclick="bpClose()" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="bp-tools">
            <label class="bp-check">
                <input type="checkbox" id="bp-only-missing" onchange="bpApplyFilter()">
                Only products missing a price
            </label>
            <span class="bp-legend">
                <span class="bp-dot is-original"></span> From your data
                <span class="bp-dot is-custom"></span> Customized
            </span>
            <button type="button" class="bp-reset-all" onclick="bpResetAll()">Undo all changes</button>
        </div>

        <div class="bp-table-wrap">
            <table class="bp-table">
                <thead>
                    <tr>
                        <th class="bp-t-left">Product</th>
                        <th>Cost price</th>
                        <th>Selling price</th>
                        <th>Current stock</th>
                        <th class="bp-t-reset"></th>
                    </tr>
                </thead>
                <tbody id="bp-rows">
                    <?php foreach ($_bpRows as $p):
                        $pid   = (int) $p['id'];
                        $fc    = $_bpForecasts[$pid] ?? null;
                        $cost  = $p['cost_price']    !== null ? (float) $p['cost_price']    : null;
                        $price = $p['selling_price'] !== null ? (float) $p['selling_price'] : null;
                        $oCost = $p['orig_cost_price']    !== null ? (float) $p['orig_cost_price']    : null;
                        $oPrice= $p['orig_selling_price'] !== null ? (float) $p['orig_selling_price'] : null;
                        // Stock isn't stored on the product — it lives on the saved
                        // forecast, written whenever Newsvendor runs.
                        $stock = ($fc && $fc['current_stock'] !== null) ? (int) $fc['current_stock'] : 0;
                        $missing = ($cost === null || $price === null);
                    ?>
                    <tr class="bp-row<?php echo $missing ? ' is-missing' : ''; ?>"
                        data-id="<?php echo $pid; ?>"
                        data-missing="<?php echo $missing ? 1 : 0; ?>"
                        data-orig-cost="<?php echo $oCost !== null ? $oCost : ''; ?>"
                        data-orig-price="<?php echo $oPrice !== null ? $oPrice : ''; ?>"
                        data-start-cost="<?php echo $cost !== null ? $cost : ''; ?>"
                        data-start-price="<?php echo $price !== null ? $price : ''; ?>"
                        data-start-stock="<?php echo $stock; ?>">
                        <td class="bp-t-left">
                            <span class="bp-name"><?php echo htmlspecialchars($p['name']); ?></span>
                            <?php if ($missing): ?><span class="bp-badge">no price</span><?php endif; ?>
                        </td>
                        <td>
                            <span class="bp-input-wrap">
                                <span class="bp-affix">₱</span>
                                <input type="number" class="bp-input bp-cost" min="0" step="0.01"
                                       value="<?php echo $cost !== null ? number_format($cost, 2, '.', '') : ''; ?>"
                                       placeholder="0.00" oninput="bpRowChanged(this)">
                            </span>
                        </td>
                        <td>
                            <span class="bp-input-wrap">
                                <span class="bp-affix">₱</span>
                                <input type="number" class="bp-input bp-price" min="0" step="0.01"
                                       value="<?php echo $price !== null ? number_format($price, 2, '.', '') : ''; ?>"
                                       placeholder="0.00" oninput="bpRowChanged(this)">
                            </span>
                        </td>
                        <td>
                            <span class="bp-input-wrap">
                                <input type="number" class="bp-input bp-stock" min="0" step="1"
                                       value="<?php echo $stock; ?>" oninput="bpRowChanged(this)">
                                <span class="bp-affix bp-affix-right">units</span>
                            </span>
                        </td>
                        <td class="bp-t-reset">
                            <button type="button" class="bp-row-reset" title="Undo this row" onclick="bpResetRow(this)">↺</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="bp-empty" class="bp-empty" style="display:none">Every product already has a price.</div>
        </div>

        <div id="bp-msg" class="bp-msg" style="display:none"></div>

        <div class="bp-foot">
            <span id="bp-count" class="bp-count"></span>
            <div class="bp-foot-actions">
                <button type="button" class="bp-btn-cancel" onclick="bpClose()">Cancel</button>
                <button type="button" id="bp-save" class="bp-btn-save" onclick="bpSave()">Save changes</button>
            </div>
        </div>

    </div>
</div>

<script>
// ── Batch price/stock editor ────────────────────────────────────────────────
// Only CHANGED rows are submitted, so saving doesn't needlessly re-run the
// Newsvendor optimization for the whole catalogue.

function bpOpen() {
    document.getElementById('bp-overlay').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    bpRefreshAll();
}

function bpClose() {
    document.getElementById('bp-overlay').classList.add('hidden');
    document.body.style.overflow = '';
}

function _bpNum(v) {
    var n = parseFloat(v);
    return isNaN(n) ? null : n;
}

// Marks each field green (matches the imported value) or orange (customized),
// mirroring the language the per-product control already uses.
function bpMarkRow(row) {
    var cost  = row.querySelector('.bp-cost');
    var price = row.querySelector('.bp-price');
    var stock = row.querySelector('.bp-stock');

    var oCost  = _bpNum(row.dataset.origCost);
    var oPrice = _bpNum(row.dataset.origPrice);

    function mark(input, orig) {
        var v = _bpNum(input.value);
        input.classList.remove('is-original', 'is-custom');
        if (v === null || orig === null) return;
        input.classList.add(Math.abs(v - orig) < 0.005 ? 'is-original' : 'is-custom');
    }
    mark(cost, oCost);
    mark(price, oPrice);

    // Stock has no imported original — it's only ever entered by the owner — so it
    // is flagged against what it was when this table opened.
    var sStart = _bpNum(row.dataset.startStock);
    var sNow   = _bpNum(stock.value);
    stock.classList.toggle('is-custom', sStart !== null && sNow !== null && sNow !== sStart);

    row.classList.toggle('is-changed', bpRowIsChanged(row));
}

function bpRowIsChanged(row) {
    var c = _bpNum(row.querySelector('.bp-cost').value);
    var p = _bpNum(row.querySelector('.bp-price').value);
    var s = _bpNum(row.querySelector('.bp-stock').value);
    var sc = _bpNum(row.dataset.startCost);
    var sp = _bpNum(row.dataset.startPrice);
    var ss = _bpNum(row.dataset.startStock);

    var diff = function (a, b) {
        if (a === null && b === null) return false;
        if (a === null || b === null) return true;
        return Math.abs(a - b) >= 0.005;
    };
    return diff(c, sc) || diff(p, sp) || diff(s, ss);
}

function bpRowChanged(input) {
    bpMarkRow(input.closest('.bp-row'));
    bpUpdateCount();
}

// Undo one row back to the imported price + the stock it had on open.
function bpResetRow(btn) {
    var row = btn.closest('.bp-row');
    var oCost  = row.dataset.origCost;
    var oPrice = row.dataset.origPrice;
    row.querySelector('.bp-cost').value  = oCost  !== '' ? parseFloat(oCost).toFixed(2)  : row.dataset.startCost;
    row.querySelector('.bp-price').value = oPrice !== '' ? parseFloat(oPrice).toFixed(2) : row.dataset.startPrice;
    row.querySelector('.bp-stock').value = row.dataset.startStock;
    bpMarkRow(row);
    bpUpdateCount();
}

function bpResetAll() {
    document.querySelectorAll('#bp-rows .bp-row').forEach(function (row) {
        row.querySelector('.bp-cost').value  = row.dataset.startCost;
        row.querySelector('.bp-price').value = row.dataset.startPrice;
        row.querySelector('.bp-stock').value = row.dataset.startStock;
        bpMarkRow(row);
    });
    bpUpdateCount();
    _bpMsg('', null);
}

function bpRefreshAll() {
    document.querySelectorAll('#bp-rows .bp-row').forEach(bpMarkRow);
    bpUpdateCount();
    bpApplyFilter();
}

function bpUpdateCount() {
    var changed = document.querySelectorAll('#bp-rows .bp-row.is-changed').length;
    var el = document.getElementById('bp-count');
    if (el) el.textContent = changed === 0
        ? 'No changes yet'
        : changed + ' product' + (changed === 1 ? '' : 's') + ' changed';
    var btn = document.getElementById('bp-save');
    if (btn) btn.disabled = (changed === 0);
}

function bpApplyFilter() {
    var only = document.getElementById('bp-only-missing').checked;
    var shown = 0;
    document.querySelectorAll('#bp-rows .bp-row').forEach(function (row) {
        var show = !only || row.dataset.missing === '1';
        row.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    document.getElementById('bp-empty').style.display = shown === 0 ? '' : 'none';
}

function _bpMsg(text, type) {
    var el = document.getElementById('bp-msg');
    if (!el) return;
    if (!text) { el.style.display = 'none'; return; }
    el.className = 'bp-msg bp-msg-' + type;
    el.textContent = text;
    el.style.display = '';
}

function bpSave() {
    var items = [];
    var invalid = 0;

    document.querySelectorAll('#bp-rows .bp-row').forEach(function (row) {
        if (!bpRowIsChanged(row)) return;
        var c = _bpNum(row.querySelector('.bp-cost').value);
        var p = _bpNum(row.querySelector('.bp-price').value);
        var s = _bpNum(row.querySelector('.bp-stock').value);
        if (c === null || p === null || c <= 0 || p <= c) { invalid++; row.classList.add('is-invalid'); return; }
        row.classList.remove('is-invalid');
        items.push({ id: parseInt(row.dataset.id, 10), cost_price: c, selling_price: p, current_stock: s || 0 });
    });

    if (invalid > 0) {
        _bpMsg(invalid + ' row' + (invalid === 1 ? ' needs' : 's need') +
               ' a cost above 0 and a selling price higher than the cost.', 'error');
        return;
    }
    if (!items.length) { _bpMsg('Nothing to save.', 'error'); return; }

    var btn = document.getElementById('bp-save');
    btn.disabled = true;
    btn.textContent = 'Saving ' + items.length + '…';
    _bpMsg('Updating prices and recalculating restock…', 'info');

    fetch(BP_BASE + '/api/batch_update_products.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ items: items }),
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.textContent = 'Save changes';
            if (data.error) { _bpMsg(data.error, 'error'); return; }

            var bits = [data.updated + ' product' + (data.updated === 1 ? '' : 's') + ' updated'];
            if (data.repriced) bits.push(data.repriced + ' restock recalculated');
            if (data.skipped)  bits.push(data.skipped + ' not forecast yet');
            if (data.failed)   bits.push(data.failed + ' failed');
            _bpMsg(bits.join(' · ') + '. Reloading…', data.failed ? 'error' : 'success');

            // The product cards, chart and dashboard all read these numbers, so the
            // simplest correct refresh is a reload.
            setTimeout(function () { window.location.reload(); }, 1200);
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Save changes';
            _bpMsg('Network error. Please try again.', 'error');
        });
}

// Escape closes; clicking the backdrop closes.
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !document.getElementById('bp-overlay').classList.contains('hidden')) bpClose();
});
document.getElementById('bp-overlay').addEventListener('click', function (e) {
    if (e.target === this) bpClose();
});
</script>
