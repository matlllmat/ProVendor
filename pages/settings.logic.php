<?php
// pages/settings.logic.php
// Auth guard and data loading for the Settings page (forecast horizon).

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/events.query.php';

$profile  = getUserProfile($pdo, $_SESSION['user_id']);
$userName = $profile ? $profile['name'] : 'Store Owner';
$horizon  = $profile ? (int) $profile['forecast_horizon_days'] : 30;

// Last sale date — the re-forecast (after a horizon change) clamps the window's
// start to the day after this so Prophet only projects forward.
$saleRange    = getUserSaleDateRange($pdo, $_SESSION['user_id']);
$lastSaleDate = $saleRange['latest'] ?? null;
