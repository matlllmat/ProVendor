// pages/js/demand_calendar.js
// Calendar view for Demand Analysis — a plain-DOM alternative to the line chart.
// Answers "how much will I sell on this day?" without reading a graph.
//
//   DemandCalendar.renderIn(container, { days, min_date, max_date, forecast_products })
//   DemandCalendar.destroy()
//
// `days` is the date-keyed map from api/get_demand_calendar.php:
//     { 'YYYY-MM-DD': { a: actual|null, p: predicted|null } }
//
// No Chart.js and no network calls of its own — it renders whatever data it is
// handed, so it works even when the forecast server is down. It owns its own
// granularity / period-cursor state; the host page only supplies data.

var DemandCalendar = (function () {

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                  'July', 'August', 'September', 'October', 'November', 'December'];
    var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    var _st = {
        el: null,
        days: {},
        minDate: null,
        maxDate: null,
        forecastProducts: 0,
        gran: 'day',        // 'day' | 'month' | 'year'
        year: null,
        month: null,        // 0-11
    };

    // ── date helpers (all local, no UTC drift) ────────────────────────────────
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + pad2(m + 1) + '-' + pad2(d); }
    function todayYMD() {
        var t = new Date();
        return ymd(t.getFullYear(), t.getMonth(), t.getDate());
    }
    function daysInMonth(y, m) { return new Date(y, m + 1, 0).getDate(); }
    function fmt(n) { return Math.round(n).toLocaleString('en-US'); }

    // What a single day holds. Actuals stop where the forecast window begins, so a
    // day is one or the other; actual wins in the rare case a day has both.
    function dayValue(entry) {
        if (!entry) return null;
        if (entry.a != null) return { v: entry.a, kind: 'actual' };
        if (entry.p != null) return { v: entry.p, kind: 'forecast' };
        return null;
    }

    // Sums every day inside [from, to] (inclusive, 'YYYY-MM-DD' strings).
    // Returns null when the period holds no data at all. A period spanning the
    // handover (last actual → first forecast day) totals both and is coloured by
    // whichever dominates.
    function sumRange(from, to) {
        var a = 0, p = 0, any = false;
        for (var key in _st.days) {
            if (key < from || key > to) continue;
            var val = dayValue(_st.days[key]);
            if (!val) continue;
            any = true;
            if (val.kind === 'actual') a += val.v; else p += val.v;
        }
        if (!any) return null;
        return { v: a + p, kind: p > a ? 'forecast' : 'actual' };
    }

    // 1–5 shading bucket relative to the busiest cell on screen, so the scale
    // always uses its full range whatever the period's magnitude.
    function heat(v, max) {
        if (!max || v <= 0) return 1;
        return Math.min(5, Math.max(1, Math.ceil(v / max * 5)));
    }

    // `date` (optional) keys the hover breakdown; `hasEvent` draws the marker dot.
    function cellHTML(label, val, extraClass, title, date, hasEvent) {
        var lbl = (label !== null) ? '<span class="dc-label">' + label + '</span>' : '';
        if (!val) {
            return '<div class="dc-cell is-empty' + extraClass + '">' + lbl + '</div>';
        }
        var dot = hasEvent ? '<span class="dc-eventdot" aria-hidden="true"></span>' : '';
        // Days with a breakdown use the rich hover panel instead of a native title,
        // so the two don't stack on top of each other.
        var attrs = date
            ? ' data-date="' + date + '"'
            : ' title="' + title + '"';
        return '<div class="dc-cell is-' + val.kind + ' is-heat-' + val.heat +
               (hasEvent ? ' has-event' : '') + extraClass + '"' + attrs + '>' +
               dot + lbl + '<span class="dc-val">' + fmt(val.v) + '</span></div>';
    }

    // Events active on a date (or anywhere in a range), summed. [[name, value], ...]
    function eventsIn(from, to) {
        var acc = {}, any = false;
        for (var key in _st.days) {
            if (key < from || key > to) continue;
            var c = _st.days[key].c;
            if (!c || !c.ev || !c.ev.length) continue;
            c.ev.forEach(function (pair) { acc[pair[0]] = (acc[pair[0]] || 0) + pair[1]; any = true; });
        }
        if (!any) return [];
        return Object.keys(acc).map(function (n) { return [n, acc[n]]; });
    }

    // ── grids ────────────────────────────────────────────────────────────────
    function dayGrid() {
        var y = _st.year, m = _st.month;
        var total = daysInMonth(y, m);
        var lead  = new Date(y, m, 1).getDay();
        var today = todayYMD();

        var vals = [], max = 0, d;
        for (d = 1; d <= total; d++) {
            var v0 = dayValue(_st.days[ymd(y, m, d)]);
            vals.push(v0);
            if (v0 && v0.v > max) max = v0.v;
        }

        var html = '<div class="dc-grid dc-grid-day">';
        DOW.forEach(function (n) { html += '<div class="dc-dow">' + n + '</div>'; });
        for (var i = 0; i < lead; i++) html += '<div class="dc-cell is-blank"></div>';

        for (d = 1; d <= total; d++) {
            var v    = vals[d - 1];
            var date = ymd(y, m, d);
            if (v) v.heat = heat(v.v, max);
            var cls = (date === today ? ' is-today' : '');
            var tip = v
                ? MONTHS_SHORT[m] + ' ' + d + ', ' + y + ' — ' + fmt(v.v) + ' units (' +
                  (v.kind === 'actual' ? 'actual sales' : 'forecast') + ')'
                : MONTHS_SHORT[m] + ' ' + d + ', ' + y + ' — no data';
            var entry    = _st.days[date];
            var hasEvent = !!(entry && entry.c && entry.c.ev && entry.c.ev.length);
            html += cellHTML(d, v, cls, tip, v ? date : null, hasEvent);
        }
        return html + '</div>';
    }

    function monthGrid() {
        var y = _st.year;
        var cells = [], max = 0, m;
        for (m = 0; m < 12; m++) {
            var val = sumRange(ymd(y, m, 1), ymd(y, m, daysInMonth(y, m)));
            cells.push(val);
            if (val && val.v > max) max = val.v;
        }
        var html = '<div class="dc-grid dc-grid-month">';
        for (m = 0; m < 12; m++) {
            var v = cells[m];
            if (v) v.heat = heat(v.v, max);
            var evs = eventsIn(ymd(y, m, 1), ymd(y, m, daysInMonth(y, m)));
            var tip = MONTHS[m] + ' ' + y + (v ? ' — ' + fmt(v.v) + ' units total' : ' — no data');
            if (evs.length) tip += ' · events: ' + evs.map(function (e) { return e[0]; }).join(', ');
            html += cellHTML(MONTHS_SHORT[m], v, '', tip, null, evs.length > 0);
        }
        return html + '</div>';
    }

    function yearGrid() {
        var y0 = parseInt(_st.minDate.slice(0, 4), 10);
        var y1 = parseInt(_st.maxDate.slice(0, 4), 10);
        var cells = [], max = 0, y;
        for (y = y0; y <= y1; y++) {
            var val = sumRange(y + '-01-01', y + '-12-31');
            cells.push({ y: y, val: val });
            if (val && val.v > max) max = val.v;
        }
        var html = '<div class="dc-grid dc-grid-year">';
        cells.forEach(function (c) {
            if (c.val) c.val.heat = heat(c.val.v, max);
            var tip = c.y + (c.val ? ' — ' + fmt(c.val.v) + ' units total' : ' — no data');
            html += cellHTML(c.y, c.val, '', tip);
        });
        return html + '</div>';
    }

    // ── chrome ───────────────────────────────────────────────────────────────
    function periodLabel() {
        if (_st.gran === 'day')   return MONTHS[_st.month] + ' ' + _st.year;
        if (_st.gran === 'month') return String(_st.year);
        return _st.minDate.slice(0, 4) + ' – ' + _st.maxDate.slice(0, 4);
    }

    function pill(group, value, active, label) {
        return '<button type="button" class="dc-pill' + (active ? ' is-on' : '') +
               '" data-' + group + '="' + value + '">' + label + '</button>';
    }

    function toolbarHTML() {
        var navOff = _st.gran === 'year';   // the year view already shows every year
        return '<div class="dc-toolbar">' +
                 '<div class="dc-pillgroup">' +
                   pill('gran', 'day',   _st.gran === 'day',   'Day') +
                   pill('gran', 'month', _st.gran === 'month', 'Month') +
                   pill('gran', 'year',  _st.gran === 'year',  'Year') +
                 '</div>' +
                 '<div class="dc-nav">' +
                   '<button type="button" class="dc-nav-btn" data-step="-1"' +
                     (navOff ? ' disabled' : '') + ' aria-label="Previous">&lsaquo;</button>' +
                   '<span class="dc-period">' + periodLabel() + '</span>' +
                   '<button type="button" class="dc-nav-btn" data-step="1"' +
                     (navOff ? ' disabled' : '') + ' aria-label="Next">&rsaquo;</button>' +
                 '</div>' +
               '</div>';
    }

    function legendHTML() {
        return '<div class="dc-legend">' +
                 '<span class="dc-legend-item"><span class="dc-swatch is-actual"></span> Actual sales</span>' +
                 '<span class="dc-legend-item"><span class="dc-swatch is-forecast"></span> Forecast</span>' +
                 '<span class="dc-legend-hint">Darker = busier</span>' +
               '</div>';
    }

    // Aggregate forecast is a sum across products, so name the count rather than
    // letting a partly-covered day read as a real dip.
    function captionHTML() {
        if (_st.forecastProducts > 1) {
            return '<p class="dc-caption">Forecast figures are summed across ' +
                   _st.forecastProducts + ' forecast products.</p>';
        }
        return '';
    }

    // ── "Why this number" hover panel ────────────────────────────────────────
    // Prophet builds each forecast day as trend + weekly + yearly + Σ events, and
    // that decomposition is saved per day — this surfaces it, so a spike on the
    // calendar can be traced to the event that caused it.
    function signed(n) {
        var r = Math.round(n);
        return (r > 0 ? '+' : r < 0 ? '−' : '') + fmt(Math.abs(r));
    }

    function tipHTML(date) {
        var entry = _st.days[date];
        if (!entry) return '';
        var val = dayValue(entry);
        if (!val) return '';

        var dt   = new Date(date + 'T00:00:00');
        var head = MONTHS_SHORT[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear();
        var kind = (val.kind === 'actual' ? 'actual sales' : 'forecast');
        var html = '<div class="dc-tip-head">' + head + ' &middot; <strong>' + fmt(val.v) +
                   '</strong> units <span class="dc-tip-kind">' + kind + '</span></div>';

        var c = entry.c;
        // Actuals have no model breakdown, and a decomposition is only shown when it
        // actually accounts for the day's total (see getDemandCalendarData).
        var canExplain = (val.kind === 'forecast') && c &&
                         Math.abs(c.bt - val.v) <= Math.max(1, val.v * 0.02);

        if (canExplain) {
            html += '<div class="dc-tip-rows">' +
                      row('Baseline trend', c.t) +
                      row('Day of week',    c.w) +
                      row('Season',         c.y);
            (c.ev || []).forEach(function (e) { html += row(e[0], e[1], true); });
            html += '</div><div class="dc-tip-total"><span>Predicted</span><span>' +
                    fmt(val.v) + ' units</span></div>';
        } else if (c && c.ev && c.ev.length) {
            // No usable decomposition, but the active events are still worth naming.
            html += '<div class="dc-tip-rows">';
            c.ev.forEach(function (e) { html += row(e[0], e[1], true); });
            html += '</div>';
        }
        return html;

        function row(label, value, isEvent) {
            return '<div class="dc-tip-row' + (isEvent ? ' is-event' : '') + '">' +
                     '<span>' + label + '</span><span>' + signed(value) + '</span>' +
                   '</div>';
        }
    }

    function hideTip() {
        var tip = _st.el && _st.el.querySelector('.dc-tip');
        if (tip) tip.classList.remove('is-on');
    }

    function wireTips() {
        var tip = document.createElement('div');
        tip.className = 'dc-tip';
        _st.el.appendChild(tip);

        _st.el.querySelectorAll('.dc-cell[data-date]').forEach(function (cell) {
            cell.addEventListener('mouseenter', function () {
                var html = tipHTML(cell.dataset.date);
                if (!html) return;
                tip.innerHTML = html;
                tip.classList.add('is-on');

                // Anchor above the cell, clamped inside the calendar so edge cells
                // don't push the panel off-screen.
                var host = _st.el.getBoundingClientRect();
                var box  = cell.getBoundingClientRect();
                var left = box.left - host.left + box.width / 2 - tip.offsetWidth / 2;
                left = Math.max(4, Math.min(left, host.width - tip.offsetWidth - 4));
                var top  = box.top - host.top - tip.offsetHeight - 8;
                if (top < 0) top = box.bottom - host.top + 8;   // flip below near the top
                tip.style.left = left + 'px';
                tip.style.top  = top + 'px';
            });
            cell.addEventListener('mouseleave', hideTip);
        });
    }

    function render() {
        if (!_st.el) return;
        if (!_st.minDate) {
            _st.el.innerHTML = '<div class="dc-empty">No demand data to show yet.</div>';
            return;
        }
        var grid = _st.gran === 'day'   ? dayGrid()
                 : _st.gran === 'month' ? monthGrid()
                 : yearGrid();
        _st.el.innerHTML = toolbarHTML() + legendHTML() + grid + captionHTML();
        wire();
        wireTips();
    }

    // Keeps the cursor inside the data range so navigation can't wander off into
    // empty months forever.
    function clampCursor() {
        var minY = parseInt(_st.minDate.slice(0, 4), 10);
        var minM = parseInt(_st.minDate.slice(5, 7), 10) - 1;
        var maxY = parseInt(_st.maxDate.slice(0, 4), 10);
        var maxM = parseInt(_st.maxDate.slice(5, 7), 10) - 1;

        if (_st.gran === 'month') {
            _st.year = Math.min(maxY, Math.max(minY, _st.year));
            return;
        }
        var cur = Math.min(maxY * 12 + maxM, Math.max(minY * 12 + minM, _st.year * 12 + _st.month));
        _st.year  = Math.floor(cur / 12);
        _st.month = cur % 12;
    }

    function wire() {
        _st.el.querySelectorAll('[data-gran]').forEach(function (b) {
            b.addEventListener('click', function () {
                _st.gran = b.dataset.gran;
                clampCursor();
                render();
            });
        });
        _st.el.querySelectorAll('[data-step]').forEach(function (b) {
            b.addEventListener('click', function () {
                var step = parseInt(b.dataset.step, 10);
                if (_st.gran === 'month') {
                    _st.year += step;
                } else {
                    _st.month += step;
                    if (_st.month > 11) { _st.month = 0;  _st.year++; }
                    if (_st.month < 0)  { _st.month = 11; _st.year--; }
                }
                clampCursor();
                render();
            });
        });
    }

    // ── public ───────────────────────────────────────────────────────────────
    // First render opens on the month containing today when that falls inside the
    // data range, otherwise the nearest month with data — so the owner lands on "now".
    //
    // A RE-render (switching product/category) keeps the granularity and month the
    // owner was already looking at, so swapping products doesn't also yank them
    // back to today in Day view. The cursor is re-clamped in case the new
    // scope covers a different range.
    function renderIn(container, data) {
        var isRerender = (_st.el === container && _st.minDate !== null);

        _st.el               = container;
        _st.days             = (data && data.days)              || {};
        _st.minDate          = (data && data.min_date)          || null;
        _st.maxDate          = (data && data.max_date)          || null;
        _st.forecastProducts = (data && data.forecast_products) || 0;

        if (_st.minDate) {
            if (!isRerender) {
                var t = new Date();
                _st.year  = t.getFullYear();
                _st.month = t.getMonth();
            }
            clampCursor();
        }
        render();
        setLoading(false);
    }

    // Dims the calendar in place while new data is fetched. Deliberately does NOT
    // touch the DOM: replacing the grid with a spinner collapses the card and then
    // expands it again, which reads as a jarring blink when switching products.
    function setLoading(on) {
        if (_st.el) _st.el.classList.toggle('is-loading', !!on);
    }

    function destroy() {
        if (_st.el) _st.el.innerHTML = '';
        _st.el = null;
    }

    return { renderIn: renderIn, destroy: destroy, setLoading: setLoading };
}());
