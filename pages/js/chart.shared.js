// pages/js/chart.shared.js
// Utilities shared by forecast.view.php and reports.view.php.
// Requires CHART_EVENTS and EVENT_COLOR to be defined before any function here is called.

const YEAR_COLORS = ['#1A6933', '#3B6EA5', '#8B6B14', '#1A5F7A', '#7B3F8C', '#B94040'];

// Normalize a real date string to the 2000 base year used by overlay charts.
function normYear(d) {
    if (!d) return null;
    var n = '2000' + d.slice(4);
    return n === '2000-02-29' ? '2000-02-28' : n;
}

// Returns CHART_EVENTS that overlap a given normalized date (year 2000 format),
// deduplicated by name. Excludes IDs in disabledIds.
function getEventsOnNormDate(normDate, disabledIds) {
    var skip = disabledIds || new Set();
    var seen = new Set();
    var result = [];
    (CHART_EVENTS || []).forEach(function (ev) {
        if (skip.has(ev.id)) return;
        var start = normYear(ev.instance_start);
        var end   = normYear(ev.instance_end || ev.instance_start);
        if (start && normDate >= start && normDate <= end && !seen.has(ev.name)) {
            seen.add(ev.name);
            result.push(ev);
        }
    });
    return result;
}

// Groups CHART_EVENTS by type, deduplicating instances by name.
function _buildEventGroups() {
    var typeMap = {};
    (CHART_EVENTS || []).forEach(function (ev) {
        var type = ev.type || 'custom';
        if (!typeMap[type]) typeMap[type] = { type: type, events: {} };
        if (!typeMap[type].events[ev.name]) {
            typeMap[type].events[ev.name] = { name: ev.name, color: ev.color || EVENT_COLOR, ids: [] };
        }
        typeMap[type].events[ev.name].ids.push(ev.id);
    });
    return Object.values(typeMap).map(function (g) {
        g.events = Object.values(g.events);
        return g;
    });
}

