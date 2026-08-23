<?php
// api/export_helpers.php
// Shared CSV streaming for the data-export endpoints (export_data / export_version).

// Neutralizes CSV / spreadsheet formula injection. A cell whose text begins with
// = + - @ (or tab/CR) is interpreted as a formula by Excel / Google Sheets, so a
// product name like "=cmd()" could execute on open. Prefixing with an apostrophe
// forces the value to render as literal text.
function csvSafeCell($value): string
{
    $s = (string) $value;
    if ($s !== '' && preg_match('/^[=\-+@\t\r]/', $s)) {
        return "'" . $s;
    }
    return $s;
}

// Streams $rows (from getSalesForExport / getVersionSalesForExport) as a CSV
// download named $filename. The caller should exit right after.
function streamSalesCsv(array $rows, string $filename): void
{
    // This endpoint emits a raw CSV body — make sure a stray warning/notice can't
    // corrupt the download by prepending itself to the output.
    ini_set('display_errors', '0');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel reads accented product names correctly.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Date', 'Product', 'Quantity', 'Category', 'Subcategory', 'SKU', 'Cost Price', 'Selling Price']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['sale_date'],
            csvSafeCell($r['product_name']),
            $r['quantity_sold'],
            csvSafeCell($r['category']),
            csvSafeCell($r['subcategory']),
            csvSafeCell($r['sku']),
            $r['cost_price'],
            $r['selling_price'],
        ]);
    }

    fclose($out);
}

// Streams the restock overview (from getRestockOverview) as a CSV download named
// $filename — a "shopping list" of suggested orders. The caller should exit after.
function streamRestockCsv(array $rows, string $filename): void
{
    ini_set('display_errors', '0');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel

    fputcsv($out, ['Product', 'Category', 'Forecast Demand', 'Current Stock', 'Suggested Order', 'Unit Cost', 'Order Cost', 'Est. Profit']);

    foreach ($rows as $r) {
        fputcsv($out, [
            csvSafeCell($r['name']),
            csvSafeCell($r['category']),
            $r['demand']     ?? '',
            $r['stock']      ?? '',
            $r['order_qty']  ?? '',
            $r['cost_price'] ?? '',
            $r['order_cost'] !== null ? number_format($r['order_cost'], 2, '.', '') : '',
            $r['est_profit'] !== null ? number_format($r['est_profit'], 2, '.', '') : '',
        ]);
    }

    fclose($out);
}
