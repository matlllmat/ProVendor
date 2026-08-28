<?php
// queries/sheets.query.php
// SQL for the linked Google Sheet (one row per user in `sheet_links`).
// The sales rows a sync produces are written with the ordinary import helpers in
// import.query.php — this file only owns the link record itself.

// Returns the user's linked sheet, or null if they haven't linked one.
// column_mapping is decoded so callers get the mapping as an array.
function getSheetLink(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM sheet_links WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $row['column_mapping'] = json_decode($row['column_mapping'], true) ?: [];
    $row['auto_sync']      = (bool) $row['auto_sync'];
    return $row;
}

// Creates the link, or replaces it if the user re-links a different sheet.
// The UNIQUE key on user_id makes this a one-statement upsert.
function saveSheetLink(PDO $pdo, int $userId, array $d): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO sheet_links
            (user_id, spreadsheet_id, sheet_url, sheet_title, worksheet_title,
             column_mapping, date_format, last_synced_at, last_sync_status,
             last_sync_added, last_sync_updated)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), \'ok\', ?, ?)
         ON DUPLICATE KEY UPDATE
            spreadsheet_id    = VALUES(spreadsheet_id),
            sheet_url         = VALUES(sheet_url),
            sheet_title       = VALUES(sheet_title),
            worksheet_title   = VALUES(worksheet_title),
            column_mapping    = VALUES(column_mapping),
            date_format       = VALUES(date_format),
            last_synced_at    = VALUES(last_synced_at),
            last_sync_status  = VALUES(last_sync_status),
            last_sync_error   = NULL,
            last_sync_added   = VALUES(last_sync_added),
            last_sync_updated = VALUES(last_sync_updated)'
    );
    $stmt->execute([
        $userId,
        $d['spreadsheet_id'],
        $d['sheet_url'],
        $d['sheet_title']     ?? null,
        $d['worksheet_title'] ?? null,
        json_encode($d['column_mapping'] ?? []),
        $d['date_format']     ?? null,
        (int) ($d['added']   ?? 0),
        (int) ($d['updated'] ?? 0),
    ]);
}

// Stamps the outcome of a sync attempt. A failed sync keeps the link (and its
// mapping) intact — only the status changes, so the owner sees WHY the refresh
// stopped working instead of the link silently disappearing.
function recordSyncResult(PDO $pdo, int $userId, string $status, ?string $error, int $added = 0, int $updated = 0): void
{
    $stmt = $pdo->prepare(
        'UPDATE sheet_links
            SET last_synced_at = NOW(), last_sync_status = ?, last_sync_error = ?,
                last_sync_added = ?, last_sync_updated = ?
          WHERE user_id = ?'
    );
    $stmt->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $added, $updated, $userId]);
}

// Turns the 5-minute browser refresh on or off for this user.
function setSheetAutoSync(PDO $pdo, int $userId, bool $on): void
{
    $pdo->prepare('UPDATE sheet_links SET auto_sync = ? WHERE user_id = ?')
        ->execute([$on ? 1 : 0, $userId]);
}

// Disconnects the sheet. Sales rows already imported from it stay — this only
// stops future syncs and re-enables CSV import.
function deleteSheetLink(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM sheet_links WHERE user_id = ?')->execute([$userId]);
}
