<?php
// api/run_optimize.php
// AJAX bridge: receives forecast data + cost/price inputs from the forecast modal,
// calls Flask /optimize (Newsvendor model), and passes the JSON result back.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

$forecastJson = $_POST['forecast']      ?? '[]';
$costPrice    = (float) ($_POST['cost_price']    ?? 0);
$sellingPrice = (float) ($_POST['selling_price'] ?? 0);
$currentStock = (int)   ($_POST['current_stock'] ?? 0);

$forecastData = json_decode($forecastJson, true);
if (!is_array($forecastData)) {
    echo json_encode(['error' => 'Invalid forecast data.']);
    exit;
}

$payload = json_encode([
    'forecast'      => $forecastData,
    'cost_price'    => $costPrice,
    'selling_price' => $sellingPrice,
    'current_stock' => $currentStock,
]);

$ch = curl_init('http://localhost:5000/optimize');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
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
