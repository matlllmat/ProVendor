<?php
// queries/import.query.php
// All SQL for saving import sessions, upserting products, and batch-inserting sales.

function saveImportSession(PDO $pdo, int $userId, string $filename, array $mapping, string $granularity): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO import_sessions (user_id, filename, column_mapping, granularity)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $filename, json_encode($mapping), $granularity]);
    return (int) $pdo->lastInsertId();
}

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
        // Build an UPDATE for any column that was NULL and now has a value
        $updates = [];
        $params  = [];

        if ($existing['sku']           === null && $sku         !== null) { $updates[] = 'sku = ?';           $params[] = $sku; }
        if ($existing['category']      === null && $category    !== null) { $updates[] = 'category = ?';      $params[] = $category; }
        if ($existing['subcategory']   === null && $subcategory !== null) { $updates[] = 'subcategory = ?';   $params[] = $subcategory; }
        if ($existing['cost_price']    === null && $cost        !== null) { $updates[] = 'cost_price = ?';    $params[] = $cost; }
        if ($existing['selling_price'] === null && $price       !== null) { $updates[] = 'selling_price = ?'; $params[] = $price; }

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

// Batch-inserts sales rows in chunks to stay under MySQL's 65,535 placeholder limit.
// 4 placeholders per row → max 500 rows per chunk (2,000 placeholders, well within limit).
function insertSalesBatch(PDO $pdo, array $rows): void
{
    if (empty($rows)) return;

    $chunkSize = 500;
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
        $sql = "INSERT INTO sales (product_id, import_session_id, quantity_sold, sale_date) VALUES $placeholders";

        $values = [];
        foreach ($chunk as $row) {
            $values[] = $row['product_id'];
            $values[] = $row['import_session_id'];
            $values[] = $row['quantity_sold'];
            $values[] = $row['sale_date'];
        }

        $pdo->prepare($sql)->execute($values);
    }
}

// Returns all import sessions for a user with the sales count and date range per session.
function getImportSessions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT imp.id, imp.filename, imp.granularity, imp.imported_at,
                COUNT(s.id)       AS row_count,
                MIN(s.sale_date)  AS date_from,
                MAX(s.sale_date)  AS date_to
         FROM import_sessions imp
         LEFT JOIN sales s ON s.import_session_id = imp.id
         WHERE imp.user_id = ?
         GROUP BY imp.id
         ORDER BY imp.imported_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Returns total product and sales counts for this user.
function getImportSummary(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM products WHERE user_id = ?)                                            AS total_products,
            (SELECT COUNT(s.id) FROM sales s JOIN products p ON p.id = s.product_id WHERE p.user_id = ?) AS total_sales,
            (SELECT COUNT(*) FROM import_sessions WHERE user_id = ?)                                     AS total_sessions'
    );
    $stmt->execute([$userId, $userId, $userId]);
    return $stmt->fetch();
}

// Returns one page of sales records for a session, joined with product info.
function getSessionRecords(PDO $pdo, int $sessionId, int $userId, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    $stmt   = $pdo->prepare(
        'SELECT s.id AS sale_id, p.name AS product_name, p.category, s.sale_date, s.quantity_sold
         FROM sales s
         JOIN products p          ON p.id   = s.product_id
         JOIN import_sessions imp ON imp.id = s.import_session_id
         WHERE s.import_session_id = ? AND imp.user_id = ?
         ORDER BY s.sale_date DESC, p.name
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$sessionId, $userId, $perPage, $offset]);
    return $stmt->fetchAll();
}

function countSessionRecords(PDO $pdo, int $sessionId, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM sales s
         JOIN import_sessions imp ON imp.id = s.import_session_id
         WHERE s.import_session_id = ? AND imp.user_id = ?'
    );
    $stmt->execute([$sessionId, $userId]);
    return (int) $stmt->fetchColumn();
}

// Deletes a single import session (and its sales rows) after verifying ownership.
// Returns true on success, false if the session doesn't exist or belongs to another user.
function deleteImportSession(PDO $pdo, int $sessionId, int $userId): bool
{
    // Verify ownership before touching anything
    $stmt = $pdo->prepare(
        'SELECT id FROM import_sessions WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, $userId]);
    if (!$stmt->fetchColumn()) return false;

    // Delete associated sales rows first (FK is ON DELETE SET NULL, so we cascade manually)
    $pdo->prepare('DELETE FROM sales WHERE import_session_id = ?')->execute([$sessionId]);
    $pdo->prepare('DELETE FROM import_sessions WHERE id = ?')->execute([$sessionId]);
    return true;
}
