<?php
// pages/events.view.php
// Events management page — list, create, edit, delete events.

require_once __DIR__ . '/events.logic.php';

$pageTitle = 'ProVendor — Events';
$pageCss   = 'events.css';
require_once __DIR__ . '/../includes/header.php';
?>
<body class="bg-[#F0E8D0] min-h-screen dot-pattern-light">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <!-- Page heading -->
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Events & Seasonality</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Holidays, paydays, and custom events that influence product demand. Click any event to see its impact.
        </p>
    </div>

    <!-- ── Toolbar: search · sort · add button ──────────────────────────────── -->
    <div class="events-toolbar">

        <!-- Search -->
        <div class="events-search-wrap">
            <svg class="events-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="event-search" class="events-search-input"
                   placeholder="Search events…" oninput="renderEvents()">
        </div>

        <!-- Sort -->
        <select id="event-sort" class="events-sort-select" onchange="renderEvents()">
            <option value="date_asc">Date: Upcoming first</option>
            <option value="date_desc">Date: Oldest first</option>
            <option value="name">Name: A–Z</option>
        </select>

        <!-- Analyze button -->
        <button id="analyze-btn" class="events-analyze-btn" onclick="runAnalysis()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <path d="M21 21l-4.35-4.35"/>
                <path d="M11 8v6M8 11h6"/>
            </svg>
            Analyze
        </button>

        <!-- Info button -->
        <button class="events-info-btn" onclick="openInfoModal()" title="How events work">?</button>

        <!-- Add button -->
        <button onclick="openCreateModal()"
                class="bg-[#261F0E] text-[#F0E8D0] rounded-xl px-4 py-2 text-sm font-semibold flex items-center gap-2 hover:opacity-80 transition-opacity"
                style="flex-shrink:0">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Event
        </button>
    </div>

    <!-- ── Suggestions panel (shown after Analyze) ───────────────────────────── -->
    <div id="suggestions-panel" class="suggestions-panel" style="display:none">

        <div id="suggestions-loading" class="suggestions-loading">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" style="animation:spin 1s linear infinite;flex-shrink:0">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
            </svg>
            Scanning your sales history for patterns…
        </div>

        <div id="suggestions-error" class="suggestions-error" style="display:none"></div>

        <div id="suggestions-results" style="display:none">
            <div class="suggestions-header">
                <p class="suggestions-title">Pattern Suggestions</p>
                <p id="suggestions-summary" class="suggestions-summary"></p>
                <button class="suggestions-close" onclick="closeSuggestions()">✕</button>
            </div>
            <div id="suggestions-list"></div>
            <div id="suggestions-none" class="suggestions-none" style="display:none">
                No new patterns detected — your existing events already cover all recurring spikes in your data.
            </div>
            <div id="suggestions-weekly-section" class="suggestions-weekly-section" style="display:none">
                <p class="suggestions-weekly-title">Weekly patterns — already handled by the forecast model</p>
                <div id="suggestions-weekly-list" class="suggestions-weekly-list"></div>
            </div>
        </div>

    </div>

    <!-- ── Hidden pool: PHP renders all rows here; JS moves them into the list ── -->
    <div id="event-rows-pool" style="display:none">
        <?php foreach ($events as $ev): ?>
        <div class="event-row"
             id="event-row-<?php echo $ev['id']; ?>"
             data-event-id="<?php echo $ev['id']; ?>"
             data-name="<?php echo htmlspecialchars($ev['name']); ?>"
             data-next-occ="<?php echo htmlspecialchars($ev['next_occurrence'] ?? ''); ?>"
             onclick="goToDetail(<?php echo $ev['id']; ?>)">

            <span class="event-dot" style="background:<?php echo htmlspecialchars($ev['color'] ?? '#FF5722'); ?>"></span>

            <span class="event-name"><?php echo htmlspecialchars($ev['name']); ?></span>

            <span class="event-col-schedule"><?php echo htmlspecialchars(formatEventSchedule($ev)); ?></span>

            <!-- Confidence column — always rendered; empty cell keeps grid alignment -->
            <span class="event-col-conf">
                <?php $conf = getConfidence($ev); if ($conf): ?>
                <span class="event-conf-badge conf-<?php echo $conf['css']; ?>"
                      title="<?php echo htmlspecialchars($conf['title']); ?>">
                    <?php echo $conf['label']; ?>
                </span>
                <?php endif; ?>
            </span>

            <!-- Next-occurrence column — always rendered; empty cell keeps grid alignment -->
            <span class="event-col-next">
                <?php if ($ev['next_occurrence']): ?>
                <span class="event-next-occ">
                    <?php
                    $nextDt   = new DateTime($ev['next_occurrence']);
                    $today    = new DateTime(date('Y-m-d'));
                    $diffDays = (int) $today->diff($nextDt)->days;
                    if ($diffDays === 0) {
                        echo 'Today';
                    } elseif ($diffDays === 1) {
                        echo 'Tomorrow';
                    } elseif ($diffDays <= 7) {
                        echo 'In ' . $diffDays . 'd';
                    } else {
                        echo 'Next ' . $nextDt->format('M j');
                    }
                    ?>
                </span>
                <?php endif; ?>
            </span>

            <!-- Actions column — always rendered; hide for preset, edit+delete for own events -->
            <div class="event-col-actions" onclick="event.stopPropagation()">
                <?php if ($ev['is_seeded']): ?>
                <span class="event-seeded-badge">Preset</span>
                <button class="event-action-btn delete"
                        onclick="confirmHidePresetEvent(<?php echo $ev['id']; ?>, '<?php echo htmlspecialchars(addslashes($ev['name'])); ?>')"
                        title="Hide this preset">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
                <?php elseif (!$ev['is_seeded'] && (int)$ev['user_id'] === (int)$_SESSION['user_id']): ?>
                <button class="event-action-btn"
                        onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ev)); ?>)"
                        title="Edit">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>
                <button class="event-action-btn delete"
                        onclick="confirmDeleteEvent(<?php echo $ev['id']; ?>, '<?php echo htmlspecialchars(addslashes($ev['name'])); ?>')"
                        title="Delete">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6"/><path d="M14 11v6"/>
                        <path d="M9 6V4h6v2"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Visible list — filled by renderEvents() ─────────────────────────── -->
    <div class="events-list" id="events-list">
        <div class="events-empty">Loading…</div>
    </div>

    <!-- ── Hidden presets footer ─────────────────────────────────────────────── -->
    <div id="hidden-events-footer" style="display:none; margin-top:1.25rem">
        <button class="hidden-events-toggle" id="hidden-events-toggle" onclick="toggleHiddenPanel()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
            </svg>
            <span id="hidden-events-label">Show hidden presets</span>
        </button>

        <div id="hidden-events-panel" style="display:none; margin-top:0.625rem">
            <div id="hidden-events-list"></div>
        </div>
    </div>

