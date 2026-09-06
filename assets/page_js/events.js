// assets/page_js/events.js
// Events page interactions:
//   - list filtering / sorting / "Upcoming" vs "All Events" grouping
//   - create / edit modal (incl. Escape-to-close, Enter-to-save, Save loading state)
//   - Analyze panel (pattern suggestions from /api/detect_patterns.php)
//   - hidden-presets footer (hide / restore)
//   - delete + hide flows (uses showConfirm + showToast)
//
// Reads per-request config from window.EVENTS_CONFIG (set inline in events.view.php):
//   today    — 'YYYY-MM-DD' (server's today, used to filter "Upcoming")
//   cutoff   — 'YYYY-MM-DD' (today + 2 months, the "Upcoming" cutoff)
//   baseUrl  — BASE_URL constant, for fetch() targets and navigation
//
// Functions called from inline onclick attributes in events.view.php and the
// two modal partials are exposed on `window` so they're reachable from HTML.

(function () {
    var cfg     = window.EVENTS_CONFIG || {};
    var TODAY   = cfg.today  || '';
    var CUTOFF  = cfg.cutoff || '';
    var BASE    = cfg.baseUrl || '';

    var allEventRows      = [];     // collected once at init; reused across renders
    var hiddenPanelOpen   = false;
    var hiddenEventsCache = null;   // null = not yet loaded from /api/events.php

    // ── Init ──────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Collect every event row from the hidden pool BEFORE the first render
        // moves them into #events-list — after that the pool selector returns nothing.
        allEventRows = Array.from(
            document.querySelectorAll('#event-rows-pool .event-row[data-event-id]')
        );
        renderEvents();
        loadHiddenCount();
        bindKeyboardShortcuts();
        bindOverlayDismiss();
    });

    // ── Render: filter → sort → group → paint ─────────────────────────────────
    function renderEvents() {
        var search = document.getElementById('event-search').value.toLowerCase().trim();
        var sort   = document.getElementById('event-sort').value;

        var filtered = allEventRows.filter(function (row) {
            if (search && row.dataset.name.toLowerCase().indexOf(search) === -1) return false;
            return true;
        });

        filtered.sort(function (a, b) {
            var aOcc = a.dataset.nextOcc || '';
            var bOcc = b.dataset.nextOcc || '';
            switch (sort) {
                case 'name':
                    return a.dataset.name.localeCompare(b.dataset.name);
                case 'date_desc':
                    if (!aOcc && !bOcc) return a.dataset.name.localeCompare(b.dataset.name);
                    if (!aOcc) return 1;
                    if (!bOcc) return -1;
                    return bOcc.localeCompare(aOcc);
                default:  // date_asc — upcoming first, past / no-occurrence last
                    if (!aOcc && !bOcc) return a.dataset.name.localeCompare(b.dataset.name);
                    if (!aOcc) return 1;
                    if (!bOcc) return -1;
                    return aOcc.localeCompare(bOcc);
            }
        });

        // Group: events whose next occurrence is between today and the 2-month
        // cutoff land in "Upcoming"; the rest (further out or past) in "All Events".
        var upcoming = filtered.filter(function (row) {
            var occ = row.dataset.nextOcc;
            return occ && occ >= TODAY && occ <= CUTOFF;
        });
        var rest = filtered.filter(function (row) {
            var occ = row.dataset.nextOcc;
            return !(occ && occ >= TODAY && occ <= CUTOFF);
        });

        var list = document.getElementById('events-list');
        list.innerHTML = '';

        if (filtered.length === 0) {
            list.appendChild(makeEmptyState(search, allEventRows.length));
            return;
        }

        if (upcoming.length > 0) {
            list.appendChild(makeSectionHeader('Upcoming'));
            upcoming.forEach(function (row) { list.appendChild(row); });
        }
        if (rest.length > 0) {
            if (upcoming.length > 0) list.appendChild(makeSectionHeader('All Events'));
            rest.forEach(function (row) { list.appendChild(row); });
        }
    }

    function makeSectionHeader(title) {
        var div = document.createElement('div');
        div.className   = 'events-section-header';
        div.textContent = title;
        return div;
    }

    // Two empty-state messages: "no match" when the user has events but the
    // search excludes them, vs "no events at all" when the list is genuinely empty.
    function makeEmptyState(search, totalRows) {
        var div = document.createElement('div');
        div.className = 'events-empty';
        if (search) {
            div.textContent = 'No events match your search.';
        } else if (totalRows === 0) {
            div.textContent = 'No events yet. Click "Add Event" to create your first one.';
        } else {
            div.textContent = 'No events to show.';
        }
        return div;
    }

    window.renderEvents = renderEvents;   // toolbar search/sort handlers call this

    window.goToDetail = function (id) {
        window.location = BASE + '/pages/event_detail.view.php?id=' + id;
    };

    // ── Create / edit modal ───────────────────────────────────────────────────
    window.openCreateModal = function () {
        document.getElementById('modal-title').textContent = 'Add Event';
        document.getElementById('modal-event-id').value    = '';
        document.getElementById('modal-name').value        = '';
        document.getElementById('modal-recurrence').value  = 'yearly';
        document.getElementById('modal-note').value        = '';

        var now = new Date();
        setYearlyParts(now.getMonth() + 1, now.getDate());
        setMonthlyParts(0, now.getDate());
        document.getElementById('modal-start').value    = '';
        document.getElementById('modal-end').value      = '';
        document.getElementById('modal-duration').value = 1;
        setCustomDates([]);

        handleRecurrenceChange();
        selectColor('#FF5722');
        resetSaveButton();
        showModal();
    };

    window.openEditModal = function (ev) {
        document.getElementById('modal-title').textContent = 'Edit Event';
        document.getElementById('modal-event-id').value    = ev.id;
        document.getElementById('modal-name').value        = ev.name;
        document.getElementById('modal-recurrence').value  = ev.recurrence;
        document.getElementById('modal-note').value        = ev.impact_note || '';

        // Reverse the stored row back into the parts each mode edits.
        var startParts = parseYMD(ev.event_start);
        setYearlyParts(startParts.month, startParts.day);
        setMonthlyParts(ev.is_last_day == 1 ? 1 : 0, startParts.day);

        document.getElementById('modal-start').value = ev.event_start;
        document.getElementById('modal-end').value   = ev.event_end || '';
        document.getElementById('modal-duration').value =
            daysBetween(ev.event_start, ev.event_end) + 1;
        setCustomDates(ev.occurrences || []);

        handleRecurrenceChange();
        selectColor(ev.color || '#FF5722');
        resetSaveButton();
        showModal();
    };

    // -- Date part helpers ----------------------------------------------------
    // Recurring events store a placeholder date; expandEvents() reads back only
    // some of it (yearly: month+day, monthly: day). ANCHOR_YEAR is a leap year
    // so a Feb 29 yearly event still stores a valid date.
    var ANCHOR_YEAR   = 2024;
    // Days per month in a leap year, so February counts as 29.
    var MONTH_LENGTHS = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    function parseYMD(str) {
        var p = (str || '').split('-');
        return { year: +p[0] || ANCHOR_YEAR, month: +p[1] || 1, day: +p[2] || 1 };
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    // Whole days from start to end (0 when end is missing or the same day).
    function daysBetween(startStr, endStr) {
        if (!startStr || !endStr) return 0;
        var ms = new Date(endStr + 'T00:00:00') - new Date(startStr + 'T00:00:00');
        return ms > 0 ? Math.round(ms / 86400000) : 0;
    }

    function addDays(dateStr, n) {
        var d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() + n);
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function setYearlyParts(month, day) {
        document.getElementById('modal-year-month').value = month;
        document.getElementById('modal-year-day').value   = day;
        handleYearlyDayChange();
    }

    function setMonthlyParts(isLastDay, day) {
        document.getElementById('month-mode-day').checked  = !isLastDay;
        document.getElementById('month-mode-last').checked = !!isLastDay;
        document.getElementById('modal-month-day').value   = day;
        handleMonthModeChange();
    }

    function showModal() {
        document.getElementById('event-modal-overlay').classList.remove('hidden');
        document.getElementById('modal-name').focus();
    }

    window.closeModal = function () {
        document.getElementById('event-modal-overlay').classList.add('hidden');
    };

    function resetSaveButton() {
        var saveBtn = document.querySelector('#event-modal-overlay .btn-save');
        if (!saveBtn) return;
        saveBtn.disabled    = false;
        saveBtn.textContent = 'Save Event';
    }

    // ── Color picker ──────────────────────────────────────────────────────────
    window.selectColor = function (hex) {
        var norm = (hex || '#FF5722').toUpperCase();
        document.getElementById('modal-color').value        = norm;
        document.getElementById('modal-color-custom').value = norm;

        // Highlight whichever preset swatch matches the chosen color (if any).
        document.querySelectorAll('#color-picker-row .color-swatch[data-color]').forEach(function (s) {
            var match = s.dataset.color.toUpperCase() === norm;
            s.classList.toggle('selected', match);
            // The .selected ring uses currentColor; set it to the swatch's own color.
            s.style.setProperty('color', match ? s.dataset.color : 'transparent');
        });
    };

    // -- Recurrence / month-mode toggles --------------------------------------
    // Shows only the "when" block matching the chosen recurrence, so the form
    // never asks for a value that recurrence type would throw away.
    window.handleRecurrenceChange = function () {
        var rec = document.getElementById('modal-recurrence').value;
        document.getElementById('when-yearly').classList.toggle('hidden',  rec !== 'yearly');
        document.getElementById('when-monthly').classList.toggle('hidden', rec !== 'monthly');
        document.getElementById('when-custom').classList.toggle('hidden',  rec !== 'custom');
        document.getElementById('when-once').classList.toggle('hidden',    rec !== 'none');
        // One-time and custom events carry real dates, so they size themselves.
        document.getElementById('duration-row').classList.toggle('hidden',
            rec === 'none' || rec === 'custom');

        // Start the list with one empty row so the field is never a blank box.
        if (rec === 'custom' && !document.querySelector('#custom-date-list .custom-date-row')) {
            addCustomDateRow();
        }
    };

    // Warns when a yearly date cannot exist (Apr 31), or exists only in leap years.
    window.handleYearlyDayChange = function () {
        var month = +document.getElementById('modal-year-month').value;
        var day   = +document.getElementById('modal-year-day').value;
        var hint  = document.getElementById('yearly-date-hint');
        var max   = MONTH_LENGTHS[month - 1];

        if (day > max) {
            hint.textContent = 'That date does not exist. Pick ' + max + ' or lower.';
            hint.classList.remove('hidden');
        } else if (month === 2 && day === 29) {
            hint.textContent = 'Feb 29 only exists in leap years - other years are skipped.';
            hint.classList.remove('hidden');
        } else {
            hint.classList.add('hidden');
        }
    };

    window.handleMonthModeChange = function () {
        var isLast = document.getElementById('month-mode-last').checked;
        document.getElementById('modal-month-day').disabled = isLast;
        handleMonthDayChange();
    };

    // Warns about day numbers that do not exist in every month.
    window.handleMonthDayChange = function () {
        var isLast = document.getElementById('month-mode-last').checked;
        var day    = +document.getElementById('modal-month-day').value;
        var hint   = document.getElementById('month-day-hint');

        if (!isLast && day > 28) {
            var skipped = MONTH_LENGTHS.filter(function (len) { return len < day; }).length;
            hint.textContent = 'Day ' + day + ' does not exist in every month, so it is skipped in '
                             + skipped + ' month' + (skipped === 1 ? '' : 's')
                             + ' a year. Choose "Last day of month" if you mean month-end.';
            hint.classList.remove('hidden');
        } else {
            hint.classList.add('hidden');
        }
    };

    // ── Specific-date rows ────────────────────────────────────────────────────
    // Each row is one occurrence: a required date, an optional "to" date for a
    // multi-day stretch, and a remove button. The list is the whole input, so
    // it is rebuilt from scratch whenever the modal opens.

    function setCustomDates(list) {
        var wrap = document.getElementById('custom-date-list');
        wrap.innerHTML = '';
        (list || []).forEach(function (occ) {
            addCustomDateRow(occ.start_date, occ.end_date);
        });
        updateCustomDateHint();
    }

    window.addCustomDateRow = function (startVal, endVal) {
        var wrap = document.getElementById('custom-date-list');

        var row = document.createElement('div');
        row.className = 'custom-date-row';

        var start = document.createElement('input');
        start.type      = 'date';
        start.className = 'form-input custom-date-input';
        start.value     = startVal || '';
        start.setAttribute('aria-label', 'Date');
        start.addEventListener('change', updateCustomDateHint);

        var to = document.createElement('span');
        to.className   = 'custom-date-to';
        to.textContent = 'to';

        var end = document.createElement('input');
        end.type      = 'date';
        end.className = 'form-input custom-date-input custom-date-end';
        end.value     = endVal || '';
        end.setAttribute('aria-label', 'End date (optional)');
        end.addEventListener('change', updateCustomDateHint);

        var del = document.createElement('button');
        del.type      = 'button';
        del.className = 'custom-date-remove';
        del.innerHTML = '&times;';
        del.title     = 'Remove this date';
        del.setAttribute('aria-label', 'Remove this date');
        del.onclick   = function () {
            row.remove();
            // Never leave the list completely empty - it reads as broken.
            if (!document.querySelector('#custom-date-list .custom-date-row')) {
                addCustomDateRow();
            }
            updateCustomDateHint();
        };

        row.appendChild(start);
        row.appendChild(to);
        row.appendChild(end);
        row.appendChild(del);
        wrap.appendChild(row);
        updateCustomDateHint();
    };

    // Reads the rows into the shape the API expects, skipping blank ones.
    function readCustomDates() {
        var out = [];
        document.querySelectorAll('#custom-date-list .custom-date-row').forEach(function (row) {
            var inputs = row.querySelectorAll('input[type="date"]');
            var start  = inputs[0].value;
            var end    = inputs[1].value;
            if (start) out.push({ start_date: start, end_date: end || null });
        });
        return out;
    }

    // Live feedback on how much evidence the list carries, using the same
    // thresholds as the confidence badge on the events list.
    function updateCustomDateHint() {
        var hint = document.getElementById('custom-date-hint');
        var n    = readCustomDates().length;

        if (n === 0) {
            hint.textContent = 'Add at least one date.';
        } else if (n < 3) {
            hint.textContent = n + ' date' + (n === 1 ? '' : 's') +
                ' — add more so the system can tell a real effect from a one-off.';
        } else if (n < 6) {
            hint.textContent = n + ' dates — enough for a moderate estimate. 6 or more is stronger.';
        } else {
            hint.textContent = n + ' dates — enough for a strong estimate.';
        }
    }

    // Turns the visible "when" controls into the two columns the API stores.
    // Recurring events keep a placeholder date whose ignored parts are pinned to
    // ANCHOR_YEAR / January, and carry their length as event_end = start + n-1.
    function buildEventDates(recurrence) {
        if (recurrence === 'custom') {
            var dates = readCustomDates();
            if (!dates.length) return { error: 'Add at least one date.' };

            var seen = {};
            for (var i = 0; i < dates.length; i++) {
                var d = dates[i];
                if (d.end_date && d.end_date < d.start_date) {
                    return { error: 'Each row must end on or after the date it starts.' };
                }
                if (seen[d.start_date]) {
                    return { error: 'The date ' + d.start_date + ' is listed twice.' };
                }
                seen[d.start_date] = true;
            }

            dates.sort(function (a, b) { return a.start_date < b.start_date ? -1 : 1; });
            // event_start/end mirror the earliest row; the API re-derives them.
            return {
                start:       dates[0].start_date,
                end:         dates[0].end_date || '',
                isLastDay:   0,
                occurrences: dates
            };
        }

        if (recurrence === 'none') {
            var s = document.getElementById('modal-start').value;
            var e = document.getElementById('modal-end').value;
            if (!s) return { error: 'Start date is required.' };
            if (e && e < s) return { error: 'End date must be on or after the start date.' };
            return { start: s, end: e, isLastDay: 0 };
        }

        var duration = parseInt(document.getElementById('modal-duration').value, 10);
        if (!duration || duration < 1) return { error: 'Length must be at least 1 day.' };

        var startDate, isLastDay = 0;

        if (recurrence === 'yearly') {
            var month = +document.getElementById('modal-year-month').value;
            var day   = +document.getElementById('modal-year-day').value;
            if (day > MONTH_LENGTHS[month - 1]) {
                return { error: 'That date does not exist in the month you picked.' };
            }
            startDate = ANCHOR_YEAR + '-' + pad2(month) + '-' + pad2(day);
        } else {
            isLastDay = document.getElementById('month-mode-last').checked ? 1 : 0;
            // January has 31 days, so any chosen day number is a valid anchor.
            var mDay  = isLastDay ? 31 : +document.getElementById('modal-month-day').value;
            startDate = ANCHOR_YEAR + '-01-' + pad2(mDay);
        }

        return {
            start:     startDate,
            end:       duration > 1 ? addDays(startDate, duration - 1) : '',
            isLastDay: isLastDay
        };
    }

    // ── Save event (create or update) ─────────────────────────────────────────
    window.saveEvent = async function () {
        var id         = document.getElementById('modal-event-id').value;
        var name       = document.getElementById('modal-name').value.trim();
        var recurrence = document.getElementById('modal-recurrence').value;
        var color      = document.getElementById('modal-color').value || '#FF5722';
        var note       = document.getElementById('modal-note').value.trim();

        if (!name) {
            showToast('Event name is required.', 'error');
            return;
        }

        // Assemble the stored columns from whichever "when" block is in use.
        var built = buildEventDates(recurrence);
        if (built.error) {
            showToast(built.error, 'error');
            return;
        }

        var saveBtn = document.querySelector('#event-modal-overlay .btn-save');
        saveBtn.disabled    = true;
        saveBtn.textContent = id ? 'Saving…' : 'Adding…';

        var formData = new FormData();
        formData.append('action',      id ? 'update' : 'create');
        if (id) formData.append('id', id);
        formData.append('name',        name);
        formData.append('event_start', built.start);
        formData.append('event_end',   built.end);
        formData.append('recurrence',  recurrence);
        formData.append('is_last_day', built.isLastDay);
        if (recurrence === 'custom') {
            formData.append('occurrences', JSON.stringify(built.occurrences));
        }
        formData.append('color',       color);
        formData.append('impact_note', note);

        try {
            var res  = await fetch(BASE + '/api/events.php', { method: 'POST', body: formData });
            var data = await res.json();
            if (data.success) {
                // Reload so the new/updated row picks up server-rendered fields
                // (next_occurrence, confidence count, etc.) without re-deriving them in JS.
                window.location.reload();
            } else {
                showToast('Error: ' + data.error, 'error');
                resetSaveButton();
            }
        } catch (e) {
            showToast('Network error. Please try again.', 'error');
            resetSaveButton();
        }
    };

    // ── Analyze panel ─────────────────────────────────────────────────────────
    window.runAnalysis = async function () {
        var btn   = document.getElementById('analyze-btn');
        var lbl   = btn.querySelector('.analyze-label');
        var panel = document.getElementById('suggestions-panel');

        btn.classList.add('running');
        lbl.textContent = 'Analyzing…';
        panel.classList.remove('hidden');
        document.getElementById('suggestions-loading').classList.remove('hidden');
        document.getElementById('suggestions-error').classList.add('hidden');
        document.getElementById('suggestions-results').classList.add('hidden');

        try {
            var res  = await fetch(BASE + '/api/detect_patterns.php', { method: 'POST' });
            var data = await res.json();
            document.getElementById('suggestions-loading').classList.add('hidden');

            if (data.error) {
                var errEl = document.getElementById('suggestions-error');
                errEl.textContent = data.error;
                errEl.classList.remove('hidden');
            } else {
                renderSuggestions(data);
                document.getElementById('suggestions-results').classList.remove('hidden');
            }
        } catch (e) {
            document.getElementById('suggestions-loading').classList.add('hidden');
            var errEl2 = document.getElementById('suggestions-error');
            errEl2.textContent = 'Network error. Make sure python/app.py is running.';
            errEl2.classList.remove('hidden');
        }

        btn.classList.remove('running');
        lbl.textContent = 'Analyze';
    };

    window.closeSuggestions = function () {
        document.getElementById('suggestions-panel').classList.add('hidden');
    };

    function renderSuggestions(data) {
        var summary   = data.data_summary || {};
        var summaryEl = document.getElementById('suggestions-summary');
        if (summary.date_from) {
            summaryEl.textContent = summary.date_from + ' → ' + summary.date_to
                + ' · ' + summary.total_days + ' days · ' + summary.years_count + ' year(s)';
        } else {
            summaryEl.textContent = '';
        }

        // The Python endpoint may send a top-level message (e.g. "not enough data")
        // instead of a suggestion list — surface it in the "none" slot.
        if (data.message) {
            var noneEl = document.getElementById('suggestions-none');
            noneEl.textContent = data.message;
            noneEl.classList.remove('hidden');
            document.getElementById('suggestions-list').innerHTML = '';
        } else {
            var suggestions = data.suggestions || [];
            var list = document.getElementById('suggestions-list');
            list.innerHTML = '';
            if (suggestions.length === 0) {
                document.getElementById('suggestions-none').classList.remove('hidden');
            } else {
                document.getElementById('suggestions-none').classList.add('hidden');
                suggestions.forEach(function (s) { list.appendChild(buildSuggestionCard(s)); });
            }
        }

        var weekly     = data.weekly_insights || [];
        var weeklyWrap = document.getElementById('suggestions-weekly-section');
        var weeklyList = document.getElementById('suggestions-weekly-list');
        weeklyList.innerHTML = '';
        if (weekly.length > 0) {
            weeklyWrap.classList.remove('hidden');
            weekly.forEach(function (w) {
                var sign = w.impact_pct >= 0 ? '+' : '';
                var chip = document.createElement('span');
                chip.className   = 'weekly-insight-chip';
                chip.textContent = w.day + ': ' + sign + w.impact_pct + '%';
                weeklyList.appendChild(chip);
            });
        } else {
            weeklyWrap.classList.add('hidden');
        }
    }

    function buildSuggestionCard(s) {
        var impact     = s.impact_pct;
        var impactSign = impact >= 0 ? '+' : '';
        var impactCls  = impact >= 0 ? '' : ' neg';

        // Date label format depends on recurrence kind.
        var dateLabel;
        if (s.is_last_day) {
            dateLabel = 'Last day of month';
        } else if (s.recurrence === 'monthly') {
            var d   = new Date(s.event_start + 'T00:00:00');
            var sfx = ['th', 'st', 'nd', 'rd'];
            var v   = d.getDate() % 100;
            var ord = sfx[(v - 20) % 10] || sfx[Math.min(v, 3)] || sfx[0];
            dateLabel = d.getDate() + ord + ' of month';
        } else {
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var s_dt   = new Date(s.event_start + 'T00:00:00');
            dateLabel  = months[s_dt.getMonth()] + ' ' + s_dt.getDate();
            if (s.event_end && s.event_end !== s.event_start) {
                var e_dt = new Date(s.event_end + 'T00:00:00');
                dateLabel += '–' + months[e_dt.getMonth()] + ' ' + e_dt.getDate();
            }
        }

        var card = document.createElement('div');
        card.className = 'suggestion-card';
        card.innerHTML =
            '<div class="suggestion-meta">'
            + '<span class="suggestion-recurrence">' + (s.recurrence === 'yearly' ? 'Every year' : 'Every month') + '</span>'
            + '<span class="suggestion-date">' + dateLabel + '</span>'
            + '<span class="event-conf-badge conf-' + s.confidence + '">' + s.confidence_label + '</span>'
            + '<span class="suggestion-impact' + impactCls + '">↑ ' + impactSign + impact + '%</span>'
            + '<span class="suggestion-detail">' + s.confidence_detail + '</span>'
            + '</div>'
            + '<div class="suggestion-name-wrap">'
            + '<input type="text" class="suggestion-name-input" value="' + escHtml(s.suggested_name) + '" placeholder="Name this event…">'
            + '<button class="suggestion-add-btn" onclick="addSuggestedEvent(this)">Add Event</button>'
            + '</div>';

        // Stash the suggestion's structural data on the card so addSuggestedEvent
        // can read it without keeping a closure over the whole suggestion object.
        card.dataset.recurrence = s.recurrence;
        card.dataset.eventStart = s.event_start || '';
        card.dataset.eventEnd   = s.event_end   || '';
        card.dataset.isLastDay  = s.is_last_day ? '1' : '0';
        return card;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.addSuggestedEvent = async function (btn) {
        var card      = btn.closest('.suggestion-card');
        var nameInput = card.querySelector('.suggestion-name-input');
        var name      = nameInput.value.trim();
        if (!name) { nameInput.focus(); return; }

        btn.disabled    = true;
        btn.textContent = 'Adding…';

        var formData = new FormData();
        formData.append('action',      'create');
        formData.append('name',        name);
        formData.append('recurrence',  card.dataset.recurrence);
        formData.append('event_start', card.dataset.eventStart);
        formData.append('event_end',   card.dataset.eventEnd);
        formData.append('is_last_day', card.dataset.isLastDay);
        formData.append('impact_note', '');

        try {
            var res  = await fetch(BASE + '/api/events.php', { method: 'POST', body: formData });
            var data = await res.json();
            if (data.success) {
                card.classList.add('suggestion-card-leaving');
                setTimeout(function () {
                    card.remove();
                    var remaining = document.querySelectorAll('#suggestions-list .suggestion-card');
                    if (remaining.length === 0) {
                        var noneEl = document.getElementById('suggestions-none');
                        noneEl.textContent = 'All suggestions added. Reload the page to see the new events.';
                        noneEl.classList.remove('hidden');
                    }
                }, 260);
            } else {
                showToast('Error: ' + data.error, 'error');
                btn.disabled    = false;
                btn.textContent = 'Add Event';
            }
        } catch (e) {
            showToast('Network error. Please try again.', 'error');
            btn.disabled    = false;
            btn.textContent = 'Add Event';
        }
    };

    // ── Hidden presets footer ─────────────────────────────────────────────────
    async function loadHiddenCount() {
        try {
            var formData = new FormData();
            formData.append('action', 'get_hidden');
            var res  = await fetch(BASE + '/api/events.php', { method: 'POST', body: formData });
            var data = await res.json();
            hiddenEventsCache = data.hidden || [];
            updateHiddenLabel();
        } catch (e) { /* silently ignore — footer just stays hidden */ }
    }

    function updateHiddenLabel() {
        var list   = hiddenEventsCache || [];
        var footer = document.getElementById('hidden-events-footer');
        var label  = document.getElementById('hidden-events-label');
        if (list.length === 0) {
            footer.classList.add('hidden');
            return;
        }
        footer.classList.remove('hidden');
        var verb = hiddenPanelOpen ? 'Hide ' : 'Show ';
        label.textContent = verb + list.length + ' hidden preset' + (list.length !== 1 ? 's' : '');
    }

    window.toggleHiddenPanel = function () {
        hiddenPanelOpen = !hiddenPanelOpen;
        document.getElementById('hidden-events-panel').classList.toggle('hidden', !hiddenPanelOpen);
        if (hiddenPanelOpen) renderHiddenList();
        updateHiddenLabel();
    };

    function renderHiddenList() {
        var listEl = document.getElementById('hidden-events-list');
        listEl.innerHTML = '';
        var list = hiddenEventsCache || [];
        if (list.length === 0) {
            var p = document.createElement('p');
            p.className   = 'hidden-events-empty';
            p.textContent = 'No hidden presets.';
            listEl.appendChild(p);
            return;
        }
        list.forEach(function (ev) {
            var row = document.createElement('div');
            row.className = 'hidden-event-row';
            row.id        = 'hidden-row-' + ev.id;

            var dot = document.createElement('span');
            dot.className        = 'event-dot hidden-event-dot';
            dot.style.background = ev.color || '#FF5722';

            var name = document.createElement('span');
            name.className   = 'hidden-event-name';
            name.textContent = ev.name;

            var sched = document.createElement('span');
            sched.className = 'hidden-event-schedule';
            sched.textContent = ev.recurrence === 'yearly'  ? 'Every year'
                              : ev.is_last_day              ? 'Last day of month'
                              : 'Every month';

            var btn = document.createElement('button');
            btn.className   = 'restore-btn';
            btn.textContent = 'Restore';
            btn.onclick     = function () { doUnhideEvent(ev.id, btn); };

            row.appendChild(dot);
            row.appendChild(name);
            row.appendChild(sched);
            row.appendChild(btn);
            listEl.appendChild(row);
        });
    }

    async function doUnhideEvent(id, btn) {
        btn.disabled    = true;
        btn.textContent = '…';
        var formData = new FormData();
        formData.append('action', 'unhide');
        formData.append('id', id);
        try {
            var res  = await fetch(BASE + '/api/events.php', { method: 'POST', body: formData });
            var data = await res.json();
            if (data.success) {
                hiddenEventsCache = hiddenEventsCache.filter(function (ev) { return ev.id != id; });
                var row = document.getElementById('hidden-row-' + id);
                if (row) {
                    row.classList.add('hidden-event-row-leaving');
                    setTimeout(function () { row.remove(); }, 260);
                }
                if (hiddenEventsCache.length === 0) hiddenPanelOpen = false;
                updateHiddenLabel();
                // Reload so the restored preset appears in the main list.
                setTimeout(function () { window.location.reload(); }, 400);
            } else {
                showToast('Error: ' + data.error, 'error');
                btn.disabled    = false;
                btn.textContent = 'Restore';
            }
        } catch (e) {
            showToast('Network error. Please try again.', 'error');
            btn.disabled    = false;
            btn.textContent = 'Restore';
        }
    }

    // ── Info modal ────────────────────────────────────────────────────────────
    window.openInfoModal = function () {
        document.getElementById('info-modal-overlay').classList.remove('hidden');
    };
    window.closeInfoModal = function () {
        document.getElementById('info-modal-overlay').classList.add('hidden');
    };

    // ── Delete custom event ───────────────────────────────────────────────────
    window.confirmDeleteEvent = function (id, name) {
        showConfirm({
            title:        'Delete Event?',
            message:      '"' + name + '" will be permanently deleted.',
            confirmText:  'Delete',
            confirmStyle: 'danger',
            onConfirm:    function () { deleteEvent(id); },
        });
    };

    async function deleteEvent(id) {
        var formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        try {
            var res  = await fetch(BASE + '/api/events.php', { method: 'POST', body: formData });
            var data = await res.json();
            if (data.success) {
                var row = document.getElementById('event-row-' + id);
                if (row) {
                    row.classList.add('event-row-leaving');
                    setTimeout(function () {
                        // Drop from the master list so the next render doesn't re-introduce it.
                        allEventRows = allEventRows.filter(function (r) { return r !== row; });
                        row.remove();
                        renderEvents();   // re-render to update section headers
                    }, 260);
                }
            } else {
                showToast('Delete failed: ' + data.error, 'error');
            }
        } catch (e) {
            showToast('Network error. Please try again.', 'error');
        }
    }

    // ── Hide preset event ─────────────────────────────────────────────────────
    window.confirmHidePresetEvent = function (id, name) {
        showConfirm({
            title:        'Hide Preset Event?',
            message:      '"' + name + '" will be hidden from your list. It won\'t appear on your charts or affect your forecast.',
            confirmText:  'Hide',
            confirmStyle: 'danger',
            onConfirm:    function () { doHideEvent(id); },
        });
    };

    async function doHideEvent(id) {
        var formData = new FormData();
        formData.append('action', 'hide');
        formData.append('id', id);
        try {
            var res  = await fetch(BASE + '/api/events.php', { method: 'POST', body: formData });
            var data = await res.json();
            if (data.success) {
                var row = document.getElementById('event-row-' + id);
                if (row) {
                    row.classList.add('event-row-leaving');
                    setTimeout(function () {
                        allEventRows = allEventRows.filter(function (r) { return r !== row; });
                        row.remove();
                        renderEvents();
                    }, 260);
                }
            } else {
                showToast('Error: ' + data.error, 'error');
            }
        } catch (e) {
            showToast('Network error. Please try again.', 'error');
        }
    }

    // ── Keyboard shortcuts: Escape closes modals, Enter saves the form modal ──
    function bindKeyboardShortcuts() {
        var formOverlay = document.getElementById('event-modal-overlay');
        var infoOverlay = document.getElementById('info-modal-overlay');
        var confirmEl   = document.getElementById('confirm-modal');

        // Use capture phase so this runs BEFORE confirm_modal.php's own keydown
        // handler. Otherwise confirm_modal might hide itself first, and we'd
        // then mistakenly close the form modal underneath on the same keypress.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (confirmEl && !confirmEl.classList.contains('hidden')) return;
                if (!infoOverlay.classList.contains('hidden')) { window.closeInfoModal(); return; }
                if (!formOverlay.classList.contains('hidden')) { window.closeModal();     return; }
                return;
            }

            // Enter saves the form, but only when focus is on a text-style input
            // inside the form modal — leave textareas, selects, and color picker alone.
            if (e.key === 'Enter' && !formOverlay.classList.contains('hidden')) {
                var t = e.target;
                if (!formOverlay.contains(t)) return;
                var isPlainInput = t.tagName === 'INPUT'
                    && t.type !== 'color'
                    && t.type !== 'checkbox';
                if (isPlainInput) {
                    e.preventDefault();
                    window.saveEvent();
                }
            }
        }, true);
    }

    // Clicking the dimmed overlay (outside the modal card) dismisses it.
    function bindOverlayDismiss() {
        var formOverlay = document.getElementById('event-modal-overlay');
        var infoOverlay = document.getElementById('info-modal-overlay');
        formOverlay.addEventListener('click', function (e) { if (e.target === this) window.closeModal();     });
        infoOverlay.addEventListener('click', function (e) { if (e.target === this) window.closeInfoModal(); });
    }
}());
