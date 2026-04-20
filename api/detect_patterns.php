<?php
// api/detect_patterns.php
// Bridge: sends this user's existing events to Flask /detect_patterns
// and returns pattern suggestions + weekly insights.

session_start();
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

$ch = curl_init('http://localhost:5000/detect_patterns');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['error' => 'Cannot reach the forecast server. Make sure python/app.py is running.']);
    exit;
}

http_response_code($httpCode);
echo $result;