</main>


<!-- ════════════════════════════════════════════
     CREATE / EDIT MODAL
════════════════════════════════════════════ -->
<div id="event-modal-overlay" class="event-modal-overlay hidden">
    <div class="event-modal">

        <div class="event-modal-header">
            <h2 id="modal-title">Add Event</h2>
            <button class="event-modal-close" onclick="closeModal()">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="event-modal-body">

            <input type="hidden" id="modal-event-id" value="">

            <div class="form-field">
                <label class="form-label" for="modal-name">Event Name *</label>
                <input type="text" id="modal-name" class="form-input"
                       placeholder="e.g. Summer Sale, Fiesta Day">
            </div>

            <div class="form-field">
                <label class="form-label" for="modal-recurrence">Recurrence</label>
                <select id="modal-recurrence" class="form-select" onchange="handleRecurrenceChange()">
                    <option value="yearly">Every year</option>
                    <option value="monthly">Every month</option>
                </select>
            </div>

            <!-- Color picker -->
            <div class="form-field">
                <label class="form-label">Color</label>
                <div class="color-picker-row" id="color-picker-row">
                    <?php
                    $swatches = ['#FF5722','#EF4444','#F59E0B','#EAB308','#22C55E','#3B82F6','#8B5CF6','#EC4899'];
                    foreach ($swatches as $sw):
                    ?>
                    <button type="button" class="color-swatch"
                            data-color="<?php echo $sw; ?>"
                            style="background:<?php echo $sw; ?>"
                            onclick="selectColor('<?php echo $sw; ?>', this)"
                            title="<?php echo $sw; ?>"></button>
                    <?php endforeach; ?>
                    <!-- Custom color -->
                    <label class="color-swatch color-swatch-custom" title="Custom color">
                        <input type="color" id="modal-color-custom"
                               oninput="selectColor(this.value, null, true)">
                    </label>
                </div>
                <input type="hidden" id="modal-color" value="#FF5722">
            </div>

            <!-- Last-day-of-month option, only visible when recurrence=monthly -->
            <div id="last-day-row" class="hidden">
                <label class="form-checkbox-row">
                    <input type="checkbox" id="modal-is-last-day" onchange="handleLastDayChange()">
                    Use last day of month (instead of a fixed date)
                </label>
            </div>

            <div class="form-grid-2">
                <div class="form-field">
                    <label class="form-label" for="modal-start">Start Date *</label>
                    <input type="date" id="modal-start" class="form-input">
                </div>
                <div class="form-field">
                    <label class="form-label" for="modal-end">End Date
                        <span style="opacity:0.5;font-weight:400">(optional)</span>
                    </label>
                    <input type="date" id="modal-end" class="form-input">
                </div>
            </div>

            <div class="form-field">
                <label class="form-label" for="modal-note">Notes
                    <span style="opacity:0.5;font-weight:400">(optional)</span>
                </label>
                <textarea id="modal-note" class="form-textarea"
                          placeholder="Describe how this event typically affects sales…"></textarea>
            </div>

        </div>

        <div class="event-modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" onclick="saveEvent()">Save Event</button>
        </div>

    </div>
