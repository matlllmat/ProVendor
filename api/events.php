<?php
// api/events.php
// CRUD API for user-created seasonal events.
// Input  (POST): { action, ...fields }
// Output (JSON): { success: true } or { error: "..." }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/events.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';   // invalidateProductAccuracy

// Parses the `occurrences` field sent for custom-date events: a JSON array of
// { start_date, end_date }. Returns [dates, errorMessage]; dates is sorted and
// de-duplicated so the same day cannot be counted twice as evidence.
function parseOccurrences(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [[], 'Could not read the date list.'];
    }

    $dates = [];
    $seen  = [];
    foreach ($decoded as $item) {
        $start = trim($item['start_date'] ?? '');
        $end   = trim($item['end_date']   ?? '') ?: null;

        if ($start === '' || strtotime($start) === false) {
            return [[], 'Every row needs a valid date.'];
        }
        if ($end !== null && (strtotime($end) === false || $end < $start)) {
            return [[], 'Each row\'s end date must be on or after its start date.'];
        }
        if (isset($seen[$start])) {
            return [[], 'The same start date is listed more than once.'];
        }
        $seen[$start] = true;
        $dates[] = ['start_date' => $start, 'end_date' => $end];
    }

    if (!$dates) {
        return [[], 'Add at least one date.'];
    }

    usort($dates, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));
    return [$dates, null];
}

// Flags the dashboard when an event change can affect the forecast. If the event
// has no past sales to learn from, queues a note for the Events page instead.
// $before / $after are the event as it was and is now (false = not active).
function reactToEventChange(PDO $pdo, int $userId, array|false $before, array|false $after): void
{
    if (($before && eventTouchesSalesHistory($pdo, $userId, $before))
        || ($after && eventTouchesSalesHistory($pdo, $userId, $after))) {
        markEventsChanged($pdo, $userId);
        // The accuracy tests use events too, so saved scores are now out of date.
        $ids = $pdo->prepare('SELECT id FROM products WHERE user_id = ?');
        $ids->execute([$userId]);
        invalidateProductAccuracy($pdo, $ids->fetchAll(PDO::FETCH_COLUMN));
    } elseif ($after && !empty(getUserSaleDateRange($pdo, $userId)['earliest'])) {
        // Some of its dates are in the history, but too few for the forecast to use.
        $_SESSION[eventDatesInHistory($pdo, $userId, $after) > 0 ? 'event_unused_note' : 'event_no_history_note'] = true;
    }
}

// An event the forecast no longer uses must not keep showing an old effect.
function dropEventEffectIfUnused(PDO $pdo, int $userId, int $eventId): void
{
    $event = getEventById($pdo, $eventId, $userId);
    if (!$event) return;
    if (eventIsUsedByForecast($pdo, $userId, $event)) return;
    $pdo->prepare('DELETE FROM event_impact_cache WHERE event_id = ?')->execute([$eventId]);
}

$action = $_POST['action'] ?? '';
$userId = (int) $_SESSION['user_id'];

