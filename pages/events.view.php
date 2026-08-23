<?php
// pages/events.view.php
// Events management page — list, create, edit, hide, restore, analyze.
//
// This file is intentionally thin:
//   - Page JS lives in assets/page_js/events.js
//   - Create/edit modal lives in includes/event_form_modal.php
//   - "How Events Work" modal lives in includes/event_info_modal.php
//   - Toast helper lives in includes/toast.php (+ assets/global_js/toast.js)
//   - Confirm dialog comes from includes/confirm_modal.php
//
// What stays here: the toolbar, the suggestions panel scaffold, the hidden
// pool of server-rendered event rows, and the hidden-presets footer — i.e.
// the page-specific structural HTML that the JS reads from / writes into.

require_once __DIR__ . '/events.logic.php';

$pageTitle = 'ProVendor — Events';
$pageCss   = 'events.css';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <!-- Page heading -->
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Events &amp; Seasonality</h1>
        <p class="events-subtitle">
            Holidays, paydays, and custom events that influence product demand. Click any event to see its impact.
        </p>
    </div>

    <!-- ── Toolbar: search · sort · analyze · info · add ──────────────────── -->
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

        <!-- Analyze button — sparkle icon + label span (JS only swaps the label) -->
        <button id="analyze-btn" class="events-analyze-btn" onclick="runAnalysis()">
            <svg class="analyze-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3l1.9 5.8a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-1.9a2 2 0 0 0 1.3-1.3z"/>
                <path d="M5 3v3"/><path d="M3.5 4.5h3"/>
                <path d="M19 17v3"/><path d="M17.5 18.5h3"/>
            </svg>
            <span class="analyze-label">Analyze</span>
        </button>

        <!-- Info button -->
        <button class="events-info-btn" onclick="openInfoModal()" title="How events work">?</button>

        <!-- Add button -->
        <button class="bg-[#261F0E] text-[#F0E8D0] rounded-xl px-4 py-2 text-sm font-semibold flex items-center gap-2 hover:opacity-80 transition-opacity events-add-btn"
                onclick="openCreateModal()">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Event
        </button>
    </div>

    <!-- ── Suggestions panel (revealed after Analyze) ─────────────────────── -->
    <div id="suggestions-panel" class="suggestions-panel hidden">

        <div id="suggestions-loading" class="suggestions-loading">
            <svg class="events-spinner" width="16" height="16" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
            </svg>
            Scanning your sales history for patterns…
        </div>

        <div id="suggestions-error" class="suggestions-error hidden"></div>

        <div id="suggestions-results" class="hidden">
            <div class="suggestions-header">
                <p class="suggestions-title">Pattern Suggestions</p>
                <p id="suggestions-summary" class="suggestions-summary"></p>
                <button class="suggestions-close" onclick="closeSuggestions()" aria-label="Close suggestions">&times;</button>
            </div>
            <div id="suggestions-list"></div>
            <div id="suggestions-none" class="suggestions-none hidden">
                No new patterns detected — your existing events already cover all recurring spikes in your data.
            </div>
            <div id="suggestions-weekly-section" class="suggestions-weekly-section hidden">
                <p class="suggestions-weekly-title">Weekly patterns — already handled by the forecast model</p>
                <div id="suggestions-weekly-list" class="suggestions-weekly-list"></div>
            </div>
        </div>

    </div>

    <!-- ── Hidden pool: PHP renders all rows here; JS moves them into #events-list ──
         Inline display:none as well as the class so the raw rows are NEVER visible
         even if events.css is slow/stale — prevents them showing alongside the list. -->
    <div id="event-rows-pool" class="event-rows-pool" style="display:none">
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

            <!-- Confidence column — always rendered; empty cell preserves grid alignment -->
            <span class="event-col-conf">
                <?php $conf = getConfidence($ev); if ($conf): ?>
                <span class="event-conf-badge conf-<?php echo $conf['css']; ?>"
                      title="<?php echo htmlspecialchars($conf['title']); ?>">
                    <?php echo $conf['label']; ?>
                </span>
                <?php endif; ?>
            </span>

            <!-- Next-occurrence column — always rendered; empty cell preserves grid alignment -->
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

            <!-- Actions column — Preset → hide button; own events → edit + delete -->
            <div class="event-col-actions" onclick="event.stopPropagation()">
                <?php if ($ev['is_seeded']): ?>
                <span class="event-seeded-badge">Preset</span>
                <button class="event-action-btn delete"
                        onclick="confirmHidePresetEvent(<?php echo $ev['id']; ?>, <?php echo htmlspecialchars(json_encode($ev['name']), ENT_QUOTES); ?>)"
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
                        onclick="confirmDeleteEvent(<?php echo $ev['id']; ?>, <?php echo htmlspecialchars(json_encode($ev['name']), ENT_QUOTES); ?>)"
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

    <!-- ── Visible list — populated by events.js renderEvents() ─────────────── -->
    <div class="events-list" id="events-list">
        <div class="events-empty">Loading…</div>
    </div>

    <!-- ── Hidden presets footer ────────────────────────────────────────────── -->
    <div id="hidden-events-footer" class="hidden-events-footer hidden">
        <button class="hidden-events-toggle" id="hidden-events-toggle" onclick="toggleHiddenPanel()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
            </svg>
            <span id="hidden-events-label">Show hidden presets</span>
        </button>

        <div id="hidden-events-panel" class="hidden-events-panel hidden">
            <div id="hidden-events-list"></div>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../includes/event_form_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/event_info_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/toast.php'; ?>

<!-- ════════════════════════════════════════════
     PAGE CONFIG + SCRIPT
════════════════════════════════════════════ -->
<script>
window.EVENTS_CONFIG = {
    today:   <?php echo json_encode(date('Y-m-d')); ?>,
    cutoff:  <?php echo json_encode(date('Y-m-d', strtotime('+2 months'))); ?>,
    baseUrl: <?php echo json_encode(BASE_URL); ?>,
};
</script>
<script src="<?php echo BASE_URL; ?>/assets/page_js/events.js?v=<?php echo filemtime(__DIR__ . '/../assets/page_js/events.js'); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
