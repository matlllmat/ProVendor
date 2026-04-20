<?php
// api/run_forecast.php
// AJAX bridge: receives category + days from the forecast page,
// calls Flask /forecast/category, and passes the JSON result back.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$category = $_POST['category'] ?? null;
$days     = max(7, min(365, (int) ($_POST['days'] ?? 30)));

$payload = json_encode([
    'user_id'  => (int) $_SESSION['user_id'],
    'category' => $category ?: null,
    'days'     => $days,
]);

$ch = curl_init('http://localhost:5000/forecast/category');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
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