</div>


<!-- ════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════ -->
<script>
const TODAY  = '<?php echo date('Y-m-d'); ?>';
const CUTOFF = '<?php echo date('Y-m-d', strtotime('+2 months')); ?>';

let allEventRows = []; // collected once; rows are moved out of the pool on first render

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Collect all rows before the first render moves them into #events-list.
    allEventRows = Array.from(
        document.querySelectorAll('#event-rows-pool .event-row[data-event-id]')
    );
    renderEvents();
    loadHiddenCount();
});

// ── Render / filter / sort / group ────────────────────────────────────────────
function renderEvents() {
    const search = document.getElementById('event-search').value.toLowerCase().trim();
    const sort   = document.getElementById('event-sort').value;

    // Use the rows collected on init — querying the pool again would return nothing
    // because rows are moved into #events-list after the first render.
    const rows = allEventRows;

    // Filter.
    const filtered = rows.filter(function(row) {
        if (search && !row.dataset.name.toLowerCase().includes(search)) return false;
        return true;
    });

    // Sort.
    filtered.sort(function(a, b) {
        const aOcc = a.dataset.nextOcc || '';
        const bOcc = b.dataset.nextOcc || '';
        switch (sort) {
            case 'name':
                return a.dataset.name.localeCompare(b.dataset.name);
            case 'date_desc':
                // No next occurrence → sort to end (they are in the past).
                if (!aOcc && !bOcc) return a.dataset.name.localeCompare(b.dataset.name);
                if (!aOcc) return 1;
                if (!bOcc) return -1;
                return bOcc.localeCompare(aOcc);
            default: // date_asc — upcoming first, past / no-occurrence last
                if (!aOcc && !bOcc) return a.dataset.name.localeCompare(b.dataset.name);
                if (!aOcc) return 1;
                if (!bOcc) return -1;
                return aOcc.localeCompare(bOcc);
        }
    });

    // Group: events whose next occurrence falls within 2 months are "Upcoming".
    const upcoming = filtered.filter(function(row) {
        const occ = row.dataset.nextOcc;
        return occ && occ >= TODAY && occ <= CUTOFF;
    });
    const rest = filtered.filter(function(row) {
        const occ = row.dataset.nextOcc;
        return !(occ && occ >= TODAY && occ <= CUTOFF);
    });

    // Rebuild the visible list.
    const list = document.getElementById('events-list');
    list.innerHTML = '';

    if (filtered.length === 0) {
        list.innerHTML = '<div class="events-empty">No events match your search.</div>';
        return;
    }

    if (upcoming.length > 0) {
        list.appendChild(makeSectionHeader('Upcoming'));
        upcoming.forEach(function(row) { list.appendChild(row); });
    }

    if (rest.length > 0) {
        if (upcoming.length > 0) {
            list.appendChild(makeSectionHeader('All Events'));
        }
        rest.forEach(function(row) { list.appendChild(row); });
    }
}

