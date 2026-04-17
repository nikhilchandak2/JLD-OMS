<?php

/**
 * Reset (deactivate + insert/reactivate) reference data for:
 * - companies (status=active/ inactive)
 * - products (is_active=1/0)
 *
 * This avoids hard-deleting rows that may be referenced by existing orders.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$pdo = (new Database())->getConnection();

echo "DB: " . ($_ENV['DB_NAME'] ?? '') . PHP_EOL;

// Deactivate everything first so the UI selection only shows the new dataset.
$pdo->exec("UPDATE companies SET status = 'inactive'");
$pdo->exec("UPDATE products SET is_active = 0");

$companies = [
    [
        'name' => 'Jaichand Lal Daga',
        'code' => 'JAICHAND_LAL_DAGA',
    ],
    [
        'name' => 'JLD Minerals Private Limited',
        'code' => 'JLD_MINERALS_PRIVATE_LIMITED',
    ],
];

$companySelect = $pdo->prepare("SELECT id FROM companies WHERE name = ? LIMIT 1");
$companyUpdate = $pdo->prepare("
    UPDATE companies
    SET code = ?, status = 'active'
    WHERE id = ?
");
$companyInsert = $pdo->prepare("
    INSERT INTO companies (name, code, address, phone, email, contact_person, gst_number, pan_number, status)
    VALUES (?, ?, '', '', '', '', '', '', 'active')
");

foreach ($companies as $c) {
    $companySelect->execute([$c['name']]);
    $id = $companySelect->fetchColumn();

    if ($id) {
        $companyUpdate->execute([$c['code'], (int)$id]);
    } else {
        $companyInsert->execute([$c['name'], $c['code']]);
    }
}

$products = [
    ['code' => 'JJN-1', 'name' => 'JJN-1'],
    ['code' => 'JN-2', 'name' => 'JN-2'],
    ['code' => 'N-3', 'name' => 'N-3'],
    ['code' => 'YJN-1', 'name' => 'YJN-1'],
    ['code' => 'JJN-1 (Tukdi)', 'name' => 'JJN-1 (Tukdi)'],
    ['code' => 'BNT-31', 'name' => 'BNT-31'],
    ['code' => 'BNT (Tukdi)', 'name' => 'BNT (Tukdi)'],
];

$productSelect = $pdo->prepare("SELECT id FROM products WHERE code = ? LIMIT 1");
$productUpdate = $pdo->prepare("
    UPDATE products
    SET name = ?, is_active = 1
    WHERE id = ?
");
$productInsert = $pdo->prepare("
    INSERT INTO products (code, name, is_active, created_at, updated_at)
    VALUES (?, ?, 1, NOW(), NOW())
");

foreach ($products as $p) {
    $productSelect->execute([$p['code']]);
    $id = $productSelect->fetchColumn();

    if ($id) {
        $productUpdate->execute([$p['name'], (int)$id]);
    } else {
        $productInsert->execute([$p['code'], $p['name']]);
    }
}

echo "Reference data reset complete." . PHP_EOL;

