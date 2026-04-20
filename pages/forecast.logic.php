<?php
// pages/forecast.logic.php
// Auth guard and data loading for the forecast page.

define('BASE_URL', '/ProVendor');

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/events.query.php';
require_once __DIR__ . '/../queries/user.query.php';

$_fUser   = getUserById($pdo, $_SESSION['user_id']);
$userName = $_fUser ? $_fUser['name'] : 'Store Owner';

// Only text search — category filtering is handled client-side by the chart tabs.
$search = trim($_GET['search'] ?? '');

// When arriving from the event_detail page, these pre-select a product and isolate an event.
$initialProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : null;
$initialEventId   = isset($_GET['event_id'])   ? (int) $_GET['event_id']   : null;

$categories = getCategories($pdo, $_SESSION['user_id']);
$products   = getProducts($pdo, $_SESSION['user_id'], $search, '');

// ── Events for chart annotations ──────────────────────────────────────────────
// Expand from the earliest sale date (so historical events render) to 1 year
// from today (so upcoming events render too).
// Using a fixed "-2 years" lookback would miss historical data older than 2 years.
$saleDateRange = getUserSaleDateRange($pdo, $_SESSION['user_id']);

$expandFrom = $saleDateRange['earliest']
    ? date('Y-m-d', strtotime($saleDateRange['earliest'] . ' -30 days'))
    : date('Y-m-d', strtotime('-3 years'));

$expandTo = date('Y-m-d', strtotime('+1 year'));

$rawEvents   = getRawEventsForUser($pdo, $_SESSION['user_id']);
$chartEvents = expandEvents($rawEvents, $expandFrom, $expandTo);
