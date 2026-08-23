<?php
// pages/reports.logic.php
// Auth guard and data loading for the Reports (forecast accuracy) page.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

// Accuracy reporting is hidden (see SHOW_ACCURACY_FEATURES in config/bootstrap.php).
// Guard the page itself, not just the nav link, so a stale tab or typed URL can't
// reach it either.
if (!SHOW_ACCURACY_FEATURES) {
    header('Location: ' . BASE_URL . '/pages/dashboard.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/user.query.php';

$_rUser   = getUserById($pdo, $_SESSION['user_id']);
$userName = $_rUser ? $_rUser['name'] : 'Store Owner';

// Catalogue-level accuracy snapshot — volume-weighted MAPE/MAE/RMSE across
// all evaluated products. This is the number to cite for "system accuracy."
$catalogueAccuracy = getCatalogueAccuracy($pdo, $_SESSION['user_id']);

// Per-product breakdown — surfaces which products are dragging the average
// down so the diagnosis is concrete rather than aggregate.
$productBreakdown  = getProductAccuracyBreakdown($pdo, $_SESSION['user_id']);
