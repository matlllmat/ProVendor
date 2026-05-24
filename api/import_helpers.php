<?php
// api/import_helpers.php
// Shared helpers used by detect.php, preflight.php, and import.php.
// Kept in one place so preflight and import always agree on what a "valid date" is —
// otherwise preflight could green-light rows that import then silently drops.

// Detects whether a date column is DD/MM/YYYY or MM/DD/YYYY by scanning sample values
// for any value where the first or second part is > 12 (which forces the interpretation).
// Returns:
//   ['format' => 'Y-m-d' | 'd/m/Y' | 'm/d/Y' | 'd-m-Y' | 'm-d-Y' | 'Y/m/d' | null,
//    'ambiguous' => bool]
// 'format' is null when nothing matched — caller should fall back to strtotime.
// 'ambiguous' = true means we picked a default (PH convention: DD/MM) because the
// sample never had a day > 12 to disambiguate. The UI should warn the user.
function sniffDateFormat(array $samples): array
{
    $clean = [];
    foreach ($samples as $s) {
        $s = trim((string) $s);
        if ($s !== '') $clean[] = $s;
    }
    if (empty($clean)) return ['format' => null, 'ambiguous' => false];

    // ISO-like: 4-digit year first, then separator + 2-digit month + day
    if (preg_match('#^(\d{4})([-/])(\d{1,2})\2(\d{1,2})$#', $clean[0], $m)) {
        $sep = $m[2];
        return ['format' => "Y{$sep}m{$sep}d", 'ambiguous' => false];
    }

    // Two-component-first format: DD/MM/YYYY or MM/DD/YYYY (and dash variants)
    if (preg_match('#^\d{1,2}([-/])\d{1,2}\1\d{4}$#', $clean[0], $m)) {
        $sep        = $m[1];
        $sawFirstGt = false; // first slot > 12 → first slot is day → DD/MM
        $sawSecondGt = false; // second slot > 12 → second slot is day → MM/DD

        foreach ($clean as $v) {
            if (!preg_match('#^(\d{1,2})[-/](\d{1,2})[-/]\d{4}$#', $v, $mm)) continue;
            $a = (int) $mm[1];
            $b = (int) $mm[2];
            if ($a > 12) $sawFirstGt  = true;
            elseif ($b > 12) $sawSecondGt = true;
        }

        if ($sawFirstGt)  return ['format' => "d{$sep}m{$sep}Y", 'ambiguous' => false];
        if ($sawSecondGt) return ['format' => "m{$sep}d{$sep}Y", 'ambiguous' => false];

        // Truly ambiguous — default to DD/MM (Philippine convention) and flag it.
        return ['format' => "d{$sep}m{$sep}Y", 'ambiguous' => true];
    }

    return ['format' => null, 'ambiguous' => false];
}

// Parses a date string using the explicit format if given, falling back to strtotime
// only when the format is null (timestamps, "January 5, 2024", etc.). Strict mode is
// important: if a row claims to be d/m/Y but actually isn't, we want it rejected, not
// silently re-parsed by strtotime under a different interpretation.
function normalizeDateStrict(string $raw, ?string $format): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;

    if ($format) {
        $dt = DateTime::createFromFormat('!' . $format, $raw);
        if ($dt && $dt->format($format) === $raw) {
            return $dt->format('Y-m-d');
        }
        return null;
    }

    $ts = strtotime($raw);
    return $ts === false ? null : date('Y-m-d', $ts);
}