function makeSectionHeader(title) {
    const div = document.createElement('div');
    div.className   = 'events-section-header';
    div.textContent = title;
    return div;
}

function goToDetail(id) {
    window.location = '<?php echo BASE_URL; ?>/pages/event_detail.view.php?id=' + id;
}

// ── Modal open/close ──────────────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('modal-title').textContent   = 'Add Event';
    document.getElementById('modal-event-id').value      = '';
    document.getElementById('modal-name').value          = '';
    document.getElementById('modal-recurrence').value    = 'yearly';
    document.getElementById('modal-is-last-day').checked = false;
    document.getElementById('modal-start').value         = '';
    document.getElementById('modal-end').value           = '';
    document.getElementById('modal-note').value          = '';
    document.getElementById('last-day-row').classList.add('hidden');
    selectColor('#FF5722');
    showModal();
}

function openEditModal(ev) {
    document.getElementById('modal-title').textContent   = 'Edit Event';
    document.getElementById('modal-event-id').value      = ev.id;
    document.getElementById('modal-name').value          = ev.name;
    document.getElementById('modal-recurrence').value    = ev.recurrence;
    document.getElementById('modal-is-last-day').checked = ev.is_last_day == 1;
    document.getElementById('modal-start').value         = ev.event_start;
    document.getElementById('modal-end').value           = ev.event_end || '';
    document.getElementById('modal-note').value          = ev.impact_note || '';
    document.getElementById('last-day-row').classList.toggle('hidden', ev.recurrence !== 'monthly');
    selectColor(ev.color || '#FF5722');
    showModal();
}

function showModal() {
    document.getElementById('event-modal-overlay').classList.remove('hidden');
    document.getElementById('modal-name').focus();
}

function closeModal() {
    document.getElementById('event-modal-overlay').classList.add('hidden');
}

