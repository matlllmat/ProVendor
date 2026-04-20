<?php
// api/events.php
// CRUD API for user-created seasonal events.
// Input  (POST): { action, ...fields }
// Output (JSON): { success: true } or { error: "..." }

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/events.query.php';

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
        if (!in_array($recurrence, ['none', 'yearly', 'monthly'], true)) {
            echo json_encode(['error' => 'Invalid recurrence.']);
            exit;
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
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#FF5722';
        }

        $ok = updateEvent($pdo, $id, $_SESSION['user_id'], $name, $eventStart, $eventEnd, $recurrence, $isLastDay, $color, $impactNote);
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
