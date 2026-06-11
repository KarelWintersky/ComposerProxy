#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\File\File;
use App\ComposerProxyHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\File\openFile;
use function Amp\async;
use function Amp\trapSignal;

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "Config not found at {$configPath}. Run setup first.\n");
    exit(1);
}
$config = require $configPath;

$pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA synchronous = NORMAL;');

$httpClient = HttpClientBuilder::buildDefault();

// --- Запуск сервера ---
$server = new SocketHttpServer(
    new \App\ConsoleLogger(),
    new \Amp\Socket\ResourceServerSocketFactory(),
    new Amp\Http\Server\Driver\SocketClientFactory(new \Psr\Log\NullLogger())
);

$server->expose($config['listen']);

$errorHandler = new DefaultErrorHandler();
$handler = new ComposerProxyHandler(
        $pdo,
        $httpClient,
        $config,
        new \App\StatsHandler($pdo, $config)
);

echo "🚀 Starting Composer Proxy on {$config['listen']}...\n";
$server->start($handler, $errorHandler);

trapSignal([SIGINT, SIGTERM]); // SIGINT, SIGTERM
echo "\n🛑 Shutting down gracefully...\n";
$server->stop();