document.getElementById('event-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ── Color picker ─────────────────────────────────────────────────────────────
const PRESET_COLORS = ['#FF5722','#EF4444','#F59E0B','#EAB308','#22C55E','#3B82F6','#8B5CF6','#EC4899'];

function selectColor(hex, swatchEl, isCustom) {
    // Normalise to uppercase for consistent comparison
    const norm = hex.toUpperCase();
    document.getElementById('modal-color').value = norm;
    document.getElementById('modal-color-custom').value = norm;

    // Highlight the matching preset swatch (if any)
    document.querySelectorAll('#color-picker-row .color-swatch[data-color]').forEach(function(s) {
        const match = s.dataset.color.toUpperCase() === norm;
        s.classList.toggle('selected', match);
        // currentColor in the box-shadow resolves to the button's own color
        s.style.setProperty('color', match ? s.dataset.color : 'transparent');
    });
}

// ── Recurrence / last-day logic ───────────────────────────────────────────────
function handleRecurrenceChange() {
    const rec     = document.getElementById('modal-recurrence').value;
    const lastRow = document.getElementById('last-day-row');
    lastRow.classList.toggle('hidden', rec !== 'monthly');
    if (rec !== 'monthly') {
        document.getElementById('modal-is-last-day').checked = false;
    }
    handleLastDayChange();
}

function handleLastDayChange() {
    const isLastDay  = document.getElementById('modal-is-last-day').checked;
    const startInput = document.getElementById('modal-start');
    startInput.disabled = isLastDay;
    if (isLastDay) {
        startInput.value = startInput.value || '<?php echo date('Y-m-01'); ?>';
    }
}

// ── Save event (create or update) ────────────────────────────────────────────
async function saveEvent() {
    const id         = document.getElementById('modal-event-id').value;
    const name       = document.getElementById('modal-name').value.trim();
    const recurrence = document.getElementById('modal-recurrence').value;
    const isLastDay  = document.getElementById('modal-is-last-day').checked ? 1 : 0;
    const start      = document.getElementById('modal-start').value;
    const end        = document.getElementById('modal-end').value;
    const color      = document.getElementById('modal-color').value || '#FF5722';
    const note       = document.getElementById('modal-note').value.trim();

    if (!name || !start) {
        alert('Name and start date are required.');
        return;
    }

    const formData = new FormData();
    formData.append('action',      id ? 'update' : 'create');
    if (id) formData.append('id', id);
    formData.append('name',        name);
    formData.append('event_start', start);
    formData.append('event_end',   end);
    formData.append('recurrence',  recurrence);
    formData.append('is_last_day', isLastDay);
    formData.append('color',       color);
    formData.append('impact_note', note);

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/events.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

// ── Analyze: detect patterns via Flask ───────────────────────────────────────
async function runAnalysis() {
    const btn   = document.getElementById('analyze-btn');
    const panel = document.getElementById('suggestions-panel');

    btn.classList.add('running');
    btn.textContent = 'Analyzing…';
    panel.style.display = '';
    document.getElementById('suggestions-loading').style.display = 'flex';
    document.getElementById('suggestions-error').style.display   = 'none';
    document.getElementById('suggestions-results').style.display = 'none';

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/detect_patterns.php', { method: 'POST' });
        const data = await res.json();

        document.getElementById('suggestions-loading').style.display = 'none';

        if (data.error) {
            const errEl       = document.getElementById('suggestions-error');
            errEl.textContent = data.error;
            errEl.style.display = '';
        } else {
            renderSuggestions(data);
            document.getElementById('suggestions-results').style.display = '';
        }
    } catch (e) {
        document.getElementById('suggestions-loading').style.display = 'none';
        const errEl       = document.getElementById('suggestions-error');
        errEl.textContent = 'Network error. Make sure python/app.py is running.';
        errEl.style.display = '';
    }

    btn.classList.remove('running');
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/><path d="M11 8v6M8 11h6"/></svg> Analyze';
}

function closeSuggestions() {
    document.getElementById('suggestions-panel').style.display = 'none';
}

function renderSuggestions(data) {
    const summary  = data.data_summary || {};
    const summaryEl = document.getElementById('suggestions-summary');
    if (summary.date_from) {
        summaryEl.textContent = summary.date_from + ' → ' + summary.date_to
            + ' · ' + summary.total_days + ' days · ' + summary.years_count + ' year(s)';
    } else {
        summaryEl.textContent = '';
    }

    // Message override (e.g. not enough data)
    if (data.message) {
        document.getElementById('suggestions-none').textContent = data.message;
        document.getElementById('suggestions-none').style.display = '';
        document.getElementById('suggestions-list').innerHTML = '';
    } else {
        const suggestions = data.suggestions || [];
        const list        = document.getElementById('suggestions-list');
        list.innerHTML    = '';

        if (suggestions.length === 0) {
            document.getElementById('suggestions-none').style.display = '';
        } else {
            document.getElementById('suggestions-none').style.display = 'none';
            suggestions.forEach(function(s) {
                list.appendChild(buildSuggestionCard(s));
            });
        }
    }

    // Weekly insights
    const weekly     = data.weekly_insights || [];
    const weeklyWrap = document.getElementById('suggestions-weekly-section');
    const weeklyList = document.getElementById('suggestions-weekly-list');
    weeklyList.innerHTML = '';

    if (weekly.length > 0) {
        weeklyWrap.style.display = '';
        weekly.forEach(function(w) {
            const sign = w.impact_pct >= 0 ? '+' : '';
            const chip = document.createElement('span');
            chip.className   = 'weekly-insight-chip';
            chip.textContent = w.day + ': ' + sign + w.impact_pct + '%';
            weeklyList.appendChild(chip);
        });
    } else {
        weeklyWrap.style.display = 'none';
    }
}

function buildSuggestionCard(s) {
    const impact     = s.impact_pct;
    const impactSign = impact >= 0 ? '+' : '';
    const impactCls  = impact >= 0 ? '' : ' neg';

    // Format the date display
    let dateLabel;
    if (s.is_last_day) {
        dateLabel = 'Last day of month';
    } else if (s.recurrence === 'monthly') {
        const d = new Date(s.event_start + 'T00:00:00');
        const sfx = ['th','st','nd','rd'];
        const v = d.getDate() % 100;
        const ord = sfx[(v - 20) % 10] || sfx[Math.min(v, 3)] || sfx[0];
        dateLabel = d.getDate() + ord + ' of month';
    } else {
        const s_dt = new Date(s.event_start + 'T00:00:00');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        dateLabel = months[s_dt.getMonth()] + ' ' + s_dt.getDate();
        if (s.event_end && s.event_end !== s.event_start) {
            const e_dt = new Date(s.event_end + 'T00:00:00');
            dateLabel += '–' + months[e_dt.getMonth()] + ' ' + e_dt.getDate();
        }
    }

    const card = document.createElement('div');
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

    // Store event data on the card for addSuggestedEvent()
    card.dataset.recurrence  = s.recurrence;
    card.dataset.eventStart  = s.event_start || '';
    card.dataset.eventEnd    = s.event_end   || '';
    card.dataset.isLastDay   = s.is_last_day ? '1' : '0';

    return card;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;');
}

async function addSuggestedEvent(btn) {
    const card       = btn.closest('.suggestion-card');
    const nameInput  = card.querySelector('.suggestion-name-input');
    const name       = nameInput.value.trim();
    if (!name) { nameInput.focus(); return; }

    btn.disabled    = true;
    btn.textContent = 'Adding…';

    const formData = new FormData();
    formData.append('action',      'create');
    formData.append('name',        name);
    formData.append('recurrence',  card.dataset.recurrence);
    formData.append('event_start', card.dataset.eventStart);
    formData.append('event_end',   card.dataset.eventEnd);
    formData.append('is_last_day', card.dataset.isLastDay);
    formData.append('impact_note', '');

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/events.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            card.style.transition = 'opacity 0.25s';
            card.style.opacity    = '0';
            setTimeout(function() {
                card.remove();
                // If no suggestion cards remain, show the "none" message
                const remaining = document.querySelectorAll('#suggestions-list .suggestion-card');
                if (remaining.length === 0) {
                    document.getElementById('suggestions-none').textContent =
                        'All suggestions added. Reload the page to see the new events.';
                    document.getElementById('suggestions-none').style.display = '';
                }
            }, 260);
        } else {
            alert('Error: ' + data.error);
            btn.disabled    = false;
            btn.textContent = 'Add Event';
        }
    } catch (e) {
        alert('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Add Event';
    }
}

