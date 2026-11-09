<?php
// pages/events.logic.php
// Auth guard, data loading, and formatting helpers for the Events page.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/events.query.php';
require_once __DIR__ . '/../queries/user.query.php';

$user     = getUserById($pdo, $_SESSION['user_id']);
$userName = $user ? $user['name'] : 'Store Owner';

// Load all events at once — filtering, sorting, and grouping are handled client-side.
$events = getAllEventsForUser($pdo, $_SESSION['user_id']);

// Get the user's sales date range to compute confidence (occurrence count within real data).
$dateRange    = getUserSaleDateRange($pdo, $_SESSION['user_id']);
$historyStart = $dateRange['earliest'] ?? null;
$historyEnd   = $dateRange['latest']   ?? null;

// Only days the forecast can learn from count as evidence for an event.
$saleDates = ($historyStart && $historyEnd) ? getSaleDates($pdo, $_SESSION['user_id']) : [];

$today = date('Y-m-d');
foreach ($events as &$ev) {
    $instances = ($historyStart && $historyEnd) ? expandEvents([$ev], $historyStart, $historyEnd) : [];
    $ev['next_occurrence'] = getNextOccurrenceDate($ev, $today);
    $ev['occurrence_count'] = countInstancesWithSales($instances, $saleDates);
}
unset($ev);

// ── Formatting helpers ────────────────────────────────────────────────────────

// Returns a human-readable schedule string for an event row.
function formatEventSchedule(array $event): string
{
    $start = new DateTime($event['event_start']);
    $end   = $event['event_end'] ? new DateTime($event['event_end']) : null;

    switch ($event['recurrence']) {
        case 'yearly':
            $str = $start->format('M j');
            if ($end) $str .= '–' . $end->format('M j');
            return $str . ' · Every year';

        case 'monthly':
            if ($event['is_last_day']) return 'Last day of month · Every month';
            return ordinal((int) $start->format('d')) . ' · Every month';

        case 'custom':
            $dates = $event['occurrences'] ?? [];
            $n     = count($dates);
            if ($n === 0) return 'No dates set';
            $first = new DateTime($dates[0]['start_date']);
            $last  = new DateTime($dates[$n - 1]['start_date']);
            $span  = $first->format('M Y') === $last->format('M Y')
                ? $first->format('M Y')
                : $first->format('M Y') . '–' . $last->format('M Y');
            return $n . ' specific date' . ($n !== 1 ? 's' : '') . ' · ' . $span;

        default: // none
            $str = $start->format('M j, Y');
            if ($end) $str .= ' – ' . $end->format('M j, Y');
            return $str;
    }
}

// Returns confidence badge data for an event, or null if no badge should be shown.
function getConfidence(array $event): ?array
{
    $count = $event['occurrence_count'] ?? 0;
    $rec   = $event['recurrence'];

    // Specific dates need a few in the history before the forecast will use them.
    if ($rec === 'custom' && $count < MIN_CUSTOM_DATES) {
        return ['label' => 'Not used yet', 'css' => 'unused',
                'title' => $count . ' of its dates ' . ($count === 1 ? 'is' : 'are') . ' in your sales history. '
                         . 'Add at least ' . MIN_CUSTOM_DATES . ' dates from your history before the forecast uses this event.'];
    }
    if ($count === 0) return null;

    // Custom dates carry no calendar rule, so evidence comes only from how many
    // listed dates fall inside real sales history.
    $detail = $rec === 'custom'
        ? $count . ' of its dates fall in your sales history.'
        : 'Seen ' . $count . ' time' . ($count !== 1 ? 's' : '') . ' in your sales history.';

    $level = confidenceLevel($rec, $count);
    $s = $level['strong'];
    $m = $level['moderate'];
    $rule = match ($level['css']) {
        'strong'   => "$s or more is Strong.",
        'moderate' => "$m to " . ($s - 1) . " is Moderate; $s or more is Strong.",
        default    => "Under $m is Weak; $m to " . ($s - 1) . ' is Moderate.',
    };
    return ['label' => $level['label'], 'css' => $level['css'], 'title' => "$detail $rule"];
}

// Converts an integer to its ordinal string: 1 → "1st", 15 → "15th".
function ordinal(int $n): string
{
    $suffix = ['th', 'st', 'nd', 'rd'];
    $v      = $n % 100;
    return $n . ($suffix[($v - 20) % 10] ?? $suffix[min($v, 3)] ?? $suffix[0]);
}

