<?php
// api/detect.php
// Receives an uploaded CSV, saves it to uploads/, detects column types,
// and returns suggested field mappings as JSON.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/import_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

// A linked Google Sheet owns this store's sales data — it rewrites the same
// (product, date) rows every five minutes, so a CSV imported alongside it would
// be quietly reverted on the next refresh. The UI hides the upload button while
// a sheet is linked; this is the matching server-side rule.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/sheets.query.php';

if (getSheetLink($pdo, (int) $_SESSION['user_id'])) {
    echo json_encode([
        'error' => 'CSV import is turned off while a Google Sheet is linked to your store. '
                 . 'Disconnect the sheet on the Sales Data tab first.',
        'code'  => 'sheet_linked',
    ]);
    exit;
}

if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error.']);
    exit;
}

$file = $_FILES['csv'];

// Validate extension
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
    echo json_encode(['error' => 'Only .csv files are accepted.']);
    exit;
}

// Validate size — 10 MB max
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['error' => 'File exceeds the 10 MB limit.']);
    exit;
}

// Save to uploads/ keyed by session so import.php can reuse it
$tempName = 'import_' . session_id() . '.csv';
$tempPath = __DIR__ . '/../uploads/' . $tempName;

if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
    echo json_encode(['error' => 'Failed to save uploaded file.']);
    exit;
}

$_SESSION['temp_csv']      = $tempPath;
$_SESSION['temp_csv_name'] = $file['name'];

// The owner validated a sheet and then chose to upload a CSV instead. Drop the
// half-finished link, or import.php would attach it to these CSV rows and start
// syncing a sheet they walked away from.
unset($_SESSION['pending_sheet']);

// ── Read headers + sample rows ────────────────────────────────────────────────
$handle = fopen($tempPath, 'r');
if (!$handle) {
    echo json_encode(['error' => 'Could not read uploaded file.']);
    exit;
}

$headers = fgetcsv($handle);
if (!$headers) {
    fclose($handle);
    echo json_encode(['error' => 'CSV appears to be empty or has no headers.']);
    exit;
}

$headers = array_map('trim', $headers);

// Strip UTF-8 BOM from the first header — Excel and some export tools prepend
// \xEF\xBB\xBF which makes the first column unrecognizable to auto-detection.
if (!empty($headers[0]) && str_starts_with($headers[0], "\xEF\xBB\xBF")) {
    $headers[0] = substr($headers[0], 3);
}

// Read up to 20 sample rows for type detection
$sampleRows = [];
$allDates   = [];
$rowCount   = 0;

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($headers)) continue; // skip malformed rows

    $mapped = array_combine($headers, $row);
    if ($rowCount < 20) {
        $sampleRows[] = $mapped;
    }
    $rowCount++;
}
fclose($handle);

// ── Detect column types and suggest field mapping ─────────────────────────────
$suggestions = detectColumnMapping($headers, $sampleRows);

// ── Sniff date format for the suggested date column ───────────────────────────
// Cached on session so preflight + import don't need to re-sniff. If the user
// later picks a different date column in the mapping UI, preflight will re-sniff.
$dateFormat = ['format' => null, 'ambiguous' => false];
if ($suggestions['date'] !== null) {
    $dateSamples = array_column($sampleRows, $suggestions['date']);
    $dateFormat  = sniffDateFormat($dateSamples);
}
$_SESSION['temp_csv_date_format'] = $dateFormat;

echo json_encode([
    'headers'     => $headers,
    'sample'      => array_slice($sampleRows, 0, 5),
    'suggestions' => $suggestions,
    'row_count'   => $rowCount,
    'date_format' => $dateFormat,
]);
