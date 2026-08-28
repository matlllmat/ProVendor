<?php
// api/sheets_link.php
// Step 4 of the Google Sheets flow: validate the pasted link.
//
// Confirms the sheet exists AND that it's shared with ProVendor's service
// account, then writes its contents into the session temp CSV that detect.php
// would normally produce. From that point the owner walks the SAME column
// mapping + preview screens as a CSV import — preflight.php and import.php need
// no idea the rows came from Google.
//
// Input  (POST): url
// Output (JSON): { headers, sample, suggestions, row_count, date_format, sheet }
//            or: { error, code }

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/sheets_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.', 'code' => 'auth']);
    exit;
}

$url = trim((string) ($_POST['url'] ?? ''));
if ($url === '') {
    echo json_encode(['error' => 'Please paste the link to your Google Sheet.', 'code' => 'bad_url']);
    exit;
}

// ── Ask Python to open the sheet with the service account ─────────────────────
$sheet = fetchSheet($url);

if (empty($sheet['ok'])) {
    echo json_encode([
        'error' => $sheet['error'] ?? 'The sheet could not be opened.',
        'code'  => $sheet['code']  ?? 'api_error',
    ]);
    exit;
}

$headers = $sheet['headers'];
$rows    = $sheet['rows'];

// ── Stage it exactly like an uploaded CSV ─────────────────────────────────────
$tempPath = writeSheetCsv($headers, $rows);
if ($tempPath === null) {
    echo json_encode([
        'error' => 'Could not stage the sheet contents on the server. Check that uploads/ is writable.',
        'code'  => 'staging_failed',
    ]);
    exit;
}

$_SESSION['temp_csv']      = $tempPath;
$_SESSION['temp_csv_name'] = $sheet['title'] . ' — ' . $sheet['worksheet'];

// Remembered until the owner commits the import; import.php reads it to persist
// the link (with the mapping they confirmed) only once rows are actually stored.
$_SESSION['pending_sheet'] = [
    'spreadsheet_id'  => $sheet['spreadsheet_id'],
    'sheet_url'       => $url,
    'sheet_title'     => $sheet['title'],
    'worksheet_title' => $sheet['worksheet'],
];

// ── Suggest a column mapping from a sample, same as detect.php ────────────────
$sampleRows = [];
foreach (array_slice($rows, 0, 20) as $row) {
    $sampleRows[] = array_combine($headers, $row);
}

$suggestions = detectColumnMapping($headers, $sampleRows);

$dateFormat = ['format' => null, 'ambiguous' => false];
if ($suggestions['date'] !== null) {
    $dateFormat = sniffDateFormat(array_column($sampleRows, $suggestions['date']));
}
$_SESSION['temp_csv_date_format'] = $dateFormat;

echo json_encode([
    'headers'     => $headers,
    'sample'      => array_slice($sampleRows, 0, 5),
    'suggestions' => $suggestions,
    'row_count'   => count($rows),
    'date_format' => $dateFormat,
    'sheet'       => [
        'title'     => $sheet['title'],
        'worksheet' => $sheet['worksheet'],
    ],
]);
