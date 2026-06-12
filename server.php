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
use App\StatsHandler;
use Psr\Log\NullLogger;
use function Amp\trapSignal;

// --- Загрузка конфигурации ---
// Приоритет: переменная окружения > конфиг рядом с server.php
$configPath = getenv('COMPOSER_PROXY_CONFIG') ?: (__DIR__ . '/config.php');

if (!file_exists($configPath)) {
    fwrite(STDERR, "❌ Config not found at: {$configPath}\n");
    fwrite(STDERR, "   Set COMPOSER_PROXY_CONFIG env variable or place config.php next to server.php\n");
    exit(1);
}

$config = require $configPath;

// Валидация обязательных параметров
$requiredKeys = ['listen', 'default_upstream', 'cache_dir', 'db_path', 'default_ttl', 'archive_ttl', 'stats_token'];
foreach ($requiredKeys as $key) {
    if (!isset($config[$key])) {
        fwrite(STDERR, "❌ Missing required config key '{$key}' in {$configPath}\n");
        exit(1);
    }
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
