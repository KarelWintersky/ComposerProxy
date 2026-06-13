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
use App\App;
use App\ComposerProxyHandler;
use App\ConsoleLogger;
use App\Router;
use App\Setup;
use App\StatsHandler;
use Psr\Log\NullLogger;
use function Amp\trapSignal;

// 1. Инициализация ядра
$app = App::getInstance();
$app->init($argv);

$config = $app->getConfig();

// 2. Проверка режима установки
if (in_array('--install', $argv, true)) {
    echo "*** Running installation mode...\n";

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
        echo "WITH --config={$app->configPath} \n";
        echo "OR   COMPOSER_PROXY_CONFIG={$app->configPath} env variable\n";
        echo "OR   place config.php in /etc/composer-proxy/ or next to PHAR directory\n";
        echo "\n";

    } catch (\Throwable $e) {
        fwrite(STDERR, "❌ Installation failed: " . $e->getMessage() . "\n");
        exit(1);
    }

    exit(0); // Завершаем работу после установки
}

// 3. Запуск сервера
$server = new SocketHttpServer(
    new \App\ConsoleLogger(), // Или используйте логгер из App, если нужно
    new ResourceServerSocketFactory(),
    new SocketClientFactory(new NullLogger())
);

$server->expose($config['listen']);

echo "🚀 Starting on {$config['listen']}...\n";

// Передаем сам App как обработчик запросов
$server->start($app, new DefaultErrorHandler());

trapSignal([2, 15]);
echo "\n🛑 Shutting down...\n";
$server->stop();
