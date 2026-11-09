<?php
// api/update_forecast_reminder.php
// Saves how many days after a forecast starts the owner is reminded to re-forecast.
// POST: reminder_days. CSRF verified by bootstrap.php.

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';

$userId  = (int) $_SESSION['user_id'];
$horizon = getForecastHorizon($pdo, $userId);
$days    = (int) ($_POST['reminder_days'] ?? 0);

// A reminder later than the forecast's last day would arrive after it has run out.
if ($days < 1 || $days > $horizon) {
    echo json_encode(['error' => 'Reminder must be between 1 and ' . $horizon . ' days, your forecast range.']);
    exit;
}

setForecastReminderDays($pdo, $userId, $days);
echo json_encode(['success' => true, 'reminder_days' => $days]);
