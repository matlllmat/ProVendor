<?php
// pages/import.logic.php
// Auth guard and data loading for the Import Data + Profile page.

require_once __DIR__ . '/../config/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.view.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/import.query.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/version.query.php';
require_once __DIR__ . '/../queries/job.query.php';

// Full profile is used by both the navbar (name) and the Profile tab.
$profile  = getUserProfile($pdo, $_SESSION['user_id']);
$userName = $profile ? $profile['name'] : 'Store Owner';

$versions = listDatasetVersions($pdo, $_SESSION['user_id']);
$summary  = getImportSummary($pdo, $_SESSION['user_id']);

// Last background forecast run — the Forecast Range tab shows when the catalogue
// was last forecast and whether a run is in flight right now.
$lastJob = getLatestForecastJob($pdo, (int) $_SESSION['user_id']);
