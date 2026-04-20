<?php
// pages/event_detail.logic.php
// Auth guard and data loading for the Event Detail page.

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

// Occurrence count — use the real sales date range so the count matches actual history.
$dateRange    = getUserSaleDateRange($pdo, $_SESSION['user_id']);
$historyStart = $dateRange['earliest'] ?? date('Y-m-d', strtotime('-2 years'));
$historyEnd   = $dateRange['latest']   ?? date('Y-m-d');
$occurrences  = expandEvents([$event], $historyStart, $historyEnd);

// Load Prophet regressor cache for this event (populated during forecast runs).
$prophetCache = getEventImpactCache($pdo, $eventId);

// Fallback: populate avg_impact_pct from window method when no Prophet cache exists yet.
// Once Prophet has run at least once, refreshEventAvgImpact() owns avg_impact_pct.
if (empty($prophetCache)) {
    $windows   = buildEventWindows($occurrences, 7);
    if (!empty($windows)) {
        $avgImpact = computeAvgImpactPct($pdo, $_SESSION['user_id'], $windows);
        if ($avgImpact !== null) {
            updateEventImpactCache($pdo, $eventId, $avgImpact);
        }
    }
}
