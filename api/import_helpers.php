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

// Parses a date string using the column's detected format, with a per-row recovery
// pass for stray values written in an *unambiguous* format (ISO, named months).
// We deliberately do NOT recover ambiguous numeric formats like "03-15-2024" inside
// a DD/MM column — there's no way to know if that's US-style MM-DD or a typo, and
// silently guessing is exactly the corruption strict parsing is meant to prevent.
// $usedFallback is set to true when the selected/detected format failed and the
// recovery path was used instead. Callers that want to report this to the user
// (e.g. preflight) pass a variable by reference; import.php ignores it.
function normalizeDateStrict(string $raw, ?string $format, bool &$usedFallback = false): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;

    $usedFallback = false;

    // 1. Try the column's explicitly chosen/detected format first.
    if ($format) {
        $dt = DateTime::createFromFormat('!' . $format, $raw);
        if ($dt) {
            $errors = DateTime::getLastErrors();
            // Accept the date as long as PHP didn't flag trailing characters or syntax warnings
            if (!$errors || ($errors['warning_count'] == 0 && $errors['error_count'] == 0)) {
                // Prevent 2-digit years getting parsed as literal year 0024 by 4-digit 'Y' formats
                if ((int)$dt->format('Y') > 1000) {
                    return $dt->format('Y-m-d');
                }
            }
        }
    }

    // 2. Recovery — only formats that can't be misinterpreted.
    $usedFallback = true;
    return recoverUnambiguousDate($raw);
}

// Tries to parse $raw assuming it's in a format whose meaning is unambiguous regardless
// of locale. Returns Y-m-d on success, null otherwise. Used as the fallback for rows
// whose date doesn't match the column's sniffed format.
function recoverUnambiguousDate(string $raw): ?string
{
    // ISO date — 4-digit year first, so DD/MM vs MM/DD cannot apply.
    foreach (['Y-m-d', 'Y/m/d'] as $iso) {
        $dt = DateTime::createFromFormat('!' . $iso, $raw);
        if ($dt && $dt->format($iso) === $raw) {
            return $dt->format('Y-m-d');
        }
    }

    // ISO + time component, e.g. "2024-03-15 10:30:00" or "2024-03-15T10:30:00".
    // We only need the date portion; whatever comes after the day is discarded.
    if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})\b#', $raw, $m)) {
        $iso = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        $dt  = DateTime::createFromFormat('!Y-m-d', $iso);
        if ($dt && $dt->format('Y-m-d') === $iso) return $iso;
    }

    // Numeric DD?MM?YYYY or MM?DD?YYYY where one slot is > 12 — only one of the
    // two interpretations is valid (months don't go above 12), so the row is
    // self-disambiguating regardless of the column's sniffed format. The
    // "both <= 12" case is genuinely ambiguous and falls through.
    if (preg_match('#^(\d{1,2})([-/])(\d{1,2})\2(\d{4})$#', $raw, $m)) {
        $a = (int) $m[1]; $b = (int) $m[3]; $y = (int) $m[4];
        if ($a > 12 && checkdate($b, $a, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $b, $a); // DD?MM?YYYY
        }
        if ($b > 12 && checkdate($a, $b, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $a, $b); // MM?DD?YYYY
        }
    }

    // Anything containing letters (e.g. "March 15, 2024", "15 Mar 2024", "Jan 5 2024")
    // is unambiguous — the month name pins the meaning. strtotime handles these well.
    if (preg_match('/[A-Za-z]/', $raw)) {
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);
    }

    return null;
}
