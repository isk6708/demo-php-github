<?php

function getDB(): PDO
{
    $dataDir = __DIR__ . '/data';

    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
            throw new RuntimeException("Cannot create data directory: {$dataDir}");
        }
    }

    $dbPath = $dataDir . '/products.db';
    $isNew  = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($isNew) {
        initDB($pdo);
    } else {
        migrateDB($pdo);
    }

    return $pdo;
}

function initDB(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 0,
            category TEXT NOT NULL DEFAULT 'General',
            reorder_level INTEGER NOT NULL DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    migrateDB($pdo);

    $count = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO products (name, description, price, quantity, category, reorder_level) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $samples = [
            ['Laptop Pro 15"',         'High-performance laptop',   1499.99, 10, 'Computers', 5],
            ['Wireless Mouse',         'Ergonomic wireless mouse',    29.99, 50, 'Accessories', 15],
            ['Mechanical Keyboard',    'RGB mechanical keyboard',     89.99, 30, 'Accessories', 12],
            ['4K Monitor',             '27-inch 4K UHD monitor',     399.00, 15, 'Displays', 8],
            ['USB-C Hub',              'Multi-port USB-C hub',        49.99, 40, 'Accessories', 15],
            ['Noise-Cancel Headphones','Over-ear ANC headphones',    199.99, 20, 'Audio', 10],
            ['Webcam HD',              '1080p HD webcam',             59.99, 25, 'Accessories', 10],
            ['External SSD 1TB',       'Portable 1TB SSD',           129.99, 18, 'Storage', 10],
            ['Bluetooth Speaker',      'Waterproof BT speaker',       79.99, 22, 'Audio', 10],
            ['Laptop Stand',           'Aluminum laptop stand',       34.99, 35, 'Accessories', 15],
        ];
        foreach ($samples as $p) {
            $stmt->execute($p);
        }
    }
}

function migrateDB(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query("PRAGMA table_info(products)") as $row) {
        $columns[$row['name']] = true;
    }

    if ($columns === []) {
        return;
    }

    if (!isset($columns['category'])) {
        $pdo->exec("ALTER TABLE products ADD COLUMN category TEXT NOT NULL DEFAULT 'General'");
    }

    if (!isset($columns['reorder_level'])) {
        $pdo->exec("ALTER TABLE products ADD COLUMN reorder_level INTEGER NOT NULL DEFAULT 10");
    }
}
