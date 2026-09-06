<?php
// pages/reports.view.php
// The Reports page was merged into Settings (import.view.php) as its
// "Reports" tab. Kept as a redirect so old links / bookmarks still work.

require_once __DIR__ . '/../config/bootstrap.php';

if (!SHOW_ACCURACY_FEATURES) {
    header('Location: ' . BASE_URL . '/pages/dashboard.view.php');
    exit;
}

header('Location: ' . BASE_URL . '/pages/import.view.php#reports');
exit;
