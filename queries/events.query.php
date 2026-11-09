<?php
// queries/events.query.php
// All SQL and expansion helpers for the seasonal events system.

// ── SQL queries ───────────────────────────────────────────────────────────────

// Returns the earliest and latest sale_date for this user's products.
// Used to determine the real analysis window instead of a hardcoded lookback.
function getUserSaleDateRange(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT MIN(s.sale_date) AS earliest, MAX(s.sale_date) AS latest
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// Returns all raw event records visible to this user: global (user_id IS NULL) + their own,
// excluding any events the user has hidden.
function getRawEventsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM seasonal_events
         WHERE (user_id IS NULL OR user_id = ?)
           AND id NOT IN (SELECT event_id FROM user_hidden_events WHERE user_id = ?)
         ORDER BY name'
    );
    $stmt->execute([$userId, $userId]);
    return attachOccurrences($pdo, $stmt->fetchAll());
}

// Returns all raw event rows visible to this user (global + own), for the events list page.
// Excludes events the user has hidden.
function getAllEventsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM seasonal_events
         WHERE (user_id IS NULL OR user_id = ?)
           AND id NOT IN (SELECT event_id FROM user_hidden_events WHERE user_id = ?)
         ORDER BY is_seeded DESC, name'
    );
    $stmt->execute([$userId, $userId]);
    return attachOccurrences($pdo, $stmt->fetchAll());
}

// Returns paginated raw event rows for the events list page, excluding hidden events.
function getEventsPaginated(PDO $pdo, int $userId, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    $stmt   = $pdo->prepare(
        'SELECT * FROM seasonal_events
         WHERE (user_id IS NULL OR user_id = ?)
           AND id NOT IN (SELECT event_id FROM user_hidden_events WHERE user_id = ?)
         ORDER BY is_seeded DESC, name
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$userId, $userId, $perPage, $offset]);
    return attachOccurrences($pdo, $stmt->fetchAll());
}

function countEvents(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM seasonal_events
         WHERE (user_id IS NULL OR user_id = ?)
           AND id NOT IN (SELECT event_id FROM user_hidden_events WHERE user_id = ?)'
    );
    $stmt->execute([$userId, $userId]);
    return (int) $stmt->fetchColumn();
}

// Returns a single event — must be global or owned by this user (hidden events still accessible by direct URL).
function getEventById(PDO $pdo, int $eventId, int $userId): array|false
{
    $stmt = $pdo->prepare(
        'SELECT * FROM seasonal_events
         WHERE id = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1'
    );
    $stmt->execute([$eventId, $userId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    return attachOccurrences($pdo, [$row])[0];
}

// Hides a seeded event for this user only (does not delete the global row).
function hideEvent(PDO $pdo, int $eventId, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO user_hidden_events (user_id, event_id) VALUES (?, ?)'
    );
    $stmt->execute([$userId, $eventId]);
}

// Returns the seeded events the user has hidden, along with their details.
function getHiddenEventsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT se.*
         FROM seasonal_events se
         JOIN user_hidden_events uhe ON uhe.event_id = se.id AND uhe.user_id = ?
         ORDER BY se.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Removes a hide record so the event becomes visible again.
function unhideEvent(PDO $pdo, int $eventId, int $userId): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM user_hidden_events WHERE user_id = ? AND event_id = ?'
    );
    $stmt->execute([$userId, $eventId]);
}

// ── Re-forecast notice after event changes ────────────────────────────────────

// The forecast learns an event's effect from past sales on its dates, so only
// an event with a date inside this user's sales history can change the forecast.
function eventTouchesSalesHistory(PDO $pdo, int $userId, array $event): bool
{
    $range = getUserSaleDateRange($pdo, $userId);
    if (empty($range['earliest'])) return false;
    return expandEvents([$event], $range['earliest'], $range['latest']) !== []
        && eventIsUsedByForecast($pdo, $userId, $event);
}

// A specific-dates event is only used once at least this many of its dates fall in
// the sales history; one date would just make the model memorise that day. Other
// event types are always used. Keep in step with MIN_CUSTOM_DATES in python/app.py.
const MIN_CUSTOM_DATES = 2;

// Occurrences needed for [Strong, Moderate]; anything fewer is Weak.
const CONFIDENCE_RULES = [
    'yearly'  => [4, 2],
    'monthly' => [12, 6],
    'custom'  => [6, 3],
];

// The confidence level for an event type seen $count times.
function confidenceLevel(string $recurrence, int $count): array
{
    [$strong, $moderate] = CONFIDENCE_RULES[$recurrence] ?? CONFIDENCE_RULES['yearly'];
    [$label, $css] = $count >= $strong ? ['Strong', 'strong']
        : ($count >= $moderate ? ['Moderate', 'moderate'] : ['Weak', 'weak']);
    return ['label' => $label, 'css' => $css, 'strong' => $strong, 'moderate' => $moderate];
}