switch ($action) {

    case 'create':
        $name       = trim($_POST['name']        ?? '');
        $eventStart = trim($_POST['event_start'] ?? '');
        $eventEnd   = trim($_POST['event_end']   ?? '') ?: null;
        $recurrence = $_POST['recurrence']       ?? '';
        $isLastDay  = (int) ($_POST['is_last_day'] ?? 0);
        $color      = trim($_POST['color']       ?? '#FF5722');
        $impactNote = trim($_POST['impact_note'] ?? '') ?: null;

        if ($name === '' || $eventStart === '') {
            echo json_encode(['error' => 'Name and start date are required.']);
            exit;
        }
        if (!in_array($recurrence, ['yearly', 'monthly', 'custom'], true)) {
            echo json_encode(['error' => 'Invalid recurrence.']);
            exit;
        }

        // Custom events carry their dates separately; event_start mirrors the
        // earliest of them so ordering and any event_start reader stay valid.
        $occurrences = [];
        if ($recurrence === 'custom') {
            [$occurrences, $occErr] = parseOccurrences($_POST['occurrences'] ?? '');
            if ($occErr) {
                echo json_encode(['error' => $occErr]);
                exit;
            }
            $eventStart = $occurrences[0]['start_date'];
            $eventEnd   = $occurrences[0]['end_date'];
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#FF5722';
        }
        if (strtotime($eventStart) === false) {
            echo json_encode(['error' => 'Invalid start date.']);
            exit;
        }
        if ($eventEnd !== null) {
            if (strtotime($eventEnd) === false) {
                echo json_encode(['error' => 'Invalid end date.']);
                exit;
            }
            if ($eventEnd < $eventStart) {
                echo json_encode(['error' => 'End date must be on or after start date.']);
                exit;
            }
        }

        $id = createEvent($pdo, $_SESSION['user_id'], $name, $eventStart, $eventEnd, $recurrence, $isLastDay, $color, $impactNote);
        if ($recurrence === 'custom') {
            replaceEventOccurrences($pdo, $id, $occurrences);
        }
        reactToEventChange($pdo, $userId, false, getEventById($pdo, $id, $userId));
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'update':
        $id         = (int) ($_POST['id']          ?? 0);
        $name       = trim($_POST['name']          ?? '');
        $eventStart = trim($_POST['event_start']   ?? '');
        $eventEnd   = trim($_POST['event_end']      ?? '') ?: null;
        $recurrence = $_POST['recurrence']         ?? '';
        $isLastDay  = (int) ($_POST['is_last_day'] ?? 0);
        $color      = trim($_POST['color']         ?? '#FF5722');
        $impactNote = trim($_POST['impact_note']   ?? '') ?: null;

        if ($id <= 0 || $name === '' || $eventStart === '') {
            echo json_encode(['error' => 'Invalid input.']);
            exit;
        }
        if (!in_array($recurrence, ['yearly', 'monthly', 'custom'], true)) {
            echo json_encode(['error' => 'Invalid recurrence.']);
            exit;
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#FF5722';
        }

        $occurrences = [];
        if ($recurrence === 'custom') {
            [$occurrences, $occErr] = parseOccurrences($_POST['occurrences'] ?? '');
            if ($occErr) {
                echo json_encode(['error' => $occErr]);
                exit;
            }
            $eventStart = $occurrences[0]['start_date'];
            $eventEnd   = $occurrences[0]['end_date'];
        }

        $before = getEventById($pdo, $id, $userId);
        $ok = updateEvent($pdo, $id, $_SESSION['user_id'], $name, $eventStart, $eventEnd, $recurrence, $isLastDay, $color, $impactNote);
        if ($ok) {
            // Always rewrite: switching a custom event to a rule-based one must
            // clear its stale dates, or expandEvents would still see them.
            replaceEventOccurrences($pdo, $id, $occurrences);

            // Name, color and note edits can't change the forecast; only dates can.
            $after = getEventById($pdo, $id, $userId);
            dropEventEffectIfUnused($pdo, $userId, $id);
            $dates = fn($e) => [$e['event_start'], $e['event_end'], $e['recurrence'], (int) $e['is_last_day'], $e['occurrences']];
            if ($dates($before) !== $dates($after)) {
                reactToEventChange($pdo, $userId, $before, $after);
            }
        }
        echo json_encode($ok
            ? ['success' => true]
            : ['error'   => 'Event not found or not editable.']
        );
        break;

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid event ID.']);
            exit;
        }
        $before = getEventById($pdo, $id, $userId);
        $ok = deleteEvent($pdo, $id, $_SESSION['user_id']);
        if ($ok) {
            reactToEventChange($pdo, $userId, $before, false);
        }
        echo json_encode($ok
            ? ['success' => true]
            : ['error'   => 'Event not found or not deletable.']
        );
        break;

    case 'hide':
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid event ID.']);
            exit;
        }
        hideEvent($pdo, $id, $_SESSION['user_id']);
        reactToEventChange($pdo, $userId, getEventById($pdo, $id, $userId), false);
        echo json_encode(['success' => true]);
        break;

    case 'unhide':
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid event ID.']);
            exit;
        }
        unhideEvent($pdo, $id, $_SESSION['user_id']);
        reactToEventChange($pdo, $userId, false, getEventById($pdo, $id, $userId));
        echo json_encode(['success' => true]);
        break;

    case 'get_hidden':
        $hidden = getHiddenEventsForUser($pdo, $_SESSION['user_id']);
        echo json_encode(['hidden' => $hidden]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action.']);
}
