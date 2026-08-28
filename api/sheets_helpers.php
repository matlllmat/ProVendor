<?php
// api/sheets_helpers.php
// Shared plumbing for the linked-Google-Sheet endpoints (sheets_link.php,
// sheets_sync.php). Talks to the Flask server's /sheets/* routes — the same
// localhost:5000 bridge every other Python-backed feature uses — and turns the
// sheet's raw grid into the aggregated (product, date) rows the importer wants.

require_once __DIR__ . '/import_helpers.php';

const SHEETS_FLASK_BASE = 'http://localhost:5000';

// Reads a Google Sheet through the Flask service account.
// Always returns an array; on failure it has ok=false plus a stable `code` so
// callers can decide between "tell the user to fix their sharing settings" and
// "tell the user the ML server isn't running".
function fetchSheet(string $url, int $timeout = 45): array
{
    $ch = curl_init(SHEETS_FLASK_BASE . '/sheets/read');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['url' => $url]),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw    = curl_exec($ch);
    $errNo  = curl_errno($ch);
    curl_close($ch);

    if ($errNo !== 0 || $raw === false) {
        return [
            'ok'    => false,
            'code'  => 'server_down',
            'error' => 'ProVendor could not reach its Google Sheets service. '
                     . 'Make sure the Python server is running (python python/app.py), then try again.',
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'ok'    => false,
            'code'  => 'bad_response',
            'error' => 'The Google Sheets service returned an unreadable response. Please try again.',
        ];
    }

    return $data;
}

// Writes the sheet grid to the session's temp CSV so the rest of the import
// pipeline (preflight.php → import.php) can consume it exactly as if the owner
// had uploaded a file. Returns the path, or null if the file couldn't be written.
function writeSheetCsv(array $headers, array $rows): ?string
{
    $path   = __DIR__ . '/../uploads/import_' . session_id() . '.csv';
    $handle = fopen($path, 'w');
    if (!$handle) return null;

    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

// Converts the sheet grid into aggregated daily rows using a saved mapping.
// Mirrors preflight.php's rules exactly — same validation, same per-day
// aggregation — so a synced row can never differ from what the preview promised.
//
// Returns ['pairs' => ['<lower product>|<Y-m-d>' => [...]], 'dropped' => int,
//          'dropped_samples' => [...]]
function aggregateSheetRows(array $headers, array $rows, array $mapping, ?string $dateFormat): array
{
    $idx = array_flip($headers);
    $col = function (string $field) use ($mapping, $idx): ?int {
        $name = $mapping[$field] ?? null;
        return ($name !== null && isset($idx[$name])) ? $idx[$name] : null;
    };

    $iDate    = $col('date');
    $iProduct = $col('product');
    $iQty     = $col('quantity');

    $iSku         = $col('sku');
    $iCategory    = $col('category');
    $iSubcategory = $col('subcategory');
    $iCost        = $col('cost');
    $iPrice       = $col('price');

    $pairs          = [];
    $dropped        = 0;
    $droppedSamples = [];
    $rowNum         = 1; // row 1 is the header in the owner's sheet

    $text = function (array $r, ?int $i): ?string {
        if ($i === null) return null;
        $v = trim((string) ($r[$i] ?? ''));
        return $v === '' ? null : $v;
    };
    $number = function (array $r, ?int $i): ?float {
        if ($i === null) return null;
        $v = trim((string) ($r[$i] ?? ''));
        return is_numeric($v) ? (float) $v : null;
    };

    foreach ($rows as $r) {
        $rowNum++;

        $productName = trim((string) ($r[$iProduct] ?? ''));
        $dateRaw     = trim((string) ($r[$iDate]    ?? ''));
        $qtyRaw      = trim((string) ($r[$iQty]     ?? ''));

        $reason = null;
        if ($productName === '')               $reason = 'Missing product name';
        elseif (mb_strlen($productName) > 100) $reason = 'Product name exceeds 100 characters';
        elseif ($dateRaw === '')               $reason = 'Missing date';
        elseif ($qtyRaw === '')                $reason = 'Missing quantity';

        $date = $reason ? null : normalizeDateStrict($dateRaw, $dateFormat);
        if (!$reason && $date === null) {
            $reason = 'Unrecognized date: "' . mb_substr($dateRaw, 0, 30) . '"';
        }
        if (!$reason && (!is_numeric($qtyRaw) || (float) $qtyRaw <= 0
                         || (float) $qtyRaw != (int) $qtyRaw || (int) $qtyRaw > 999999)) {
            $reason = 'Quantity must be a whole number between 1 and 999,999';
        }

        if ($reason) {
            $dropped++;
            if (count($droppedSamples) < 10) {
                $droppedSamples[] = [
                    'row'     => $rowNum,
                    'product' => $productName !== '' ? $productName : '(empty)',
                    'date'    => $dateRaw     !== '' ? $dateRaw     : '(empty)',
                    'qty'     => $qtyRaw      !== '' ? $qtyRaw      : '(empty)',
                    'reason'  => $reason,
                ];
            }
            continue;
        }

        $qty = (int) $qtyRaw;
        $key = mb_strtolower($productName) . '|' . $date;

        if (isset($pairs[$key])) {
            // Same product sold twice on one day — the forecast model needs one
            // daily total, so the rows sum (identical to the CSV importer).
            $pairs[$key]['qty'] += $qty;
            continue;
        }

        $pairs[$key] = [
            'rowNum'      => $rowNum,
            'product'     => $productName,
            'date'        => $date,
            'qty'         => $qty,
            'sku'         => $text($r, $iSku),
            'category'    => $text($r, $iCategory),
            'subcategory' => $text($r, $iSubcategory),
            'cost'        => $number($r, $iCost),
            'price'       => $number($r, $iPrice),
        ];
    }

    return ['pairs' => $pairs, 'dropped' => $dropped, 'dropped_samples' => $droppedSamples];
}
