<?php
// api/start_forecast_job.php
// Queues a catalogue-wide forecast and spawns the detached CLI worker that runs
// it, then returns immediately. The browser never waits for the forecasting
// itself — it just polls forecast_job_status.php for progress.
//
// POST (optional): horizon_days — saves it as the user's global horizon first
//                  (used by the onboarding "how far ahead?" step).

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/job.query.php';

$userId = (int) $_SESSION['user_id'];

// Optional horizon update (onboarding sends this; Settings saves it separately).
if (isset($_POST['horizon_days'])) {
    $days = (int) $_POST['horizon_days'];
    if ($days < 1 || $days > 60) {
        echo json_encode(['error' => 'Forecast range must be between 1 and 60 days.']);
        exit;
    }
    setForecastHorizon($pdo, $userId, $days);
}

// Don't let two workers fight over the same products.
if (hasActiveForecastJob($pdo, $userId)) {
    $job = getLatestForecastJob($pdo, $userId);
    echo json_encode([
        'success'      => true,
        'already'      => true,
        'job_id'       => (int) $job['id'],
        'message'      => 'A forecast run is already in progress.',
    ]);
    exit;
}

$horizon = getForecastHorizon($pdo, $userId);

$total = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE user_id = ' . $userId)->fetchColumn();
if ($total === 0) {
    echo json_encode(['error' => 'No products to forecast. Import sales data first.']);
    exit;
}

// 'extend' only fills in the days each window is missing; 'full' refits everything.
$mode  = (($_POST['mode'] ?? 'full') === 'extend') ? 'extend' : 'full';
$jobId = createForecastJob($pdo, $userId, $horizon, $total, $mode);

// Everything from the session has been read. Release the session lock before
// spawning: PHP holds it for the whole request, so any hiccup in the spawn would
// otherwise block this user's other requests (the progress poll included).
session_write_close();

// ── Locate the PHP CLI binary ────────────────────────────────────────────────
// Under mod_php, PHP_BINARY is httpd.exe and PHP_BINDIR is the compile-time
// path (C:\php on XAMPP builds) — neither points at php.exe. Guessing wrong is
// worse than failing: Windows `start` with an unresolvable program hangs the
// request instead of erroring, so every candidate is verified on disk first.
$phpCandidates = [
    PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
    // XAMPP layout: <root>\apache\bin\httpd.exe  →  <root>\php\php.exe
    dirname(PHP_BINARY, 3) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
    'C:\\xampp\\php\\php.exe',
];

$phpBinary = null;
foreach ($phpCandidates as $candidate) {
    if (is_file($candidate)) { $phpBinary = $candidate; break; }
}

if ($phpBinary === null) {
    failForecastJob($pdo, $jobId, 'Could not locate the PHP CLI binary (php.exe) to run the forecast worker.');
    echo json_encode(['error' => 'Could not locate PHP to run the background forecast. Check that php.exe is available.']);
    exit;
}

$worker = realpath(__DIR__ . '/../cli/forecast_worker.php');
if ($worker === false) {
    failForecastJob($pdo, $jobId, 'forecast_worker.php is missing.');
    echo json_encode(['error' => 'The background forecast worker is missing.']);
    exit;
}

// No output redirection here on purpose: a redirect makes the child hold the
// pipe cmd hands back, and pclose() then blocks until the whole forecast run
// finishes — exactly the waiting this feature exists to avoid. The worker logs
// to cli/worker.log itself instead.
$cmd = 'start /B "" '
     . escapeshellarg($phpBinary) . ' '
     . escapeshellarg($worker) . ' '
     . escapeshellarg((string) $jobId);

$handle = popen('cmd /c ' . $cmd, 'r');
if ($handle === false) {
    failForecastJob($pdo, $jobId, 'Could not start the background forecast worker.');
    echo json_encode(['error' => 'Could not start the background forecast worker.']);
    exit;
}
pclose($handle);

echo json_encode([
    'success' => true,
    'job_id'  => $jobId,
    'total'   => $total,
    'horizon' => $horizon,
    'mode'    => $mode,
]);
