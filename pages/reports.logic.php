<?php
// pages/reports.logic.php
// Auth guard and data loading for the saved forecasts (Reports) page.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/reports.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/events.query.php';
require_once __DIR__ . '/../queries/user.query.php';

$_rUser   = getUserById($pdo, $_SESSION['user_id']);
$userName = $_rUser ? $_rUser['name'] : 'Store Owner';

$sessions = getForecastSessions($pdo, $_SESSION['user_id']);

// Catalogue-level accuracy snapshot — volume-weighted MAPE/MAE/RMSE across
// all evaluated products. This is the number to cite for "system accuracy."
$catalogueAccuracy   = getCatalogueAccuracy($pdo, $_SESSION['user_id']);

// Per-product breakdown — surfaces which products are dragging the average
// down so the diagnosis is concrete rather than aggregate.
$productBreakdown    = getProductAccuracyBreakdown($pdo, $_SESSION['user_id']);

// Events for chart annotations in the detail modal
$saleDateRange = getUserSaleDateRange($pdo, $_SESSION['user_id']);
$expandFrom    = $saleDateRange['earliest']
    ? date('Y-m-d', strtotime($saleDateRange['earliest'] . ' -30 days'))
    : date('Y-m-d', strtotime('-3 years'));
$expandTo      = date('Y-m-d', strtotime('+1 year'));
$rawEvents     = getRawEventsForUser($pdo, $_SESSION['user_id']);
$chartEvents   = expandEvents($rawEvents, $expandFrom, $expandTo);
