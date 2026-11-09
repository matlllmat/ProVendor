<?php
// api/detect.php
// Receives an uploaded CSV or Excel file, saves it to uploads/, detects column types,
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

if (in_array($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    http_response_code(413);
    echo json_encode(['error' => IMPORT_FILE_TOO_LARGE_MESSAGE]);
    exit;
}

if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error.']);
    exit;
}

$file = $_FILES['csv'];

// Validate extension
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension === 'xls') {
    echo json_encode(['error' => 'Older .xls files can\'t be read. In Excel, use Save As and choose .xlsx or .csv.']);
    exit;
}
if ($extension !== 'csv' && $extension !== 'xlsx') {
    echo json_encode(['error' => 'Only .csv and .xlsx files are accepted.']);
    exit;
}

// Validate size — 10 MB max
if ($file['size'] > MAX_IMPORT_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => IMPORT_FILE_TOO_LARGE_MESSAGE]);
    exit;
}

// Save to uploads/ keyed by session so import.php can reuse it
$tempName = 'import_' . session_id() . '.csv';
$tempPath = __DIR__ . '/../uploads/' . $tempName;

$sourcePath = $extension === 'xlsx' ? $tempPath . '.xlsx' : $tempPath;
if (!move_uploaded_file($file['tmp_name'], $sourcePath)) {
    echo json_encode(['error' => 'Failed to save uploaded file.']);
    exit;
}

// Row numbers in the preview should match the owner's spreadsheet, and a
// workbook with several sheets needs to say which one was read.
$rowOffset    = 0;
$workbookNote = null;
if ($extension === 'xlsx') {
    $workbook = convertWorkbookToCsv($sourcePath, $tempPath);
    @unlink($sourcePath);
    if (isset($workbook['error'])) {
        @unlink($tempPath);
        echo json_encode(['error' => $workbook['error']]);
        exit;
    }
    $rowOffset = (int) $workbook['header_row'] - 1;
    if (count($workbook['sheets']) > 1) {
        $workbookNote = 'This workbook has ' . count($workbook['sheets']) . ' sheets with data. Only "' . $workbook['sheet'] . '" was read.';
    }
}
$_SESSION['temp_csv_row_offset']    = $rowOffset;
$_SESSION['temp_csv_workbook_note'] = $workbookNote;

// Excel on Windows writes CSV as ANSI (Windows-1252) by default, not UTF-8.
// Those bytes are invalid UTF-8, and json_encode() returns false on invalid
// UTF-8 — so every response below would be an empty body and the wizard would
// die with no error message at all. Normalize the file once, here: preflight
// and import re-read this same temp file, so they're fixed for free.
$raw               = file_get_contents($tempPath);
$encodingConverted = false;

if ($raw !== false && !mb_check_encoding($raw, 'UTF-8')) {
    $converted = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    if ($converted !== '' && file_put_contents($tempPath, $converted) !== false) {
        $encodingConverted = true;
    }
}

$_SESSION['temp_csv']               = $tempPath;
$_SESSION['temp_csv_name']          = $file['name'];
$_SESSION['temp_csv_encoding_conv'] = $encodingConverted;

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

// Keep up to 20 sample rows for column detection; dates are checked on every row.
$sampleRows = [];
$allRows    = [];
$rowCount   = 0;

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($headers)) continue; // skip malformed rows

    $allRows[] = $row;
    if ($rowCount < 20) {
        $sampleRows[] = array_combine($headers, $row);
    }
    $rowCount++;

    if ($rowCount > MAX_IMPORT_ROWS) {
        fclose($handle);
        @unlink($tempPath);
        echo json_encode(['error' => TOO_MANY_ROWS_MESSAGE]);
        exit;
    }
}
fclose($handle);

// ── Detect column types and suggest field mapping ─────────────────────────────
$suggestions = detectColumnMapping($headers, $sampleRows);

// ── Sniff the date format of every column ─────────────────────────────────────
// The owner can assign any column to Date, and preflight checks the final choice.
$dateFormats = sniffColumnDateFormats($headers, $allRows);
$dateFormat  = $dateFormats[$suggestions['date']] ?? ['format' => null, 'ambiguous' => false];
$_SESSION['temp_csv_date_format'] = $dateFormat;

echo json_encode([
    'headers'            => $headers,
    'sample'             => array_slice($sampleRows, 0, 5),
    'suggestions'        => $suggestions,
    'row_count'          => $rowCount,
    'date_format'        => $dateFormat,
    'date_formats'       => $dateFormats,
    'encoding_converted' => $encodingConverted,
]);
