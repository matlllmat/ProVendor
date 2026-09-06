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

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'create':
        $name       = trim($_POST['name']        ?? '');
        $eventStart = trim($_POST['event_start'] ?? '');
        $eventEnd   = trim($_POST['event_end']   ?? '') ?: null;
        $recurrence = $_POST['recurrence']       ?? 'none';
        $isLastDay  = (int) ($_POST['is_last_day'] ?? 0);
        $color      = trim($_POST['color']       ?? '#FF5722');
        $impactNote = trim($_POST['impact_note'] ?? '') ?: null;

        if ($name === '' || $eventStart === '') {
            echo json_encode(['error' => 'Name and start date are required.']);
            exit;
        }
        if (!in_array($recurrence, ['none', 'yearly', 'monthly', 'custom'], true)) {
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
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'update':
        $id         = (int) ($_POST['id']          ?? 0);
        $name       = trim($_POST['name']          ?? '');
        $eventStart = trim($_POST['event_start']   ?? '');
        $eventEnd   = trim($_POST['event_end']      ?? '') ?: null;
        $recurrence = $_POST['recurrence']         ?? 'none';
        $isLastDay  = (int) ($_POST['is_last_day'] ?? 0);
        $color      = trim($_POST['color']         ?? '#FF5722');
        $impactNote = trim($_POST['impact_note']   ?? '') ?: null;

        if ($id <= 0 || $name === '' || $eventStart === '') {
            echo json_encode(['error' => 'Invalid input.']);
            exit;
        }
        if (!in_array($recurrence, ['none', 'yearly', 'monthly', 'custom'], true)) {
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

        $ok = updateEvent($pdo, $id, $_SESSION['user_id'], $name, $eventStart, $eventEnd, $recurrence, $isLastDay, $color, $impactNote);
        if ($ok) {
            // Always rewrite: switching a custom event to a rule-based one must
            // clear its stale dates, or expandEvents would still see them.
            replaceEventOccurrences($pdo, $id, $occurrences);
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
        $ok = deleteEvent($pdo, $id, $_SESSION['user_id']);
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
        echo json_encode(['success' => true]);
        break;

    case 'unhide':
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid event ID.']);
            exit;
        }
        unhideEvent($pdo, $id, $_SESSION['user_id']);
        echo json_encode(['success' => true]);
        break;

    case 'get_hidden':
        $hidden = getHiddenEventsForUser($pdo, $_SESSION['user_id']);
        echo json_encode(['hidden' => $hidden]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action.']);
}
