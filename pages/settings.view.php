<?php
// pages/settings.view.php
// The Settings page was merged into My Store (import.view.php) as its
// "Forecast Range" tab. Kept as a redirect so old links / bookmarks still work.

require_once __DIR__ . '/../config/bootstrap.php';

header('Location: ' . BASE_URL . '/pages/import.view.php#forecast');
exit;
