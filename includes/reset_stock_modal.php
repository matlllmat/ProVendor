<?php
// includes/reset_stock_modal.php
// "Reset current stocks" — clears the stock count for every product and asks the
// owner to key in what is actually on the shelf right now, in one pass.
//
// Self-contained like batch_pricing_modal.php: it loads its own data, so any
// authenticated page can include it with only $pdo and a session in scope. The
// forecast page and the dashboard both do.
//
// Nothing is written until Apply is pressed. Opening this, clearing every box and
// then closing it leaves the stored stock exactly as it was — a half-finished
// stock-take must never leave the catalogue sitting at zero, because zero stock
// inflates every restock recommendation the Newsvendor model makes.
//
// Saving goes through api/batch_update_products.php, the same endpoint the batch
// editor uses: it re-runs the Newsvendor optimization per product so the restock
// quantities follow the new stock figures. Each product's existing cost/selling
// price is sent back unchanged — only the stock moves.
//
// Chrome is styled with the shared bp-* classes from assets/global_css/app.css
// (the batch editor's); only the handful of rules unique to this screen are
// rs-* prefixed.

// The confirmation step uses showConfirm(). The forecast page already loads the
// modal that defines it, the dashboard does not — require_once here so this file
// works on any page that includes it, and never double-renders where it doesn't.
require_once __DIR__ . '/confirm_modal.php';
require_once __DIR__ . '/../queries/forecast.query.php';

$_rsRows      = getProducts($pdo, (int) $_SESSION['user_id'], '', '');
// Stock isn't a column on `products` — it lives on the latest saved forecast.
$_rsForecasts = getLatestForecasts($pdo, (int) $_SESSION['user_id']);