// How many of an event's dates the forecast can really learn from.
function eventDatesInHistory(PDO $pdo, int $userId, array $event): int
{
    $range = getUserSaleDateRange($pdo, $userId);
    if (empty($range['earliest'])) return 0;
    $instances = expandEvents([$event], $range['earliest'], $range['latest']);
    return countInstancesWithSales($instances, getSaleDates($pdo, $userId));
}

// This owner's sale days, as a ['Y-m-d' => index] set.
function getSaleDates(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.sale_date
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?"
    );
    $stmt->execute([$userId]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// How many of these expanded instances have at least one day in $saleDates.
function countInstancesWithSales(array $instances, array $saleDates): int
{
    $count = 0;
    foreach ($instances as $instance) {
        $day = new DateTime($instance['instance_start']);
        $end = new DateTime($instance['instance_end'] ?? $instance['instance_start']);
        for (; $day <= $end; $day->modify('+1 day')) {
            if (isset($saleDates[$day->format('Y-m-d')])) {
                $count++;
                break;
            }
        }
    }
    return $count;
}

function eventIsUsedByForecast(PDO $pdo, int $userId, array $event): bool
{
    if (($event['recurrence'] ?? '') !== 'custom') return true;
    return eventDatesInHistory($pdo, $userId, $event) >= MIN_CUSTOM_DATES;
}

function markEventsChanged(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET events_changed_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $userId]);
}

// Records when a product's forecast was really recalculated. forecasts.generated_at
// can't be used because price and stock edits rewrite it. $startedAt is taken before
// Prophet reads the events, so a change made during the run still counts as newer.
function markProductForecastRun(PDO $pdo, int $productId, string $startedAt): void
{
    $pdo->prepare('UPDATE products SET prophet_run_at = ? WHERE id = ?')
        ->execute([$startedAt, $productId]);
}

