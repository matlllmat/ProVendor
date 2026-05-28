<?php
// queries/import.query.php
// All SQL for upserting products and batch-inserting/updating sales rows.
// Version history (snapshots/restore) lives in queries/version.query.php.

// Returns the product id.
// Inserts if the product doesn't exist yet.
// If it does exist, updates any fields that are currently NULL but now have a value.
function upsertProduct(PDO $pdo, int $userId, string $name, ?string $sku, ?string $category, ?string $subcategory, ?float $cost, ?float $price): int
{
    $stmt = $pdo->prepare(
        'SELECT id, sku, category, subcategory, cost_price, selling_price
         FROM products WHERE user_id = ? AND name = ? LIMIT 1'
    );
    $stmt->execute([$userId, $name]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Identity attributes (sku, category, subcategory): keep the first value we
        // ever saw — these don't change frequently and the user can edit them in My Store.
        // Money attributes (cost_price, selling_price): last-write-wins, since prices
        // change over time and the canonical value is whatever the most recent import says.
        $updates = [];
        $params  = [];

        if ($existing['sku']         === null && $sku         !== null) { $updates[] = 'sku = ?';         $params[] = $sku; }
        if ($existing['category']    === null && $category    !== null) { $updates[] = 'category = ?';    $params[] = $category; }
        if ($existing['subcategory'] === null && $subcategory !== null) { $updates[] = 'subcategory = ?'; $params[] = $subcategory; }
        if ($cost  !== null) { $updates[] = 'cost_price = ?';    $params[] = $cost;  }
        if ($price !== null) { $updates[] = 'selling_price = ?'; $params[] = $price; }

        if (!empty($updates)) {
            $params[] = (int) $existing['id'];
            $pdo->prepare('UPDATE products SET ' . implode(', ', $updates) . ' WHERE id = ?')
                ->execute($params);
        }

        return (int) $existing['id'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO products (user_id, name, sku, category, subcategory, cost_price, selling_price)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $name, $sku, $category, $subcategory, $cost, $price]);
    return (int) $pdo->lastInsertId();
}

// Returns a set of "product_id|sale_date" keys already in the DB for this user.
// Used to skip duplicate rows when importing a new CSV.
function getExistingSalesPairs(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.product_id, s.sale_date
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);

    $pairs = [];
    foreach ($stmt->fetchAll() as $row) {
        $pairs[$row['product_id'] . '|' . $row['sale_date']] = true;
    }
    return $pairs;
}

// Same shape as getExistingSalesByName but returns sale id + qty per pair so
// import.php can issue UPDATEs in replace mode without a second lookup.
function getExistingSalesWithIdsByName(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, p.name, s.sale_date, s.quantity_sold
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[mb_strtolower($row['name']) . '|' . $row['sale_date']] = [
            'id'  => (int) $row['id'],
            'qty' => (int) $row['quantity_sold'],
        ];
    }
    return $out;
}

// Returns map of "<product_name>|<sale_date>" → quantity_sold for the user's
// current sales. Keyed by name (not product_id) so the preflight UI can compare
// against new products that don't exist in the DB yet.
function getExistingSalesByName(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.name, s.sale_date, s.quantity_sold
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[mb_strtolower($row['name']) . '|' . $row['sale_date']] = (int) $row['quantity_sold'];
    }
    return $out;
}

// Counts existing sales records within a date range for overlap detection.
function countExistingSalesInRange(PDO $pdo, int $userId, string $dateFrom, string $dateTo): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ? AND s.sale_date BETWEEN ? AND ?'
    );
    $stmt->execute([$userId, $dateFrom, $dateTo]);
    return (int) $stmt->fetchColumn();
}

// Same as getExistingSalesPairs but maps pairKey → sale_id (used for replace-overlap mode).
function getExistingSalesPairsWithIds(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.product_id, s.sale_date
         FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    );
    $stmt->execute([$userId]);
    $pairs = [];
    foreach ($stmt->fetchAll() as $row) {
        $pairs[$row['product_id'] . '|' . $row['sale_date']] = (int) $row['id'];
    }
    return $pairs;
}

// Updates the quantity_sold for a single sale row (inline edit).
// Verifies ownership before writing. Returns true on success.
function updateSaleQty(PDO $pdo, int $saleId, int $userId, int $qty): bool
{
    // Ownership check via product join
    $stmt = $pdo->prepare(
        'SELECT s.id FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE s.id = ? AND p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$saleId, $userId]);
    if (!$stmt->fetchColumn()) return false;

    $pdo->prepare('UPDATE sales SET quantity_sold = ? WHERE id = ?')
        ->execute([$qty, $saleId]);
    return true;
}

// Bulk-updates quantity_sold for many sales rows in one query per chunk,
// using a CASE WHEN id construct instead of N individual UPDATEs.
// $rows shape: [['sale_id' => int, 'qty' => int], ...]
function bulkUpdateSalesByPair(PDO $pdo, array $rows): void
{
    if (empty($rows)) return;

    $chunkSize = 500;
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $qtyWhen  = '';
        $idPlaces = [];
        $qtyArgs  = [];
        $idArgs   = [];

        foreach ($chunk as $r) {
            $qtyWhen   .= ' WHEN ? THEN ?';
            $idPlaces[] = '?';
            $qtyArgs[]  = $r['sale_id']; $qtyArgs[] = $r['qty'];
            $idArgs[]   = $r['sale_id'];
        }

        $sql = "UPDATE sales SET quantity_sold = CASE id $qtyWhen END
                WHERE id IN (" . implode(',', $idPlaces) . ")";

        $pdo->prepare($sql)->execute(array_merge($qtyArgs, $idArgs));
    }
}

// Batch-inserts sales rows in chunks. 3 placeholders per row → 500 rows per
// chunk fits well under MySQL's 65,535 placeholder limit.
function insertSalesBatch(PDO $pdo, array $rows): void
{
    if (empty($rows)) return;

    $chunkSize = 500;
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
        $sql = "INSERT INTO sales (product_id, quantity_sold, sale_date) VALUES $placeholders";

        $values = [];
        foreach ($chunk as $row) {
            $values[] = $row['product_id'];
            $values[] = $row['quantity_sold'];
            $values[] = $row['sale_date'];
        }

        $pdo->prepare($sql)->execute($values);
    }
}

// Returns total product and sales counts for this user.
// Also returns date span and visible-event count so the Prophet pipeline
// explainer can show live numbers without a second query.
function getImportSummary(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM products WHERE user_id = ?)                                                        AS total_products,
            (SELECT COUNT(s.id) FROM sales s JOIN products p ON p.id = s.product_id WHERE p.user_id = ?)             AS total_sales,
            (SELECT COUNT(*) FROM dataset_versions WHERE user_id = ?)                                                AS total_versions,
            (SELECT MIN(s.sale_date) FROM sales s JOIN products p ON p.id = s.product_id WHERE p.user_id = ?)        AS date_from,
            (SELECT MAX(s.sale_date) FROM sales s JOIN products p ON p.id = s.product_id WHERE p.user_id = ?)        AS date_to,
            (SELECT COUNT(*) FROM seasonal_events
              WHERE (user_id IS NULL OR user_id = ?)
                AND id NOT IN (SELECT event_id FROM user_hidden_events WHERE user_id = ?))                           AS total_events'
    );
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    return $stmt->fetch();
}

