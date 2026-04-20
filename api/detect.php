<?php
// api/detect.php
// Receives an uploaded CSV, saves it to uploads/, detects column types,
// and returns suggested field mappings as JSON.

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']);
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

echo json_encode([
    'headers'     => $headers,
    'sample'      => array_slice($sampleRows, 0, 5),
    'suggestions' => $suggestions,
    'row_count'   => $rowCount,
]);

// ── Helper: suggest which CSV column maps to which required field ─────────────
function detectColumnMapping(array $headers, array $rows): array
{
    $suggestions = [
        'date'        => null,
        'product'     => null,
        'quantity'    => null,
        'sku'         => null,
        'category'    => null,
        'subcategory' => null,
        'cost'        => null,
        'price'       => null,
    ];

    foreach ($headers as $col) {
        $lower  = strtolower(trim($col));
        $sample = array_column($rows, $col);

        // Date
        if ($suggestions['date'] === null
            && (str_contains($lower, 'date') || str_contains($lower, 'day') || str_contains($lower, 'time'))
            && isDateColumn($sample)
        ) {
            $suggestions['date'] = $col;
            continue;
        }

        // Product
        if ($suggestions['product'] === null
            && (str_contains($lower, 'product') || str_contains($lower, 'item')
                || str_contains($lower, 'name') || str_contains($lower, 'desc'))
        ) {
            $suggestions['product'] = $col;
            continue;
        }

        // Quantity
        if ($suggestions['quantity'] === null
            && (str_contains($lower, 'qty') || str_contains($lower, 'quantity')
                || str_contains($lower, 'sold') || str_contains($lower, 'units')
                || str_contains($lower, 'amount'))
            && isNumericColumn($sample)
        ) {
            $suggestions['quantity'] = $col;
            continue;
        }

        // SKU / Product code
        if ($suggestions['sku'] === null
            && (str_contains($lower, 'sku') || str_contains($lower, 'code')
                || str_contains($lower, 'barcode') || str_contains($lower, 'ref'))
        ) {
            $suggestions['sku'] = $col;
            continue;
        }

        // Category
        if ($suggestions['category'] === null
            && (str_contains($lower, 'category') || str_contains($lower, 'group')
                || str_contains($lower, 'dept') || str_contains($lower, 'type'))
        ) {
            $suggestions['category'] = $col;
            continue;
        }

        // Subcategory
        if ($suggestions['subcategory'] === null
            && (str_contains($lower, 'sub') || str_contains($lower, 'variant')
                || str_contains($lower, 'packaging') || str_contains($lower, 'pack')
                || str_contains($lower, 'size') || str_contains($lower, 'segment'))
        ) {
            $suggestions['subcategory'] = $col;
            continue;
        }

        // Cost
        if ($suggestions['cost'] === null
            && (str_contains($lower, 'cost') || str_contains($lower, 'purchase')
                || str_contains($lower, 'buy'))
            && isNumericColumn($sample)
        ) {
            $suggestions['cost'] = $col;
            continue;
        }

        // Selling price
        if ($suggestions['price'] === null
            && (str_contains($lower, 'price') || str_contains($lower, 'selling')
                || str_contains($lower, 'retail'))
            && isNumericColumn($sample)
        ) {
            $suggestions['price'] = $col;
        }
    }

    // Fallback: if date not found by name, find first column where values parse as dates
    if ($suggestions['date'] === null) {
        foreach ($headers as $col) {
            $sample = array_column($rows, $col);
            if (isDateColumn($sample)) {
                $suggestions['date'] = $col;
                break;
            }
        }
    }

    return $suggestions;
}

function isDateColumn(array $values): bool
{
    $valid = 0;
    foreach (array_slice($values, 0, 10) as $v) {
        $v = trim((string) $v);
        if ($v === '') continue;
        if (strtotime($v) !== false) $valid++;
    }
    return $valid >= 3;
}

function isNumericColumn(array $values): bool
{
    $valid = 0;
    foreach (array_slice($values, 0, 10) as $v) {
        $v = trim((string) $v);
        if ($v === '') continue;
        if (is_numeric($v)) $valid++;
    }
    return $valid >= 3;
}