// How many forecast products were last recalculated before the latest event change.
function countProductsBehindEventChange(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM products p
         JOIN users u ON u.id = p.user_id
         WHERE u.id = ?
           AND u.events_changed_at IS NOT NULL
           AND p.is_active = 1
           AND (p.prophet_run_at IS NULL OR p.prophet_run_at <= u.events_changed_at)
           AND EXISTS (SELECT 1 FROM forecasts f WHERE f.product_id = p.id)'
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// ── Custom-date occurrences ───────────────────────────────────────────────────

// Loads the concrete dates for every 'custom' event in $events in one query and
// attaches them as an 'occurrences' key. Non-custom events get an empty array,
// so callers can read $event['occurrences'] without checking recurrence first.
function attachOccurrences(PDO $pdo, array $events): array
{
    $customIds = [];
    foreach ($events as $ev) {
        if (($ev['recurrence'] ?? '') === 'custom') $customIds[] = (int) $ev['id'];
    }

    $byEvent = [];
    if ($customIds) {
        $in   = implode(',', array_fill(0, count($customIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT event_id, start_date, end_date FROM event_occurrences
             WHERE event_id IN ($in) ORDER BY start_date"
        );
        $stmt->execute($customIds);
        foreach ($stmt->fetchAll() as $row) {
            $byEvent[(int) $row['event_id']][] = [
                'start_date' => $row['start_date'],
                'end_date'   => $row['end_date'],
            ];
        }
    }

    foreach ($events as &$ev) {
        $ev['occurrences'] = $byEvent[(int) $ev['id']] ?? [];
    }
    unset($ev);

    return $events;
}

// Replaces an event's occurrence list wholesale. $dates is a list of
// ['start_date' => 'Y-m-d', 'end_date' => 'Y-m-d'|null].
// Delete-then-insert keeps the write simple; these lists are short and
// edited as a unit, so there is no partial-update case to preserve.
function replaceEventOccurrences(PDO $pdo, int $eventId, array $dates): void
{
    $pdo->prepare('DELETE FROM event_occurrences WHERE event_id = ?')->execute([$eventId]);
    if (!$dates) return;

    $stmt = $pdo->prepare(
        'INSERT INTO event_occurrences (event_id, start_date, end_date) VALUES (?, ?, ?)'
    );
    foreach ($dates as $d) {
        $stmt->execute([$eventId, $d['start_date'], $d['end_date'] ?: null]);
    }
}

function createEvent(
    PDO $pdo, int $userId,
    string $name,
    string $eventStart, ?string $eventEnd,
    string $recurrence, int $isLastDay,
    string $color,
    ?string $impactNote
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO seasonal_events
             (user_id, name, event_start, event_end, recurrence, is_last_day, is_seeded, color, impact_note)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
    );
    $stmt->execute([$userId, $name, $eventStart, $eventEnd, $recurrence, $isLastDay, $color, $impactNote]);
    return (int) $pdo->lastInsertId();
}

function updateEvent(
    PDO $pdo, int $eventId, int $userId,
    string $name,
    string $eventStart, ?string $eventEnd,
    string $recurrence, int $isLastDay,
    string $color,
    ?string $impactNote
): bool {
    $stmt = $pdo->prepare(
        'UPDATE seasonal_events
         SET name=?, event_start=?, event_end=?, recurrence=?, is_last_day=?, color=?, impact_note=?
         WHERE id=? AND user_id=? AND is_seeded=0'
    );
    $stmt->execute([$name, $eventStart, $eventEnd, $recurrence, $isLastDay, $color, $impactNote, $eventId, $userId]);
    if ($stmt->rowCount() > 0) return true;

    // MySQL counts only rows whose values changed. A save that changes just the date
    // list (kept in another table) leaves this row identical, so check it exists.
    $exists = $pdo->prepare('SELECT 1 FROM seasonal_events WHERE id=? AND user_id=? AND is_seeded=0');
    $exists->execute([$eventId, $userId]);
    return (bool) $exists->fetchColumn();
}

function deleteEvent(PDO $pdo, int $eventId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'DELETE FROM seasonal_events WHERE id=? AND user_id=? AND is_seeded=0'
    );
    $stmt->execute([$eventId, $userId]);
    return $stmt->rowCount() > 0;
}

// ── Recurrence expansion ──────────────────────────────────────────────────────

// Expands a list of raw event rows into concrete instances within a date range.
// Returns a flat array of event instances each with 'instance_start' and 'instance_end'.
function expandEvents(array $rawEvents, string $fromDate, string $toDate): array
{
    $from      = new DateTime($fromDate);
    $to        = new DateTime($toDate);
    $instances = [];

    foreach ($rawEvents as $event) {
        $baseStart = new DateTime($event['event_start']);
        $baseEnd   = $event['event_end'] ? new DateTime($event['event_end']) : null;
        $duration  = $baseEnd ? (int) $baseStart->diff($baseEnd)->days : 0;

        switch ($event['recurrence']) {
            case 'none':
                $checkEnd = $baseEnd ?? $baseStart;
                if ($baseStart <= $to && $checkEnd >= $from) {
                    $instances[] = makeEventInstance($event, $baseStart, $baseEnd);
                }
                break;

            case 'yearly':
                for ($y = (int) $from->format('Y'); $y <= (int) $to->format('Y'); $y++) {
                    // Guard against Feb 29 on non-leap years
                    $dateStr = $y . $baseStart->format('-m-d');
                    if (!checkdate(
                        (int) $baseStart->format('m'),
                        (int) $baseStart->format('d'),
                        $y
                    )) continue;

                    $iStart = new DateTime($dateStr);
                    $iEnd   = $duration > 0 ? (clone $iStart)->modify("+{$duration} days") : null;
                    $checkEnd = $iEnd ?? $iStart;

                    if ($iStart <= $to && $checkEnd >= $from) {
                        $instances[] = makeEventInstance($event, $iStart, $iEnd);
                    }
                }
                break;

            case 'custom':
                // Dates come straight from event_occurrences - no rule to apply.
                foreach ($event['occurrences'] ?? [] as $occ) {
                    $iStart = new DateTime($occ['start_date']);
                    $iEnd   = $occ['end_date'] ? new DateTime($occ['end_date']) : null;
                    if ($iStart <= $to && ($iEnd ?? $iStart) >= $from) {
                        $instances[] = makeEventInstance($event, $iStart, $iEnd);
                    }
                }
                break;

            case 'monthly':
                $cursor = new DateTime($from->format('Y-m-01'));
                while ($cursor <= $to) {
                    if ($event['is_last_day']) {
                        $iStart = new DateTime($cursor->format('Y-m-') . $cursor->format('t'));
                    } else {
                        $day         = (int) $baseStart->format('d');
                        $daysInMonth = (int) $cursor->format('t');
                        if ($day > $daysInMonth) {
                            $cursor->modify('first day of next month');
                            continue;
                        }
                        $iStart = new DateTime($cursor->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT));
                    }
                    $iEnd = $duration > 0 ? (clone $iStart)->modify("+{$duration} days") : null;

                    if ($iStart <= $to && $iStart >= $from) {
                        $instances[] = makeEventInstance($event, $iStart, $iEnd);
                    }
                    $cursor->modify('first day of next month');
                }
                break;
        }
    }

    usort($instances, fn($a, $b) => strcmp($a['instance_start'], $b['instance_start']));
    return $instances;
}

