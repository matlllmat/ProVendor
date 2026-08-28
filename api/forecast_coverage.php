<?php
// api/forecast_coverage.php
// Reports how far ahead the catalogue is actually forecast versus how far it
// should be, and — in AUTO mode — tops it back up when it has fallen behind.
//
// This is the piece that keeps the window at the owner's chosen length as days
// elapse. It is deliberately cheap and idempotent:
//   • it only starts a job when at least one product is actually short,
//   • the job runs in 'extend' mode, so the worker forecasts ONLY the days each
//     product is missing and reuses everything already stored,
//   • an up-to-date catalogue starts nothing and never touches Flask.
//
// GET/POST. Called on page load by the progress pill, and by the Settings page.
// Output: { mode, up_to_date, covered, behind, total, required_end, started }

require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/user.query.php';
require_once __DIR__ . '/../queries/forecast.query.php';
require_once __DIR__ . '/../queries/job.query.php';

$userId  = (int) $_SESSION['user_id'];
$mode    = getForecastMode($pdo, $userId);
$horizon = getForecastHorizon($pdo, $userId);

$cov = getForecastCoverage($pdo, $userId, $horizon);

$started = false;

// Auto mode: bring the window back up to length. Guarded three ways — only when
// something is behind, only when there are products, and never alongside a run
// that's already going.
if ($mode === 'auto'
    && !$cov['up_to_date']
    && $cov['total'] > 0
    && !hasActiveForecastJob($pdo, $userId)) {

    $jobId = createForecastJob($pdo, $userId, $horizon, $cov['total'], 'extend');
    session_write_close();   // don't hold the session lock across the spawn

    if (spawnForecastWorker($jobId)) {
        $started = true;
    } else {
        failForecastJob($pdo, $jobId, 'Could not start the background forecast worker.');
    }
}

echo json_encode([
    'mode'         => $mode,
    'up_to_date'   => $cov['up_to_date'],
    'covered'      => $cov['covered'],
    'behind'       => $cov['behind'],
    'never'        => $cov['never'],
    'total'        => $cov['total'],
    'required_end' => $cov['required_end'],
    'horizon'      => $horizon,
    'started'      => $started,
]);


// Spawns the detached CLI worker. Same approach as api/start_forecast_job.php:
// every candidate path is verified on disk first, because Windows `start` with an
// unresolvable program hangs the request instead of erroring.
function spawnForecastWorker(int $jobId): bool
{
    $candidates = [
        PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
        dirname(PHP_BINARY, 3) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
        'C:\\xampp\\php\\php.exe',
    ];
    $php = null;
    foreach ($candidates as $c) { if (is_file($c)) { $php = $c; break; } }
    if ($php === null) return false;

    $worker = realpath(__DIR__ . '/../cli/forecast_worker.php');
    if ($worker === false) return false;

    // No output redirection: it would keep the pipe open and make pclose() wait
    // for the whole run. The worker logs to cli/worker.log itself.
    $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg((string) $jobId);
    $h = popen('cmd /c ' . $cmd, 'r');
    if ($h === false) return false;
    pclose($h);
    return true;
}