// Opens (or closes) a floating event-filter checklist anchored below anchorEl.
// disabledIds — Set mutated in place as checkboxes toggle.
// onApply     — called after every change so the chart can refresh annotations.
// Returns true if the panel was opened, false if it was closed.
function toggleEventsChecklist(anchorEl, disabledIds, onApply) {
    var PANEL_ID = 'pv-ev-checklist';
    var existing = document.getElementById(PANEL_ID);
    if (existing) { existing.remove(); return false; }

    var groups = _buildEventGroups();

    var panel = document.createElement('div');
    panel.id        = PANEL_ID;
    panel.className = 'event-filter-panel';
    panel.style.cssText = 'position:fixed;z-index:9999;left:auto';

    if (!groups.length) {
        panel.innerHTML = '<p class="event-filter-empty">No events to filter.</p>';
    } else {
        var allOn = (CHART_EVENTS || []).every(function (ev) { return !disabledIds.has(ev.id); });
        var html  = '<p class="event-filter-title">Show Events</p>';
        html += '<label class="event-filter-row" style="padding-bottom:0.4rem;border-bottom:1px solid rgba(210,200,174,0.55);margin-bottom:0.1rem">' +
                    '<input type="checkbox" id="pv-ev-all"' + (allOn ? ' checked' : '') + '>' +
                    '<strong style="font-size:0.72rem">All events</strong>' +
                '</label>';
        html += '<div class="event-filter-inner">';
        groups.forEach(function (g) {
            var label      = g.type.charAt(0).toUpperCase() + g.type.slice(1).replace(/_/g, ' ');
            var groupAllOn = g.events.every(function (ev) {
                return ev.ids.every(function (id) { return !disabledIds.has(id); });
            });
            html += '<div class="event-filter-group-header">' +
                        '<span class="event-filter-group-label">' + label + '</span>' +
                        '<button type="button" class="event-filter-group-toggle" data-gtype="' + g.type + '">' +
                            (groupAllOn ? 'Hide all' : 'Show all') +
                        '</button>' +
                    '</div>';
            g.events.forEach(function (ev) {
                var evOn    = ev.ids.every(function (id) { return !disabledIds.has(id); });
                var idsAttr = ev.ids.join(',');
                html += '<label class="event-filter-row-child">' +
                            '<input type="checkbox" data-ev-ids="' + idsAttr + '"' + (evOn ? ' checked' : '') + '>' +
                            '<span class="event-filter-dot" style="background:' + ev.color + '"></span>' +
                            '<span>' + ev.name + '</span>' +
                        '</label>';
            });
        });
        html += '</div>';
        panel.innerHTML = html;
    }

    var rect = anchorEl.getBoundingClientRect();
    panel.style.top   = (rect.bottom + 6) + 'px';
    panel.style.right = Math.max(8, window.innerWidth - rect.right) + 'px';
    document.body.appendChild(panel);

    var allCb = panel.querySelector('#pv-ev-all');

    function syncAllCb() {
        if (allCb) allCb.checked = (CHART_EVENTS || []).every(function (ev) { return !disabledIds.has(ev.id); });
    }

    panel.querySelectorAll('input[data-ev-ids]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var checked = this.checked;
            this.dataset.evIds.split(',').map(Number).forEach(function (id) {
                if (checked) disabledIds.delete(id); else disabledIds.add(id);
            });
            syncAllCb();
            onApply();
        });
    });

    if (allCb) {
        allCb.addEventListener('change', function () {
            var checked = this.checked;
            (CHART_EVENTS || []).forEach(function (ev) {
                if (checked) disabledIds.delete(ev.id); else disabledIds.add(ev.id);
            });
            panel.querySelectorAll('input[data-ev-ids]').forEach(function (c) { c.checked = checked; });
            panel.querySelectorAll('.event-filter-group-toggle').forEach(function (b) {
                b.textContent = checked ? 'Hide all' : 'Show all';
            });
            onApply();
        });
    }

    panel.querySelectorAll('.event-filter-group-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var gtype = this.dataset.gtype;
            var group = groups.find(function (g) { return g.type === gtype; });
            if (!group) return;
            var wasAllOn = group.events.every(function (ev) {
                return ev.ids.every(function (id) { return !disabledIds.has(id); });
            });
            group.events.forEach(function (ev) {
                ev.ids.forEach(function (id) { if (wasAllOn) disabledIds.add(id); else disabledIds.delete(id); });
            });
            this.textContent = wasAllOn ? 'Show all' : 'Hide all';
            group.events.forEach(function (ev) {
                var cb = panel.querySelector('input[data-ev-ids="' + ev.ids.join(',') + '"]');
                if (cb) cb.checked = !wasAllOn;
            });
            syncAllCb();
            onApply();
        });
    });

    function onOutside(e) {
        if (!panel.contains(e.target) && e.target !== anchorEl) {
            panel.remove();
            document.removeEventListener('click', onOutside, true);
        }
    }
    setTimeout(function () { document.addEventListener('click', onOutside, true); }, 0);

    return true;
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

function tsToDateStr(ts) {
    const d = new Date(ts);
    return d.getFullYear()
        + '-' + String(d.getMonth() + 1).padStart(2, '0')
        + '-' + String(d.getDate()).padStart(2, '0');
}

function buildForecastStartAnnotation(startDate) {
    return {
        type: 'line', scaleID: 'x', value: startDate,
        borderColor: 'rgba(38,31,14,0.25)', borderWidth: 1, borderDash: [4, 4],
        label: {
            display: true, content: 'Forecast', position: 'start',
            backgroundColor: 'rgba(38,31,14,0.72)', color: '#F0E8D0',
            font: { size: 9, family: 'Lora' }, padding: { x: 5, y: 3 }, borderRadius: 3, yAdjust: -4,
        },
    };
}