usort($_rsRows, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

// Split the catalogue into what can actually take a new stock figure and what
// can't. Stock is persisted by re-running the Newsvendor optimization, which
// needs a valid price AND an existing forecast — so a product missing either
// physically cannot store one. Those rows are shown disabled with the reason
// rather than hidden, so a product never silently goes missing.
$_rsReady = 0;
foreach ($_rsRows as $i => $p) {
    $cost  = $p['cost_price']    !== null ? (float) $p['cost_price']    : null;
    $price = $p['selling_price'] !== null ? (float) $p['selling_price'] : null;
    $fc    = $_rsForecasts[(int) $p['id']] ?? null;

    $blocked = null;
    if ($cost === null || $price === null || $cost <= 0 || $price <= $cost) {
        $blocked = 'needs a price';
    } elseif ($fc === null) {
        $blocked = 'not forecast yet';
    }

    $_rsRows[$i]['_cost']    = $cost;
    $_rsRows[$i]['_price']   = $price;
    $_rsRows[$i]['_stock']   = ($fc && $fc['current_stock'] !== null) ? (int) $fc['current_stock'] : 0;
    $_rsRows[$i]['_blocked'] = $blocked;

    if ($blocked === null) $_rsReady++;
}
$_rsBlocked = count($_rsRows) - $_rsReady;
?>

<div id="rs-overlay" class="bp-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="rs-title">
    <div class="bp-modal">

        <div class="bp-head">
            <div>
                <h2 id="rs-title" class="bp-title">Reset current stocks</h2>
                <p class="bp-sub">
                    Every stock box below has been cleared. Enter what is on the shelf now for each
                    product<?php if ($_rsBlocked > 0): ?>,
                    <strong class="bp-sub-warn"><?php echo $_rsBlocked; ?> can&rsquo;t be counted yet</strong><?php endif; ?>.
                    Nothing is saved until you press Apply.
                </p>
            </div>
            <button type="button" class="bp-close" onclick="rsClose()" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="bp-tools">
            <label class="bp-check">
                <input type="checkbox" id="rs-hide-blocked" onchange="rsApplyFilter()">
                Hide products that can&rsquo;t be counted
            </label>
            <span class="rs-tools-spacer"></span>
            <button type="button" class="bp-reset-all" onclick="rsClearAll()">Clear all to 0</button>
            <button type="button" class="bp-reset-all" onclick="rsRestoreAll()">Restore previous</button>
        </div>

        <div class="bp-table-wrap">
            <table class="bp-table">
                <thead>
                    <tr>
                        <th class="bp-t-left">Product</th>
                        <th>Previously</th>
                        <th>New stock</th>
                    </tr>
                </thead>
                <tbody id="rs-rows">
                    <?php foreach ($_rsRows as $p):
                        $pid     = (int) $p['id'];
                        $blocked = $p['_blocked'];
                    ?>
                    <tr class="bp-row<?php echo $blocked ? ' rs-blocked' : ''; ?>"
                        data-id="<?php echo $pid; ?>"
                        data-blocked="<?php echo $blocked ? 1 : 0; ?>"
                        data-cost="<?php echo $p['_cost']  !== null ? $p['_cost']  : ''; ?>"
                        data-price="<?php echo $p['_price'] !== null ? $p['_price'] : ''; ?>"
                        data-prev-stock="<?php echo $p['_stock']; ?>">
                        <td class="bp-t-left">
                            <span class="bp-name"><?php echo htmlspecialchars($p['name']); ?></span>
                            <?php if ($blocked): ?>
                            <span class="bp-badge"><?php echo $blocked; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="rs-prev"><?php echo number_format($p['_stock']); ?></td>
                        <td>
                            <?php if ($blocked): ?>
                            <span class="rs-na">&mdash;</span>
                            <?php else: ?>
                            <span class="bp-input-wrap">
                                <input type="number" class="bp-input rs-stock" min="0" step="1"
                                       value="0" oninput="rsRowChanged(this)"
                                       onkeydown="rsEnterNext(event, this)"
                                       aria-label="New stock for <?php echo htmlspecialchars($p['name']); ?>">
                                <span class="bp-affix bp-affix-right">units</span>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="rs-empty" class="bp-empty" style="display:none">No products to show.</div>
        </div>

        <div id="rs-msg" class="bp-msg" style="display:none"></div>

        <div class="bp-foot">
            <span id="rs-count" class="bp-count"></span>
            <div class="bp-foot-actions">
                <button type="button" class="bp-btn-cancel" onclick="rsClose()">Cancel</button>
                <button type="button" id="rs-save" class="bp-btn-save" onclick="rsApply()">Apply new stock levels</button>
            </div>
        </div>

    </div>
</div>

<script>
// ── Reset current stocks ────────────────────────────────────────────────────
(function () {
    var BASE = '<?php echo BASE_URL; ?>';

    // Step 1 — the confirmation. The button is destructive-sounding by design, so
    // say plainly what happens (and what doesn't) before opening the screen.
    window.rsConfirm = function () {
        var ready = <?php echo (int) $_rsReady; ?>;

        if (ready === 0) {
            showConfirm({
                title:        'Nothing can be counted yet',
                message:      'No product has both a price and a saved forecast, so there is nowhere to '
                            + 'record a stock count. Set prices with Batch edit and run a forecast first.',
                confirmText:  'Got it',
                confirmStyle: 'primary',
                onConfirm:    function () {},
            });
            return;
        }

        showConfirm({
            title:        'Reset current stocks?',
            message:      'The stock count for ' + ready + ' product' + (ready === 1 ? '' : 's')
                        + ' will be cleared to 0 and you will enter the new figures. '
                        + 'Nothing is saved until you press Apply, so you can back out at any point.',
            confirmText:  'Reset and count',
            confirmStyle: 'warning',
            onConfirm:    rsOpen,
        });
    };

    // Step 2 — the stock-take screen, opened already cleared.
    window.rsOpen = function () {
        rsClearAll();
        _rsMsg('', null);
        document.getElementById('rs-hide-blocked').checked = false;
        rsApplyFilter();
        document.getElementById('rs-overlay').classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        var first = document.querySelector('#rs-rows .rs-stock');
        if (first) first.focus();
    };

    window.rsClose = function () {
        document.getElementById('rs-overlay').classList.add('hidden');
        // Opened from inside the batch editor, which stays open underneath — only
        // give the page its scroll back once nothing is layered over it.
        var bp = document.getElementById('bp-overlay');
        if (!bp || bp.classList.contains('hidden')) document.body.style.overflow = '';
    };

    function _rsNum(v) {
        var n = parseInt(v, 10);
        return isNaN(n) ? null : n;
    }

    function _rsInputs() {
        return Array.prototype.slice.call(document.querySelectorAll('#rs-rows .rs-stock'));
    }

    // The reset itself — every counted product back to zero, ready to be keyed in.
    window.rsClearAll = function () {
        _rsInputs().forEach(function (i) { i.value = '0'; i.classList.remove('is-custom'); });
        rsUpdateCount();
    };

    // Escape hatch: put the previous figures back so a mis-click costs nothing.
    window.rsRestoreAll = function () {
        _rsInputs().forEach(function (i) {
            var row = i.closest('.bp-row');
            i.value = row.dataset.prevStock;
            i.classList.remove('is-custom');
        });
        rsUpdateCount();
    };

    // Enter jumps to the next stock box. A stock-take is a long run of typed
    // numbers, and reaching for the mouse between each one is the slow part.
    // Rows hidden by the filter are skipped, and the contents of the box we land
    // on are selected so typing replaces the cleared 0 instead of appending to it.
    window.rsEnterNext = function (e, input) {
        if (e.key !== 'Enter') return;
        e.preventDefault();   // nothing should submit from inside the table

        var visible = _rsInputs().filter(function (i) {
            return i.closest('.bp-row').style.display !== 'none';
        });

        var next = visible[visible.indexOf(input) + 1];
        if (!next) {
            // Last box — stay put rather than wrapping round, which would send the
            // owner back to the top of a long catalogue without them asking.
            input.select();
            return;
        }
        next.focus();
        next.select();
    };

    window.rsRowChanged = function (input) {
        var row = input.closest('.bp-row');
        var now = _rsNum(input.value);
        input.classList.toggle('is-custom', now !== null && now !== _rsNum(row.dataset.prevStock));
        rsUpdateCount();
    };

    function rsUpdateCount() {
        var changed = 0;
        _rsInputs().forEach(function (i) {
            var row = i.closest('.bp-row');
            var now = _rsNum(i.value);
            if (now !== null && now !== _rsNum(row.dataset.prevStock)) changed++;
        });

        var el = document.getElementById('rs-count');
        if (el) el.textContent = changed === 0
            ? 'No stock figures changed yet'
            : changed + ' product' + (changed === 1 ? '' : 's') + ' will change';
    }

    window.rsApplyFilter = function () {
        var hide  = document.getElementById('rs-hide-blocked').checked;
        var shown = 0;
        document.querySelectorAll('#rs-rows .bp-row').forEach(function (row) {
            var show = !(hide && row.dataset.blocked === '1');
            row.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        document.getElementById('rs-empty').style.display = shown === 0 ? '' : 'none';
    };

    function _rsMsg(text, type) {
        var el = document.getElementById('rs-msg');
        if (!el) return;
        if (!text) { el.style.display = 'none'; return; }
        el.className = 'bp-msg bp-msg-' + type;
        el.textContent = text;
        el.style.display = '';
    }

    // Step 3 — write. Every countable product is sent, not just the edited ones:
    // this is a stock-take, so an untouched box reading 0 genuinely means zero.
    window.rsApply = function () {
        var items   = [];
        var invalid = 0;

        document.querySelectorAll('#rs-rows .bp-row').forEach(function (row) {
            if (row.dataset.blocked === '1') return;

            var input = row.querySelector('.rs-stock');
            var stock = _rsNum(input.value);

            if (stock === null || stock < 0) {
                invalid++;
                row.classList.add('is-invalid');
                return;
            }
            row.classList.remove('is-invalid');

            // Price is resent untouched — this endpoint saves pricing and stock
            // together, and we are only moving the stock.
            items.push({
                id:            parseInt(row.dataset.id, 10),
                cost_price:    parseFloat(row.dataset.cost),
                selling_price: parseFloat(row.dataset.price),
                current_stock: stock,
            });
        });

        if (invalid > 0) {
            _rsMsg(invalid + ' product' + (invalid === 1 ? ' needs' : 's need')
                 + ' a stock count of 0 or more.', 'error');
            return;
        }
        if (!items.length) { _rsMsg('There is nothing to apply.', 'error'); return; }

        var btn = document.getElementById('rs-save');
        btn.disabled    = true;
        btn.textContent = 'Applying ' + items.length + '…';
        _rsMsg('Saving stock counts and recalculating restock quantities…', 'info');

        fetch(BASE + '/api/batch_update_products.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ items: items }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled    = false;
                btn.textContent = 'Apply new stock levels';
                if (data.error) { _rsMsg(data.error, 'error'); return; }

                var bits = [data.updated + ' product' + (data.updated === 1 ? '' : 's') + ' counted'];
                if (data.repriced) bits.push(data.repriced + ' restock recalculated');
                if (data.failed)   bits.push(data.failed + ' failed');
                _rsMsg(bits.join(' · ') + '. Reloading…', data.failed ? 'error' : 'success');

                // Restock quantities, order values and the dashboard KPIs all derive
                // from stock, so a reload is the simplest correct refresh.
                setTimeout(function () { window.location.reload(); }, 1200);
            })
            .catch(function () {
                btn.disabled    = false;
                btn.textContent = 'Apply new stock levels';
                _rsMsg('Network error. Please try again.', 'error');
            });
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !document.getElementById('rs-overlay').classList.contains('hidden')) rsClose();
    });
    document.getElementById('rs-overlay').addEventListener('click', function (e) {
        if (e.target === this) rsClose();
    });
}());
</script>
