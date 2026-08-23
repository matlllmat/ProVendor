<?php
// queries/user.query.php
// All SQL queries related to the users table.

function getUserByEmail(PDO $pdo, string $email): array|false
{
    $stmt = $pdo->prepare('SELECT id, password FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function userHasSales(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

function createUser(PDO $pdo, string $name, string $storeName, string $email, string $passwordHash): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, store_name, email, password) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$name, $storeName, $email, $passwordHash]);
    return (int) $pdo->lastInsertId();
}

function getUserById(PDO $pdo, int $userId): array|false
{
    $stmt = $pdo->prepare('SELECT id, name, store_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// Returns full profile fields needed by the profile tab (excludes password hash).
function getUserProfile(PDO $pdo, int $userId): array|false
{
    $stmt = $pdo->prepare(
        'SELECT id, name, store_name, email, forecast_horizon_days FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// Returns the user's global forecast horizon in days (falls back to 30).
function getForecastHorizon(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT forecast_horizon_days FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $days = $stmt->fetchColumn();
    return $days !== false ? (int) $days : 30;
}

// Sets the user's global forecast horizon. Caller clamps to the 1–60 range.
function setForecastHorizon(PDO $pdo, int $userId, int $days): void
{
    $pdo->prepare('UPDATE users SET forecast_horizon_days = ? WHERE id = ?')
        ->execute([$days, $userId]);
}

// Updates display name and store name.
function updateUserProfile(PDO $pdo, int $userId, string $name, string $storeName): void
{
    $pdo->prepare('UPDATE users SET name = ?, store_name = ? WHERE id = ?')
        ->execute([$name, $storeName, $userId]);
}

// Returns the stored password hash for the given user (used for change-password verification).
function getUserPasswordHash(PDO $pdo, int $userId): string|false
{
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

// Replaces the stored password hash with a new one.
function updateUserPassword(PDO $pdo, int $userId, string $newHash): void
{
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([$newHash, $userId]);
}

// Permanently deletes all imported data for a user: forecasts → sales → versions → products.
// The user account and store name are preserved.
function clearUserData(PDO $pdo, int $userId): void
{
    // Forecasts reference products — delete first to avoid FK violation.
    $pdo->prepare(
        'DELETE f FROM forecasts f
         JOIN products p ON p.id = f.product_id
         WHERE p.user_id = ?'
    )->execute([$userId]);

    // Sales reference products — delete next so products can go.
    $pdo->prepare(
        'DELETE s FROM sales s
         JOIN products p ON p.id = s.product_id
         WHERE p.user_id = ?'
    )->execute([$userId]);

    // Version snapshots also reference products (via sales_snapshots) — drop them
    // before products so the snapshot FK doesn't block the delete.
    $pdo->prepare('DELETE FROM dataset_versions WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM products WHERE user_id = ?')->execute([$userId]);
}
