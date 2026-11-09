<?php
// api/detect_patterns.php
// Bridge: sends this user's existing events to Flask /detect_patterns
// and returns pattern suggestions + weekly insights.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/flask_client.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/events.query.php';

// Send existing events so Flask can suppress already-covered dates.
$existingEvents = getRawEventsForUser($pdo, $_SESSION['user_id']);

$payload = json_encode([
    'user_id'         => (int) $_SESSION['user_id'],
    'existing_events' => $existingEvents,
]);

[$result, $httpCode, $flaskError] = flaskRequest('/detect_patterns', $payload, 60);

if ($flaskError) {
    echo json_encode(['error' => 'Cannot reach the forecast server. Make sure python/app.py is running.']);
    exit;
}

http_response_code($httpCode);
echo $result;
