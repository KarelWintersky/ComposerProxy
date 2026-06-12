#!/usr/bin/env php
<?php
declare(strict_types=1);

$configPath = $argv[1] ?? '/etc/composer-proxy/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "Config file not found: {$configPath}\n");
    exit(1);
}

$config = require $configPath;

echo "Initializing Composer Proxy...\n";

// 1. Создание директорий
$dirs = [
    dirname($config['db_path']),
    $config['cache_dir'],
    // '/var/log/composer-proxy'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "Creating directory: {$dir}\n";
        mkdir($dir, 0755, true);
    }
}

// 2. Инициализация SQLite
$dsn = 'sqlite:' . $config['db_path'];
try {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Включаем WAL для высокой конкурентности
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    // Основная таблица кэша
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cache_entries (
            url TEXT PRIMARY KEY,
            file_path TEXT NOT NULL,
            content_type TEXT,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            last_accessed_at INTEGER NOT NULL
        )
    ");

    // Таблица маппинга архивов
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS archive_mapping (
            archive_url TEXT PRIMARY KEY,
            vendor_package TEXT NOT NULL,
            version TEXT DEFAULT '',
            reference TEXT DEFAULT '',
            source_url TEXT DEFAULT ''
        )
    ");

    // Миграции для cache_entries
    $columns = $pdo->query("PRAGMA table_info(cache_entries)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('composer_package', $columns, true)) {
        $pdo->exec("ALTER TABLE cache_entries ADD COLUMN composer_package TEXT DEFAULT ''");
    }
    if (!in_array('package_version', $columns, true)) {
        $pdo->exec("ALTER TABLE cache_entries ADD COLUMN package_version TEXT DEFAULT ''");
    }
    if (!in_array('reference', $columns, true)) {
        $pdo->exec("ALTER TABLE cache_entries ADD COLUMN reference TEXT DEFAULT ''");
    }
    if (!in_array('source_url', $columns, true)) {
        $pdo->exec("ALTER TABLE cache_entries ADD COLUMN source_url TEXT DEFAULT ''");
    }

    // Миграции для archive_mapping
    $columns2 = $pdo->query("PRAGMA table_info(archive_mapping)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('version', $columns2, true)) {
        $pdo->exec("ALTER TABLE archive_mapping ADD COLUMN version TEXT DEFAULT ''");
    }
    if (!in_array('reference', $columns2, true)) {
        $pdo->exec("ALTER TABLE archive_mapping ADD COLUMN reference TEXT DEFAULT ''");
    }
    if (!in_array('source_url', $columns2, true)) {
        $pdo->exec("ALTER TABLE archive_mapping ADD COLUMN source_url TEXT DEFAULT ''");
    }

    echo "Database initialized successfully at: {$config['db_path']}\n";

} catch (PDOException $e) {
    fwrite(STDERR, "Database initialization failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Setup complete.\n";
