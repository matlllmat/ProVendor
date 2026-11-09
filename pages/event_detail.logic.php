<?php
// pages/event_detail.logic.php
// Auth guard and data loading for the Event Detail page.

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

$eventId = (int) ($_GET['id'] ?? 0);
if ($eventId <= 0) {
    header('Location: ' . BASE_URL . '/pages/events.view.php');
    exit;
}

$event = getEventById($pdo, $eventId, $_SESSION['user_id']);
if (!$event) {
    header('Location: ' . BASE_URL . '/pages/events.view.php');
    exit;
}

// An event the forecast doesn't use yet has no real effect to show.
$datesInHistory = eventDatesInHistory($pdo, $_SESSION['user_id'], $event);
$eventUnused    = !eventIsUsedByForecast($pdo, $_SESSION['user_id'], $event);

// Load Prophet regressor cache for this event (populated during forecast runs).
$prophetCache = $eventUnused ? [] : getEventImpactCache($pdo, $eventId, $_SESSION['user_id']);