// Groups [{date:'YYYY-MM-DD', actual}] into {year: [{x:'2000-MM-DD', y}]}.
// All dates are normalised to base year 2000 so every year overlaps on the same Jan–Dec axis.
function groupByYearNorm(historical) {
    const map = {};
    historical.forEach(function (r) {
        const year = r.date.slice(0, 4);
        if (!map[year]) map[year] = [];
        map[year].push({ x: '2000' + r.date.slice(4), y: r.actual });
    });
    return map;
}

// Builds Chart.js annotation objects for CHART_EVENTS visible in [visibleFrom, visibleTo].
//   normalize  — when true, maps event dates to 2000 base year (for overlay charts).
//   disabledIds — Set of event IDs to hide; pass new Set() to show all events.
function buildChartAnnotations(visibleFrom, visibleTo, normalize, disabledIds) {
    // Normalize date to base year 2000. Feb 29 is clamped to Feb 28 so that
    // leap-year events and non-leap-year events of the same name don't both
    // appear in February when years are overlaid on the shared 2000 axis.
    const na = normalize ? function (d) {
        if (!d) return null;
        const n = '2000' + d.slice(4);
        return n === '2000-02-29' ? '2000-02-28' : n;
    } : function (d) { return d; };
    const skip = disabledIds || new Set();

    let visible = CHART_EVENTS.filter(function (ev) {
        if (skip.has(ev.id)) return false;
        const evEnd   = na(ev.instance_end || ev.instance_start);
        const evStart = na(ev.instance_start);
        if (visibleFrom && evEnd   < visibleFrom) return false;
        if (visibleTo   && evStart > visibleTo)   return false;
        return true;
    });

    // When dates are normalized to year 2000, the same recurring event from different
    // calendar years lands on the same axis position — deduplicate by (name + date).
    if (normalize) {
        const seen = new Set();
        visible = visible.filter(function (ev) {
            const key = ev.name + '|' + na(ev.instance_start) + '|' + na(ev.instance_end || ev.instance_start);
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }
    const compact = visible.length > 8;

    const totalDays     = (visibleFrom && visibleTo)
        ? Math.max(1, (new Date(visibleTo) - new Date(visibleFrom)) / 86400000) : 365;
    const proximityDays = Math.max(3, Math.floor(totalDays * 0.04));
    const laneH         = compact ? 18 : 26;
    const baseY         = compact ?  4 : -4;

    const sorted = visible.slice().sort(function (a, b) {
        return na(a.instance_start) < na(b.instance_start) ? -1 : 1;
    });
    const laneEnd = [];
    const yAdjOf  = [];

    sorted.forEach(function (ev) {
        const evStart = na(ev.instance_start);
        const evEnd   = na(ev.instance_end || ev.instance_start);
        let lane      = -1;
        for (let l = 0; l < laneEnd.length; l++) {
            const gap = (new Date(evStart) - new Date(laneEnd[l])) / 86400000;
            if (gap >= proximityDays) { lane = l; break; }
        }
        if (lane === -1) { lane = laneEnd.length; laneEnd.push(''); }
        laneEnd[lane] = evEnd;
        yAdjOf.push(baseY + lane * laneH);
    });

    const annotations = {};
    sorted.forEach(function (ev, i) {
        const color = ev.color || EVENT_COLOR;
        const start = na(ev.instance_start);
        const end   = na(ev.instance_end);
        const yAdj  = yAdjOf[i];
        const key   = 'evt-' + i;
        if (compact) {
            annotations[key] = {
                type: 'line', scaleID: 'x', value: start,
                borderColor: hexToRgba(color, 0.25), borderWidth: 1, borderDash: [2, 5],
                label: {
                    display: true, content: '●', position: 'start',
                    backgroundColor: color, color: '#fff',
                    font: { size: 7, weight: '700', family: 'Lora' },
                    padding: { x: 3, y: 2 }, borderRadius: 99, yAdjust: yAdj,
                },
            };
        } else if (end && end !== start) {
            annotations[key] = {
                type: 'box', xMin: start, xMax: end,
                backgroundColor: hexToRgba(color, 0.08), borderColor: color, borderWidth: 1,
                label: {
                    display: true, content: ev.name, position: { x: 'start', y: 'start' },
                    backgroundColor: hexToRgba(color, 0.88), color: '#fff',
                    font: { size: 9, family: 'Lora' }, padding: { x: 5, y: 3 }, borderRadius: 3,
                    yAdjust: yAdj,
                },
            };
        } else {
            annotations[key] = {
                type: 'line', scaleID: 'x', value: start,
                borderColor: color, borderWidth: 1.5, borderDash: [4, 3],
                label: {
                    display: true, content: ev.name, position: 'start',
                    backgroundColor: hexToRgba(color, 0.88), color: '#fff',
                    font: { size: 9, family: 'Lora' }, padding: { x: 5, y: 3 }, borderRadius: 3,
                    yAdjust: yAdj,
                },
            };
        }
    });
    return annotations;
}


// ══════════════════════════════════════════════════════════════════
// CUSTOM HTML CHART TOOLTIP
// Replaces Chart.js's default canvas-drawn tooltip so we can render
// colored bars, dot legends, signed +/- values, and the Prophet
// component breakdown. Used by both the Demand Analysis chart on the
// forecast page and the ChartModal on the forecast result + reports.
// CSS class hooks live in pages/css/chart_modal.css (.cm-tooltip etc.).
// ══════════════════════════════════════════════════════════════════
function getOrCreateChartTooltipEl() {
    var el = document.getElementById('cm-tooltip');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'cm-tooltip';
    el.className = 'cm-tooltip';
    el.setAttribute('aria-hidden', 'true');
    document.body.appendChild(el);
    return el;
}

function hideChartTooltip() {
    var el = document.getElementById('cm-tooltip');
    if (el) el.style.opacity = '0';
}

function _ttCompRow(tone, label, value, signed) {
    var n  = Math.round(value);
    var vc = 'cm-tt-val';
    var vt = signed ? ((n >= 0 ? '+' : '') + n) : ('' + n);
    if (signed) {
        if (n > 0) vc += ' is-pos';
        else if (n < 0) vc += ' is-neg';
    }
    return '<div class="cm-tt-comp" data-tone="' + tone + '">'
        +     '<span class="cm-tt-bar"></span>'
        +     '<span class="cm-tt-comp-label">' + label + '</span>'
        +     '<span class="' + vc + '">' + vt + '</span>'
        +  '</div>';
}

// Builds the tooltip handler. `getState` is a function so the latest
// fcComponents / disabledIds are read on every hover.
//   state.fcComponents : { 'YYYY-MM-DD' (normalized) → component object }, optional
//   state.disabledIds  : Set of disabled CHART_EVENTS ids, optional
function externalChartTooltip(context, state) {
    var tt = context.tooltip;
    var el = getOrCreateChartTooltipEl();

    if (!tt || tt.opacity === 0) { el.style.opacity = '0'; return; }

    state = state || {};

    // Keep visible, non-band datasets only.
    var points = (tt.dataPoints || []).filter(function (p) {
        return p.dataset.label !== '_upper'
            && p.dataset.label !== '_lower'
            && p.parsed.y != null;
    });
    if (!points.length) { el.style.opacity = '0'; return; }

    var chart    = context.chart;
    var isTime   = chart.options.scales.x && chart.options.scales.x.type === 'time';
    var first    = points[0];
    var dateLabel, normDate = null;

    if (isTime) {
        dateLabel = new Date(first.parsed.x).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
        normDate  = tsToDateStr(first.parsed.x);
    } else {
        // Category x-axis (bar charts): bucket label is whatever Chart.js stored
        dateLabel = chart.data.labels ? chart.data.labels[first.dataIndex] : '';
    }

    var fcItem = points.find(function (p) {
        return p.dataset.label === 'Projected Demand' || p.dataset.label === 'Forecast';
    });

    // ── Header ─────────────────────────────────────────────────────────────
    var html = '<div class="cm-tt-head">'
             +     '<p class="cm-tt-date">' + dateLabel + '</p>'
             +     (fcItem
                     ? '<p class="cm-tt-big">' + Math.round(fcItem.parsed.y) + ' <span class="cm-tt-big-unit">units</span></p>'
                     : '')
             +  '</div>';

    // ── Dataset value rows ─────────────────────────────────────────────────
    html += '<div class="cm-tt-rows">';
    points.forEach(function (p) {
        var color = p.dataset.borderColor || p.dataset.backgroundColor;
        if (Array.isArray(color)) color = color[p.dataIndex] || '#1A6933';
        var label = p.dataset.label;
        if (label === 'Projected Demand') label = 'Forecast';
        html += '<div class="cm-tt-row">'
            +     '<span class="cm-tt-dot" style="background:' + color + '"></span>'
            +     '<span class="cm-tt-label">' + label + '</span>'
            +     '<span class="cm-tt-val">' + Math.round(p.parsed.y).toLocaleString() + '</span>'
            +  '</div>';
    });
    html += '</div>';

    // ── Component breakdown ───────────────────────────────────────────────
    // Daily view: per-day components keyed by normalized date.
    // Aggregated views (weekly/monthly/yearly): components summed across the
    // days that fall into the hovered bucket, keyed by the bar's dataIndex.
    if (fcItem) {
        var comp = null;
        if (isTime && state.fcComponents) {
            comp = state.fcComponents[normDate];
        } else if (!isTime && state.fcBucketComponents) {
            comp = state.fcBucketComponents[fcItem.dataIndex];
        }
        if (comp) {
            // Larger thresholds for aggregated views since the sums are bigger.
            var threshold = isTime ? 0.5 : 1;
            html += '<div class="cm-tt-section">'
                +     '<p class="cm-tt-section-label">Why this number?</p>'
                +     _ttCompRow('brown', 'Baseline trend', comp.trend, false);
            if (Math.abs(comp.weekly) >= threshold) html += _ttCompRow('blue',  'Weekly effect', comp.weekly, true);
            if (Math.abs(comp.yearly) >= threshold) html += _ttCompRow('green', 'Yearly effect', comp.yearly, true);
            (comp.events || []).forEach(function (ev) {
                if (Math.abs(ev.value) >= threshold) html += _ttCompRow('orange', ev.name, ev.value, true);
            });
            html += '</div>';
        }
    }

    // ── Active CHART_EVENT annotations (daily only) ───────────────────────
    if (isTime && normDate) {
        var disabled = state.disabledIds || new Set();
        var evts = getEventsOnNormDate(normDate, disabled);
        if (evts.length) {
            html += '<div class="cm-tt-section">'
                +     '<p class="cm-tt-section-label">Active events</p>';
            evts.forEach(function (ev) {
                html += '<div class="cm-tt-evt">'
                    +     '<span class="cm-tt-evt-dot" style="background:' + (ev.color || '#FF5722') + '"></span>'
                    +     '<span>' + ev.name + '</span>'
                    +  '</div>';
            });
            html += '</div>';
        }
    }

    el.innerHTML = html;

    // Position relative to chart canvas with cursor offset; flip sides if needed.
    var canvas = context.chart.canvas;
    var rect   = canvas.getBoundingClientRect();
    var x      = rect.left + window.pageXOffset + tt.caretX;
    var y      = rect.top  + window.pageYOffset + tt.caretY;

    el.style.opacity = '1';
    el.style.left    = (x + 14) + 'px';
    el.style.top     = (y - 10) + 'px';

    var ttRect = el.getBoundingClientRect();
    if (rect.left + tt.caretX + 14 + ttRect.width > window.innerWidth - 8) {
        el.style.left = (x - 14 - ttRect.width) + 'px';
    }
    if (rect.top + tt.caretY - 10 < 8) {
        el.style.top = (y + 14) + 'px';
    }
}
