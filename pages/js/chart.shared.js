// pages/js/chart.shared.js
// Utilities shared by forecast.view.php and reports.view.php.
// Requires CHART_EVENTS and EVENT_COLOR to be defined before any function here is called.

const YEAR_COLORS = ['#1A6933', '#3B6EA5', '#8B6B14', '#1A5F7A', '#7B3F8C', '#B94040'];

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
    const na   = normalize ? function (d) { return d ? '2000' + d.slice(4) : null; } : function (d) { return d; };
    const skip = disabledIds || new Set();

    const visible = CHART_EVENTS.filter(function (ev) {
        if (skip.has(ev.id)) return false;
        const evEnd   = na(ev.instance_end || ev.instance_start);
        const evStart = na(ev.instance_start);
        if (visibleFrom && evEnd   < visibleFrom) return false;
        if (visibleTo   && evStart > visibleTo)   return false;
        return true;
    });
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
