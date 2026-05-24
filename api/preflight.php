<?php
// api/preflight.php
// Pre-import validation: scans the temp CSV with the confirmed column mapping and returns
// (a) invalid rows (bad dates, zero qty, missing product) with up to 10 examples, and
// (b) overlap count — how many existing records fall in the CSV's date range.
// Does NOT write anything to the database.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/import_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

if (empty($_SESSION['temp_csv']) || !file_exists($_SESSION['temp_csv'])) {
    echo json_encode(['error' => 'No uploaded file found. Please re-upload your CSV.']);
    exit;
}

$mapping = json_decode($_POST['mapping'] ?? '{}', true);
if (empty($mapping['date']) || empty($mapping['product']) || empty($mapping['quantity'])) {
    echo json_encode(['error' => 'Required columns not mapped.']);
    exit;
}

$colDate    = $mapping['date'];
$colProduct = $mapping['product'];
$colQty     = $mapping['quantity'];

// ── Scan CSV ──────────────────────────────────────────────────────────────────
$handle = fopen($_SESSION['temp_csv'], 'r');
if (!$handle) {
    echo json_encode(['error' => 'Could not read the uploaded file.']);
    exit;
}

$headers = array_map('trim', fgetcsv($handle));

// Verify the mapped columns exist
foreach ([$colDate, $colProduct, $colQty] as $col) {
    if (!in_array($col, $headers)) {
        fclose($handle);
        echo json_encode(['error' => "Mapped column \"$col\" not found in CSV."]);
        exit;
    }
}

// First pass: collect ~50 sample date values so we can sniff the format BEFORE
// rejecting anything. Without this, if detect.php and preflight see different
// date columns, we'd reject every row as "Unrecognized date format" because
// strtotime would be guessing wrong.
$dateColIdx = array_search($colDate, $headers, true);
$dateSamples = [];
$bufferedRows = [];
while (($row = fgetcsv($handle)) !== false) {
    $bufferedRows[] = $row;
    if ($dateColIdx !== false && count($row) === count($headers)) {
        $v = trim((string) ($row[$dateColIdx] ?? ''));
        if ($v !== '' && count($dateSamples) < 50) $dateSamples[] = $v;
    }
    if (count($bufferedRows) >= 50 && count($dateSamples) >= 50) {
        // Continue reading the rest below without re-sampling
        break;
    }
}

$dateFormat = sniffDateFormat($dateSamples);
$_SESSION['temp_csv_date_format'] = $dateFormat; // cache for import.php

$valid        = 0;
$invalid      = 0;
$errorSamples = [];
$minDate      = null;
$maxDate      = null;
$rowNum       = 1; // 1 = header row

// Drain the rest of the file into the buffer so we process every row once below
while (($row = fgetcsv($handle)) !== false) {
    $bufferedRows[] = $row;
}
fclose($handle);

foreach ($bufferedRows as $row) {
    $rowNum++;
    if (count($row) !== count($headers)) {
        $invalid++;
        if (count($errorSamples) < 10) {
            $errorSamples[] = [
                'row'    => $rowNum,
                'product'=> '',
                'date'   => '',
                'qty'    => '',
                'reason' => 'Row has wrong number of columns',
            ];
        }
        continue;
    }

    $r           = array_combine($headers, $row);
    $productName = trim($r[$colProduct] ?? '');
    $dateRaw     = trim($r[$colDate]    ?? '');
    $qtyRaw      = trim($r[$colQty]     ?? '');

    $reason = null;
    if ($productName === '') {
        $reason = 'Missing product name';
    } elseif ($dateRaw === '') {
        $reason = 'Missing date';
    } elseif (normalizeDateStrict($dateRaw, $dateFormat['format']) === null) {
        $reason = 'Unrecognized date format: "' . mb_substr($dateRaw, 0, 30) . '"';
    } elseif (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw != (int) $qtyRaw) {
        $reason = 'Quantity must be a whole number greater than 0 (got "' . mb_substr($qtyRaw, 0, 20) . '")';
    }

    if ($reason) {
        $invalid++;
        if (count($errorSamples) < 10) {
            $errorSamples[] = [
                'row'    => $rowNum,
                'product'=> $productName ?: '(empty)',
                'date'   => $dateRaw    ?: '(empty)',
                'qty'    => $qtyRaw     ?: '(empty)',
                'reason' => $reason,
            ];
        }
        continue;
    }

    $date = normalizeDateStrict($dateRaw, $dateFormat['format']);
    $valid++;
    if ($minDate === null || $date < $minDate) $minDate = $date;
    if ($maxDate === null || $date > $maxDate) $maxDate = $date;
}

// ── Overlap check ─────────────────────────────────────────────────────────────
$overlapCount = 0;
if ($minDate && $maxDate) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../queries/import.query.php';
    $overlapCount = countExistingSalesInRange($pdo, $_SESSION['user_id'], $minDate, $maxDate);
}

echo json_encode([
    'valid'         => $valid,
    'invalid'       => $invalid,
    'error_samples' => $errorSamples,
    'date_format'   => $dateFormat,
    'overlap'       => [
        'count'     => $overlapCount,
        'date_from' => $minDate,
        'date_to'   => $maxDate,
    ],
]);
