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
    return $stmt->fetchAll();
}

// Returns all raw event rows visible to this user (global + own), for the events list page.
// Excludes events the user has hidden. Includes avg_impact_pct for impact badges.
function getAllEventsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM seasonal_events
         WHERE (user_id IS NULL OR user_id = ?)
           AND id NOT IN (SELECT event_id FROM user_hidden_events WHERE user_id = ?)
         ORDER BY is_seeded DESC, name'
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
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
    return $stmt->fetchAll();
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
    return $stmt->fetch();
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
    return $stmt->rowCount() > 0;
}

function deleteEvent(PDO $pdo, int $eventId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'DELETE FROM seasonal_events WHERE id=? AND user_id=? AND is_seeded=0'
    );
    $stmt->execute([$eventId, $userId]);
    return $stmt->rowCount() > 0;
}

// Returns per-product impact data for a set of event windows.
// Each window: ['window_start' => 'Y-m-d', 'window_end' => 'Y-m-d'].
// Impact % = ((event_avg - overall_avg) / overall_avg) * 100.
// Sorted by |impact_pct| DESC, paginated.
function getProductImpactForEvent(
    PDO $pdo, int $userId,
    array $windows,
    int $page, int $perPage
): array {
    if (empty($windows)) {
        return ['products' => [], 'total' => 0];
    }

    [$windowSql, $windowParams] = buildWindowSql($windows);

    // $windowSql is used twice in the SELECT (event_qty + event_days), so bind params twice.
    $sql = "
        SELECT
            p.id,
            p.name,
            p.category,
            SUM(s.quantity_sold)                                                        AS total_qty,
            COUNT(DISTINCT s.sale_date)                                                 AS total_days,
            SUM(CASE WHEN $windowSql THEN s.quantity_sold ELSE 0 END)                  AS event_qty,
            COUNT(DISTINCT CASE WHEN $windowSql THEN s.sale_date ELSE NULL END)        AS event_days
        FROM products p
        JOIN sales s ON s.product_id = p.id
        WHERE p.user_id = ?
        GROUP BY p.id, p.name, p.category
        HAVING total_days > 0 AND event_days > 0
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$windowParams, ...$windowParams, $userId]);
    $rows = $stmt->fetchAll();

    // Calculate impact in PHP and sort by absolute impact
    foreach ($rows as &$row) {
        $overallAvg       = $row['total_qty'] / $row['total_days'];
        $eventAvg         = $row['event_qty'] / $row['event_days'];
        $row['overall_avg'] = round($overallAvg, 2);
        $row['event_avg']   = round($eventAvg, 2);
        $row['impact_pct']  = $overallAvg > 0
            ? round(($eventAvg - $overallAvg) / $overallAvg * 100, 1)
            : 0;
    }
    unset($row);

    usort($rows, fn($a, $b) => abs($b['impact_pct']) <=> abs($a['impact_pct']));

    $total  = count($rows);
    $offset = ($page - 1) * $perPage;

    return [
        'products' => array_slice($rows, $offset, $perPage),
        'total'    => $total,
    ];
}

// Builds a single SQL fragment and params array for matching sale_date against
// a list of [window_start, window_end] pairs.
function buildWindowSql(array $windows): array
{
    $conditions = [];
    $params     = [];
    foreach ($windows as $w) {
        $conditions[] = '(s.sale_date BETWEEN ? AND ?)';
        $params[]     = $w['window_start'];
        $params[]     = $w['window_end'];
    }
    return [implode(' OR ', $conditions), $params];
}

// Writes the cached average impact % back to the event row.
// Called after computing product impact on the detail page.
function updateEventImpactCache(PDO $pdo, int $eventId, float $avgImpactPct): void
{
    $stmt = $pdo->prepare('UPDATE seasonal_events SET avg_impact_pct = ? WHERE id = ?');
    $stmt->execute([round($avgImpactPct, 1), $eventId]);
}

// Returns the average impact % across all products for a set of event windows,
// without pagination. Used to populate the cached avg_impact_pct column.
function computeAvgImpactPct(PDO $pdo, int $userId, array $windows): ?float
{
    $result = getProductImpactForEvent($pdo, $userId, $windows, 1, PHP_INT_MAX);
    if (empty($result['products'])) return null;
    $total = array_sum(array_column($result['products'], 'impact_pct'));
    return round($total / count($result['products']), 1);
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
function makeEventInstance(array $event, DateTime $iStart, ?DateTime $iEnd): array
{
    return array_merge($event, [
        'instance_start' => $iStart->format('Y-m-d'),
        'instance_end'   => $iEnd ? $iEnd->format('Y-m-d') : null,
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

// Refreshes seasonal_events.avg_impact_pct for one event using the cached Prophet coefficients.
// Weighted average: overall_pct = SUM(coefficient) / SUM(mean_daily_sales) * 100.
// High-volume products contribute more weight, matching their share of total store revenue.
function refreshEventAvgImpact(PDO $pdo, int $eventId): void
{
    $pdo->prepare(
        'UPDATE seasonal_events
         SET avg_impact_pct = (
             SELECT ROUND(SUM(eic.coefficient) / NULLIF(SUM(eic.mean_daily_sales), 0) * 100, 1)
             FROM event_impact_cache eic
             WHERE eic.event_id = ?
         )
         WHERE id = ?'
    )->execute([$eventId, $eventId]);
}

// Returns cached Prophet impact rows for an event, joined with product names.
// Sorted by |impact_pct| DESC so the most-impacted products appear first.
function getEventImpactCache(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        'SELECT
             eic.product_id,
             p.name             AS product_name,
             p.category,
             eic.coefficient,
             eic.mean_daily_sales,
             eic.occurrence_count,
             ROUND(eic.coefficient / NULLIF(eic.mean_daily_sales, 0) * 100, 1) AS impact_pct,
             eic.computed_at
         FROM event_impact_cache eic
         JOIN products p ON p.id = eic.product_id
         WHERE eic.event_id = ?
         ORDER BY ABS(eic.coefficient / NULLIF(eic.mean_daily_sales, 0)) DESC'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

// Generates ±$windowDays windows around each event occurrence for impact analysis.
function buildEventWindows(array $occurrences, int $windowDays = 7): array
{
    $windows = [];
    foreach ($occurrences as $occ) {
        $end = $occ['instance_end'] ?? $occ['instance_start'];
        $windows[] = [
            'window_start' => date('Y-m-d', strtotime($occ['instance_start'] . " -{$windowDays} days")),
            'window_end'   => date('Y-m-d', strtotime($end . " +{$windowDays} days")),
        ];
    }
    return $windows;
}