// ── Hidden presets ────────────────────────────────────────────────────────────
let hiddenPanelOpen   = false;
let hiddenEventsCache = null; // null = not yet loaded

async function loadHiddenCount() {
    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/events.php', {
            method: 'POST',
            body: (function() { const f = new FormData(); f.append('action','get_hidden'); return f; })(),
        });
        const data = await res.json();
        const list = data.hidden || [];
        hiddenEventsCache = list;
        const footer = document.getElementById('hidden-events-footer');
        const label  = document.getElementById('hidden-events-label');
        if (list.length > 0) {
            footer.style.display = '';
            label.textContent = 'Show ' + list.length + ' hidden preset' + (list.length !== 1 ? 's' : '');
        } else {
            footer.style.display = 'none';
        }
    } catch (e) { /* silently ignore */ }
}

function toggleHiddenPanel() {
    hiddenPanelOpen = !hiddenPanelOpen;
    const panel  = document.getElementById('hidden-events-panel');
    const label  = document.getElementById('hidden-events-label');
    const count  = hiddenEventsCache ? hiddenEventsCache.length : 0;
    panel.style.display = hiddenPanelOpen ? '' : 'none';
    if (hiddenPanelOpen) {
        label.textContent = 'Hide ' + count + ' hidden preset' + (count !== 1 ? 's' : '');
        renderHiddenList();
    } else {
        label.textContent = 'Show ' + count + ' hidden preset' + (count !== 1 ? 's' : '');
    }
}