// Merges a raw event row with concrete instance dates.
// `type` is set so the chart event filter can group instances as Preset vs. Custom.
function makeEventInstance(array $event, DateTime $iStart, ?DateTime $iEnd): array
{
    return array_merge($event, [
        'instance_start' => $iStart->format('Y-m-d'),
        'instance_end'   => $iEnd ? $iEnd->format('Y-m-d') : null,
        'type'           => $event['is_seeded'] ? 'preset' : 'custom',
    ]);
}

// Returns the next concrete occurrence date (Y-m-d) for an event on or after $today.
// Returns null if the event has no future occurrence (e.g. a past one-time event).
function getNextOccurrenceDate(array $event, string $today): ?string
{
    $now  = new DateTime($today);
    $base = new DateTime($event['event_start']);

    switch ($event['recurrence']) {
        case 'none':
            return $base >= $now ? $base->format('Y-m-d') : null;

        case 'custom':
            // Occurrences arrive sorted by start_date, so the first one that has
            // not finished yet is the next one.
            foreach ($event['occurrences'] ?? [] as $occ) {
                $end = new DateTime($occ['end_date'] ?: $occ['start_date']);
                if ($end >= $now) return $occ['start_date'];
            }
            return null;

        case 'yearly':
            for ($y = (int) $now->format('Y'); $y <= (int) $now->format('Y') + 1; $y++) {
                if (!checkdate((int) $base->format('m'), (int) $base->format('d'), $y)) continue;
                $candidate = new DateTime($y . $base->format('-m-d'));
                if ($candidate >= $now) return $candidate->format('Y-m-d');
            }
            return null;

        case 'monthly':
            $cursor = new DateTime($now->format('Y-m-01'));
            for ($i = 0; $i < 3; $i++) {
                if ($event['is_last_day']) {
                    $candidate = new DateTime($cursor->format('Y-m-') . $cursor->format('t'));
                } else {
                    $day         = (int) $base->format('d');
                    $daysInMonth = (int) $cursor->format('t');
                    if ($day > $daysInMonth) {
                        $cursor->modify('first day of next month');
                        continue;
                    }
                    $candidate = new DateTime(
                        $cursor->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT)
                    );
                }
                if ($candidate >= $now) return $candidate->format('Y-m-d');
                $cursor->modify('first day of next month');
            }
            return null;
    }
    return null;
}

// ── Prophet event_impact_cache helpers ───────────────────────────────────────

// Upserts Prophet regressor coefficients for one product into event_impact_cache.
// $coefficients is an array of maps: [event_id, coefficient, mean_daily_sales, occurrence_count].
function upsertEventImpactCache(PDO $pdo, int $productId, array $coefficients): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO event_impact_cache
             (event_id, product_id, coefficient, mean_daily_sales, occurrence_count, computed_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             coefficient      = VALUES(coefficient),
             mean_daily_sales = VALUES(mean_daily_sales),
             occurrence_count = VALUES(occurrence_count),
             computed_at      = NOW()'
    );
    foreach ($coefficients as $c) {
        $stmt->execute([
            (int)   $c['event_id'],
            $productId,
            (float) $c['coefficient'],
            (float) $c['mean_daily_sales'],
            (int)   $c['occurrence_count'],
        ]);
    }
}

// Returns cached Prophet impact rows for an event, joined with product names.
// Sorted by |impact_pct| DESC so the most-impacted products appear first.
//
// Scoped to $userId's own products: event_impact_cache is keyed by
// (event_id, product_id), and global/preset events (seasonal_events.user_id
// IS NULL) are shared across every account, so every user's forecasted
// products can have a cache row for the SAME preset event. Without the
// products.user_id filter this returned every user's products mixed
// together on one event's detail page / dashboard panel — a cross-account
// data leak (looked like "duplicate" rows when two accounts happened to
// have products with matching names).
function getEventImpactCache(PDO $pdo, int $eventId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
             eic.product_id,
             p.name             AS product_name,
             ' . PRODUCT_CATEGORIES_SQL . ' AS category,
             eic.coefficient,
             eic.mean_daily_sales,
             eic.occurrence_count,
             ROUND(eic.coefficient / NULLIF(eic.mean_daily_sales, 0) * 100, 1) AS impact_pct,
             eic.computed_at
         FROM event_impact_cache eic
         JOIN products p ON p.id = eic.product_id
         WHERE eic.event_id = ? AND p.user_id = ?
         ORDER BY ABS(eic.coefficient / NULLIF(eic.mean_daily_sales, 0)) DESC'
    );
    $stmt->execute([$eventId, $userId]);
    return $stmt->fetchAll();
}
