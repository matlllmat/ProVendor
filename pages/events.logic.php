<?php
// pages/events.logic.php
// Auth guard, data loading, and formatting helpers for the Events page.

define('BASE_URL', '/ProVendor');

session_start();

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

$today = date('Y-m-d');
foreach ($events as &$ev) {
    $ev['next_occurrence'] = getNextOccurrenceDate($ev, $today);
    $ev['occurrence_count'] = ($historyStart && $historyEnd)
        ? count(expandEvents([$ev], $historyStart, $historyEnd))
        : 0;
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
    if ($count === 0) return null;

    $rec = $event['recurrence'];

    if ($rec === 'monthly') {
        $detail = $count . ' monthly occurrence' . ($count !== 1 ? 's' : '');
        if ($count >= 12) return ['label' => 'Strong',   'css' => 'strong',   'title' => $detail];
        if ($count >= 6)  return ['label' => 'Moderate', 'css' => 'moderate', 'title' => $detail];
        return                   ['label' => 'Weak',     'css' => 'weak',     'title' => $detail];
    }

    // yearly (or legacy none)
    $detail = $count . ' yearly occurrence' . ($count !== 1 ? 's' : '');
    if ($count >= 4) return ['label' => 'Strong',   'css' => 'strong',   'title' => $detail];
    if ($count >= 2) return ['label' => 'Moderate', 'css' => 'moderate', 'title' => $detail];
    return                  ['label' => 'Weak',     'css' => 'weak',     'title' => $detail];
}

// Converts an integer to its ordinal string: 1 → "1st", 15 → "15th".
function ordinal(int $n): string
{
    $suffix = ['th', 'st', 'nd', 'rd'];
    $v      = $n % 100;
    return $n . ($suffix[($v - 20) % 10] ?? $suffix[min($v, 3)] ?? $suffix[0]);
}

