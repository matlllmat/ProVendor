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

// Full profile is used by both the navbar (name) and the Profile tab.
$profile  = getUserProfile($pdo, $_SESSION['user_id']);
$userName = $profile ? $profile['name'] : 'Store Owner';

$sessions = getImportSessions($pdo, $_SESSION['user_id']);
$summary  = getImportSummary($pdo, $_SESSION['user_id']);
