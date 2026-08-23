// pages/js/autorun_forecast.js
// Reusable auto-forecast engine, shared by:
//   • landing.view.php — runs automatically right after the first CSV import
//   • import.view.php   — re-runs after a re-import (Sales Data tab) and when the
//                         forecast horizon changes (Forecast Range tab)
//   • forecast.view.php — generates one product on demand
//
// It is DOM-agnostic: callers pass the product list + a horizon (days) and supply
// callbacks for progress / completion, then render whatever UI they want.
//
// Requires a global AUTO_BASE_URL (base URL for API calls). csrf.js adds the
// CSRF header to every fetch, so the JSON POSTs below are covered.
//
// For each product, sequentially (so progress advances one at a time and the
// server isn't hammered): run_batch_prophet (Prophet) → run_batch_newsvendor
// (Newsvendor at current_stock = 0, which also SAVES). Products with no valid
// price still get their demand forecast saved, just with no restock quantity.

var AutoForecast = (function () {

    function _toYMD(d) {
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    // ── Horizon → { from, to } ────────────────────────────────────────────────
    // A rolling window of `days` starting today (fixed once generated). `from` is
    // clamped to the day after the last sale so Prophet only projects forward —
    // for a live store today is already past the last sale, so from = today.
    function computeDayRange(days, lastSaleDate) {
        days = Math.max(1, Math.min(60, parseInt(days, 10) || 30));

        var from = new Date();
        from.setHours(0, 0, 0, 0);

        if (lastSaleDate) {
            var minDt = new Date(lastSaleDate + 'T00:00:00');
            minDt.setDate(minDt.getDate() + 1);
            if (from < minDt) from = minDt;
        }

        var to = new Date(from);
        to.setDate(to.getDate() + days - 1);   // `days` inclusive of `from`

        return { from: _toYMD(from), to: _toYMD(to) };
    }

    // ── Forecast one product: Prophet → Newsvendor (saves) ────────────────────
    // Resolves { ok, id, demand, order } — ok=false on any failure (the caller
    // keeps going so one bad product doesn't halt the whole catalogue).
    function _forecastOne(product, fromDate, toDate) {
        return fetch(AUTO_BASE_URL + '/api/run_batch_prophet.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ product_ids: [product.id], from_date: fromDate, to_date: toDate }),
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            var res = resp.results && resp.results[0];
            if (resp.error || !res || !res.success || !res.forecast || !res.forecast.length) {
                return { ok: false, id: product.id };
            }
            return fetch(AUTO_BASE_URL + '/api/run_batch_newsvendor.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ products: [{
                    id:            product.id,
                    cost_price:    product.cost  || 0,
                    selling_price: product.price || 0,
                    current_stock: 0,
                    forecast:      res.forecast,
                }] }),
            })
            .then(function (r) { return r.json(); })
            .then(function (nv) {
                var out = nv.results && nv.results[0];
                if (nv.error || !out || !out.success) return { ok: false, id: product.id };
                return { ok: true, id: product.id, demand: out.total_predicted, order: out.restock_qty };
            });
        })
        .catch(function () { return { ok: false, id: product.id }; });
    }

    // ── Run the whole catalogue ───────────────────────────────────────────────
    // products     : [{ id, name, cost, price, horizon? }]  — horizon overrides defaultDays for that product
    // defaultDays  : the user's global horizon
    // lastSaleDate : 'YYYY-MM-DD' or null (clamps `from`)
    // cb           : { onProgress(done,total,productName), onProduct(result), onDone({total,failed}) }
    function run(products, defaultDays, lastSaleDate, cb) {
        cb = cb || {};
        var total  = products.length;
        var done   = 0;
        var failed = 0;

        if (cb.onProgress) cb.onProgress(0, total, null);

        var chain = Promise.resolve();
        products.forEach(function (product) {
            chain = chain.then(function () {
                if (cb.onProgress) cb.onProgress(done, total, product.name);
                var range = computeDayRange(product.horizon || defaultDays, lastSaleDate);
                return _forecastOne(product, range.from, range.to).then(function (result) {
                    done++;
                    if (!result.ok) failed++;
                    if (cb.onProduct)  cb.onProduct(result);
                    if (cb.onProgress) cb.onProgress(done, total, product.name);
                });
            });
        });

        return chain.then(function () {
            var summary = { total: total, failed: failed };
            if (cb.onDone) cb.onDone(summary);
            return summary;
        });
    }

    return {
        computeDayRange: computeDayRange,
        run:             run,
    };

}());
