<?php
// queries/backtest.query.php
// The "Backtest With Your Own Data" upload run — exactly one saved per owner,
// overwritten by each new run, deleted outright by "Clear".

// Upserts the owner's saved run. $data keys: original_filename, stored_path,
// evaluated_count, total_count, weighted_mape, weighted_mae, weighted_rmse,
// weighted_accuracy_pct, rows_parsed, rows_dropped.
function saveBacktestRun(PDO $pdo, int $userId, array $data): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO backtest_runs
             (user_id, original_filename, stored_path, evaluated_count, total_count,
              weighted_mape, weighted_mae, weighted_rmse, weighted_accuracy_pct,
              rows_parsed, rows_dropped)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             original_filename     = VALUES(original_filename),
             stored_path           = VALUES(stored_path),
             evaluated_count       = VALUES(evaluated_count),
             total_count           = VALUES(total_count),
             weighted_mape         = VALUES(weighted_mape),
             weighted_mae          = VALUES(weighted_mae),
             weighted_rmse         = VALUES(weighted_rmse),
             weighted_accuracy_pct = VALUES(weighted_accuracy_pct),
             rows_parsed           = VALUES(rows_parsed),
             rows_dropped          = VALUES(rows_dropped),
             created_at            = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        $userId,
        $data['original_filename'],
        $data['stored_path'],
        $data['evaluated_count'],
        $data['total_count'],
        $data['weighted_mape'],
        $data['weighted_mae'],
        $data['weighted_rmse'],
        $data['weighted_accuracy_pct'],
        $data['rows_parsed'],
        $data['rows_dropped'],
    ]);
}

function getBacktestRun(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM backtest_runs WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Deletes the saved row and returns its stored_path so the caller can unlink
// the file too — null if there was nothing to clear.
function deleteBacktestRun(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare('SELECT stored_path FROM backtest_runs WHERE user_id = ?');
    $stmt->execute([$userId]);
    $path = $stmt->fetchColumn();
    if ($path === false) return null;

    $stmt = $pdo->prepare('DELETE FROM backtest_runs WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $path;
}
