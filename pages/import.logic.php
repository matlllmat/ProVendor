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

// A linked Google Sheet replaces CSV upload as the way sales data arrives, so
// the Sales Data tab renders quite differently depending on this. null = no link.
require_once __DIR__ . '/../queries/sheets.query.php';
$sheetLink = getSheetLink($pdo, (int) $_SESSION['user_id']);

// Last background forecast run — the Forecast Range tab shows when the catalogue
// was last forecast and whether a run is in flight right now.
$lastJob = getLatestForecastJob($pdo, (int) $_SESSION['user_id']);

// How the window is kept current (manual / auto) and how far ahead it currently
// reaches — drives the mode switch and the "up to date" indicator.
require_once __DIR__ . '/../queries/forecast.query.php';
$forecastMode     = getForecastMode($pdo, (int) $_SESSION['user_id']);
$forecastCoverage = getForecastCoverage(
    $pdo, (int) $_SESSION['user_id'],
    (int) ($profile['forecast_horizon_days'] ?? 30)
);

// Reports tab: the system's own accuracy, tested automatically against each
// product's held-out recent history — no upload needed. Hidden when the flag
// is off. The page renders whatever's cached; untested products are quietly
// backfilled by a background AJAX call the moment the tab is opened
// (api/run_catalogue_accuracy.php), so this stays cheap on every load.
if (SHOW_ACCURACY_FEATURES) {
    $catalogueAccuracy = getCatalogueAccuracy($pdo, $_SESSION['user_id']);
    $productBreakdown  = getProductAccuracyBreakdown($pdo, $_SESSION['user_id']);

    // "Backtest With Your Own Data" — only the most recent upload run is kept;
    // shown as-is on load so it's there to view again without re-uploading.
    require_once __DIR__ . '/../queries/backtest.query.php';
    $backtestRun = getBacktestRun($pdo, (int) $_SESSION['user_id']);
}
