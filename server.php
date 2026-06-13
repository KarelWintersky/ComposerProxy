#!/usr/bin/env php
<?php
declare(strict_types=1);

// Константы приложения
const APP_VERSION = '1.0.0';
const APP_NAME = 'Composer Proxy';

require __DIR__ . '/vendor/autoload.php';

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Socket\ResourceServerSocketFactory;
use App\ComposerProxyHandler;
use App\ConsoleLogger;
use App\Router;
use App\Setup;
use App\StatsHandler;
use Psr\Log\NullLogger;
use function Amp\trapSignal;

// --- Загрузка конфигурации ---
// Приоритет: CLI --config > ENV > /etc > рядом с PHAR > рядом с server.php

// --- 1. Парсинг аргументов CLI ---
$cliConfigPath = null;
$isInstallMode = false;

foreach ($argv as $i => $arg) {
    if (str_starts_with($arg, '--config=')) {
        $cliConfigPath = substr($arg, strlen('--config='));
    } elseif ($arg === '--install') {
        $isInstallMode = true;
    }
}

// --- 2. Определение пути к конфигу ---
$configPath = $cliConfigPath ?? getenv('COMPOSER_PROXY_CONFIG') ?: null;

if (!$configPath) {
    $candidates = [
        '/etc/composer-proxy/config.php',
        dirname(Phar::running() ?: __FILE__) . '/config.php',
        __DIR__ . '/config.php',
    ];
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            $configPath = $candidate;
            break;
        }
    }
}

// Если конфиг не найден, но мы в режиме установки — ошибка
if (!$configPath || !file_exists($configPath)) {
    fwrite(STDERR, "❌ Config not found.\n");
    fwrite(STDERR, "   Usage: composer-proxy --config=/path/to/config.php [--install]\n");
    exit(1);
}

$config = require $configPath;

// --- 3. Режим установки (--install) ---
if ($isInstallMode) {
    echo "*** Running installation mode...\n";

    // Валидация конфига перед установкой
    if (!Setup::validateConfig($config)) {
        exit(1);
    }

    try {
        // Создаем временное PDO подключение для инициализации
        // Setup::init сам создаст директории и таблицы
        $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        Setup::initDatabase($config, $pdo);
        Setup::initStorage($config);

        echo "\n";
        echo "✓ Installation complete!\n";
        $f = __FILE__;
        echo "\nTo start the server, run {$f} without --install\n\n";
        echo "WITH --config={$configPath} \n";
        echo "OR   COMPOSER_PROXY_CONFIG={$configPath} env variable\n";
        echo "OR   place config.php in /etc/composer-proxy/ or next to PHAR directory\n";
        echo "\n";

    } catch (\Throwable $e) {
        fwrite(STDERR, "❌ Installation failed: " . $e->getMessage() . "\n");
        exit(1);
    }

    exit(0); // Завершаем работу после установки
}

// Валидация обязательных параметров
if (!Setup::validateConfig($config)) {
    exit(1);
}

// Проверка директорий
if (!is_dir($config['cache_dir']) && !mkdir($config['cache_dir'], 0755, true)) {
    fwrite(STDERR, "❌ Cannot create cache directory: {$config['cache_dir']}\n");
    exit(1);
}

$dbDir = dirname($config['db_path']);
if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true)) {
    fwrite(STDERR, "❌ Cannot create database directory: {$dbDir}\n");
    exit(1);
}

// Инициализация PDO
try {
    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
} catch (PDOException $e) {
    fwrite(STDERR, "❌ Database initialization failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Проверка наличия таблиц
try {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cache_entries', $tables, true) || !in_array('archive_mapping', $tables, true)) {
        fwrite(STDERR, "❌ Database tables missing. Run: php setup_database.php {$configPath}\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "❌ Database check failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Создание зависимостей
$logger = new ConsoleLogger();
$httpClient = HttpClientBuilder::buildDefault();

$statsHandler = new StatsHandler($pdo, $config);
$proxyHandler = new ComposerProxyHandler($pdo, $httpClient, $config);
$router = new Router($statsHandler, $proxyHandler);

// Запуск сервера
$server = new SocketHttpServer(
    $logger,
    new ResourceServerSocketFactory(),
    new SocketClientFactory(new NullLogger())
);


$server->expose($config['listen']);

$errorHandler = new DefaultErrorHandler();

echo "🚀 " . APP_NAME . " v" . APP_VERSION . " starting on {$config['listen']}...\n";
echo "   Config:    {$configPath}\n";
echo "   Cache dir: {$config['cache_dir']}\n";
echo "   Database:  {$config['db_path']}\n";
echo "   Upstream:  {$config['default_upstream']}\n";

$server->start($router, $errorHandler);

trapSignal([SIGINT, SIGTERM]);
echo "\n🛑 Shutting down gracefully...\n";
$server->stop();
