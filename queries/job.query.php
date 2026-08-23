<?php
// queries/job.query.php
// Background forecast-job state. The job row is the single source of truth for
// "is a catalogue forecast running, and how far along is it" — written by the
// CLI worker (cli/forecast_worker.php), read by the progress pill's poll endpoint.

// A running job whose worker died (Flask crash, machine sleep, killed process)
// would otherwise show "running" forever. If nothing has updated the row in this
// many seconds, treat it as dead.
const FORECAST_JOB_STALE_SECONDS = 300;

// Creates a queued job and returns its id.
function createForecastJob(PDO $pdo, int $userId, int $horizonDays, int $total): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO forecast_jobs (user_id, status, horizon_days, total, done, failed)
         VALUES (?, "queued", ?, ?, 0, 0)'
    );
    $stmt->execute([$userId, $horizonDays, $total]);
    return (int) $pdo->lastInsertId();
}

// The user's most recent job, or null. Also flips a stalled "running" row to
// failed so the UI stops showing a phantom in-progress job.
function getLatestForecastJob(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_since_update
         FROM forecast_jobs
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $job = $stmt->fetch();
    if ($job === false) return null;

    if (in_array($job['status'], ['queued', 'running'], true)
        && (int) $job['seconds_since_update'] > FORECAST_JOB_STALE_SECONDS) {
        failForecastJob($pdo, (int) $job['id'], 'The forecast worker stopped responding.');
        $job['status'] = 'failed';
        $job['error']  = 'The forecast worker stopped responding.';
    }

    return $job;
}

// True when this user already has work in flight — used to avoid starting a
// second worker that would fight the first one over the same products.
function hasActiveForecastJob(PDO $pdo, int $userId): bool
{
    $job = getLatestForecastJob($pdo, $userId);
    return $job !== null && in_array($job['status'], ['queued', 'running'], true);
}

function markForecastJobRunning(PDO $pdo, int $jobId): void
{
    $pdo->prepare('UPDATE forecast_jobs SET status = "running" WHERE id = ?')->execute([$jobId]);
}

// Called after each product so the pill can show live progress. Touching
// updated_at here is also the worker's heartbeat for the staleness check above.
function updateForecastJobProgress(PDO $pdo, int $jobId, int $done, int $failed, ?string $currentProduct): void
{
    $pdo->prepare(
        'UPDATE forecast_jobs SET done = ?, failed = ?, current_product = ? WHERE id = ?'
    )->execute([$done, $failed, $currentProduct, $jobId]);
}

function finishForecastJob(PDO $pdo, int $jobId): void
{
    $pdo->prepare(
        'UPDATE forecast_jobs
         SET status = "done", current_product = NULL, finished_at = NOW()
         WHERE id = ?'
    )->execute([$jobId]);
}

function failForecastJob(PDO $pdo, int $jobId, string $error): void
{
    $pdo->prepare(
        'UPDATE forecast_jobs
         SET status = "failed", error = ?, finished_at = NOW()
         WHERE id = ?'
    )->execute([mb_substr($error, 0, 2000), $jobId]);
}