function renderHiddenList() {
    const listEl = document.getElementById('hidden-events-list');
    listEl.innerHTML = '';
    const list = hiddenEventsCache || [];
    if (list.length === 0) {
        listEl.innerHTML = '<p style="font-size:0.78rem;opacity:0.45;margin:0.25rem 0">No hidden presets.</p>';
        return;
    }
    list.forEach(function(ev) {
        const row = document.createElement('div');
        row.className = 'hidden-event-row';
        row.id = 'hidden-row-' + ev.id;

        const dot = document.createElement('span');
        dot.className = 'event-dot';
        dot.style.background = ev.color || '#FF5722';
        dot.style.flexShrink = '0';

        const name = document.createElement('span');
        name.className   = 'hidden-event-name';
        name.textContent = ev.name;

        const sched = document.createElement('span');
        sched.className = 'hidden-event-schedule';
        sched.textContent = ev.recurrence === 'yearly'  ? 'Every year'
                          : ev.is_last_day              ? 'Last day of month'
                          : 'Every month';

        const btn = document.createElement('button');
        btn.className   = 'restore-btn';
        btn.textContent = 'Restore';
        btn.onclick = function() { doUnhideEvent(ev.id, btn); };

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

    const formData = new FormData();
    formData.append('action', 'unhide');
    formData.append('id', id);

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/events.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            // Remove from cache and fade out the row
            hiddenEventsCache = hiddenEventsCache.filter(function(ev) { return ev.id != id; });
            const row = document.getElementById('hidden-row-' + id);
            if (row) {
                row.style.transition = 'opacity 0.25s';
                row.style.opacity    = '0';
                setTimeout(function() { row.remove(); }, 260);
            }
            // Update label count or close footer if none left
            const count  = hiddenEventsCache.length;
            const footer = document.getElementById('hidden-events-footer');
            const label  = document.getElementById('hidden-events-label');
            if (count === 0) {
                footer.style.display = 'none';
                hiddenPanelOpen = false;
            } else {
                const verb = hiddenPanelOpen ? 'Hide ' : 'Show ';
                label.textContent = verb + count + ' hidden preset' + (count !== 1 ? 's' : '');
            }
            // Reload the page so the restored event appears in the main list
            setTimeout(function() { window.location.reload(); }, 400);
        } else {
            alert('Error: ' + data.error);
            btn.disabled    = false;
            btn.textContent = 'Restore';
        }
    } catch (e) {
        alert('Network error. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Restore';
    }
}

// ── Info modal ────────────────────────────────────────────────────────────────
function openInfoModal() {
    document.getElementById('info-modal-overlay').classList.remove('hidden');
}
function closeInfoModal() {
    document.getElementById('info-modal-overlay').classList.add('hidden');
}

// Spin animation
const _spinStyle = document.createElement('style');
_spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(_spinStyle);

// ── Delete event ──────────────────────────────────────────────────────────────
function confirmDeleteEvent(id, name) {
    showConfirm({
        title:        'Delete Event?',
        message:      '"' + name + '" will be permanently deleted.',
        confirmText:  'Delete',
        confirmStyle: 'danger',
        onConfirm:    function() { deleteEvent(id); },
    });
}

async function deleteEvent(id) {
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/events.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('event-row-' + id);
            if (row) {
                row.style.transition = 'opacity 0.25s';
                row.style.opacity    = '0';
                setTimeout(function() {
                    row.remove();
                    renderEvents(); // re-render to update section headers
                }, 260);
            }
        } else {
            alert('Delete failed: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

// ── Hide preset event ─────────────────────────────────────────────────────────
function confirmHidePresetEvent(id, name) {
    showConfirm({
        title:        'Hide Preset Event?',
        message:      '"' + name + '" will be hidden from your list. It won\'t appear on your charts or affect your forecast.',
        confirmText:  'Hide',
        confirmStyle: 'danger',
        onConfirm:    function() { doHideEvent(id); },
    });
}

async function doHideEvent(id) {
    const formData = new FormData();
    formData.append('action', 'hide');
    formData.append('id', id);

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/events.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('event-row-' + id);
            if (row) {
                row.style.transition = 'opacity 0.25s';
                row.style.opacity    = '0';
                setTimeout(function() {
                    // Remove from allEventRows so it doesn't reappear on re-render
                    allEventRows = allEventRows.filter(function(r) { return r !== row; });
                    row.remove();
                    renderEvents();
                }, 260);
            }
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}
</script>

<!-- ════════════════════════════════════════════
     INFO MODAL
════════════════════════════════════════════ -->
<div id="info-modal-overlay" class="event-modal-overlay hidden">
    <div class="event-modal" style="max-width:560px">

        <div class="event-modal-header">
            <h2>How Events Work</h2>
            <button class="event-modal-close" onclick="closeInfoModal()">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="event-modal-body" style="gap:1.25rem">

            <div class="info-section">
                <p class="info-section-title">What events do</p>
                <p class="info-section-body">
                    Events mark important recurring dates on your sales chart and are automatically
                    included as <strong>regressors</strong> in the forecast model. This means the
                    model learns each event's specific sales effect from your history and projects
                    it into future forecasts. Adding an event improves accuracy for products that
                    are affected by it; removing an event excludes it from the next run.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Impact badge &nbsp; ↑ +32% &nbsp; ↓ −18%</p>
                <p class="info-section-body">
                    Shows the overall % effect this event has on daily sales, derived from the
                    <strong>forecast model</strong> (Prophet regressor analysis). The model isolates
                    the event's contribution from trend and weekly seasonality — making it more
                    reliable than a simple before/after comparison. The badge appears after you run
                    your first forecast that includes this event. Click any event row to see the
                    full product-by-product breakdown with confidence levels.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Confidence levels</p>
                <p class="info-section-body">
                    Tells you how trustworthy the model's impact figure is, based on how many times
                    the event has appeared in your sales history.
                </p>
                <div class="info-conf-table">
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-strong">Strong</span>
                        <span>Yearly: 4+ occurrences &nbsp;·&nbsp; Monthly: 12+</span>
                    </div>
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-moderate">Moderate</span>
                        <span>Yearly: 2–3 &nbsp;·&nbsp; Monthly: 6–11</span>
                    </div>
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-weak">Weak</span>
                        <span>Only 1 occurrence — treat with caution</span>
                    </div>
                </div>
            </div>

            <div class="info-section">
                <p class="info-section-title">Recurrence</p>
                <p class="info-section-body">
                    <strong>Every year</strong> repeats on the same date each year (e.g. Dec 25).
                    <strong>Every month</strong> repeats on a fixed day each month — either a specific
                    date (e.g. the 15th) or the last day of the month.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Analyze</p>
                <p class="info-section-body">
                    Scans your sales history for recurring spikes not yet covered by an existing event —
                    both yearly (dates that consistently spike year-over-year) and monthly (days that
                    consistently spike month-over-month). Each suggestion shows a confidence level and
                    estimated impact. Nothing is added automatically — you review and name each one.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Preset events</p>
                <p class="info-section-body">
                    Built-in events for common Filipino convenience store patterns (paydays, Christmas,
                    New Year, All Souls Day, etc.). You can hide any preset you don't need — click the
                    eye-off icon on its row. Hidden presets won't appear on your charts or affect your forecast.
                    To restore them, click <strong>Show hidden presets</strong> at the bottom of the events list.
                </p>
            </div>

        </div>

        <div class="event-modal-footer">
            <button class="btn-save" onclick="closeInfoModal()">Got it</button>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>
