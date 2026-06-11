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

    // Создаем таблицу со всеми необходимыми колонками
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

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS archive_mapping (
        archive_url TEXT PRIMARY KEY,
        vendor_package TEXT NOT NULL
    )
    ");

    // Миграция: добавляем колонки, если обновляемся со старой версии
    $columns = $pdo->query("PRAGMA table_info(cache_entries)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('created_at', $columns, true)) {
        $pdo->exec("ALTER TABLE cache_entries ADD COLUMN created_at INTEGER NOT NULL DEFAULT " . time());
    }
    if (!in_array('last_accessed_at', $columns, true)) {
        $pdo->exec("ALTER TABLE cache_entries ADD COLUMN last_accessed_at INTEGER NOT NULL DEFAULT " . time());
    }

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='archive_mapping'")->fetch();
    if (!$tables) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS archive_mapping (
            archive_url TEXT PRIMARY KEY,
            vendor_package TEXT NOT NULL
        )
    ");
    }

    echo "Database initialized successfully at: {$config['db_path']}\n";

    // Установка правильных прав на файлы (для пользователя www-data или выделенного пользователя)
    // В DEB-пакете это обычно делается через chown в postinst, но для надежности:
    if (function_exists('posix_getuid')) {
        // Предполагаем, что сервис будет запущен от пользователя 'composer-proxy' или 'www-data'
        // Здесь можно добавить логику chown, если известно имя пользователя
    }

} catch (PDOException $e) {
    fwrite(STDERR, "Database initialization failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Setup complete.\n";
