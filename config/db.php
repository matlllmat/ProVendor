<?php
// config/db.php
// PDO connection — included by any page that needs DB access.
$host = 'localhost';
$db = 'provendor';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// A product can have several categories (product_categories). These fragments
// are for queries where the product table is aliased "p".
const PRODUCT_CATEGORIES_SQL = "(SELECT GROUP_CONCAT(pc.category ORDER BY pc.position SEPARATOR ', ')
                                 FROM product_categories pc WHERE pc.product_id = p.id)";
const PRODUCT_IN_CATEGORY_SQL = 'EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = p.id AND pc.category = ?)';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) { 
    throw new PDOException($e->getMessage(), (int) $e->getCode());
}