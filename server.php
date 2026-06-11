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

class ComposerProxyHandler implements RequestHandler {
    private PDO $pdo;
    private object $httpClient;
    private array $config;

    public function __construct(PDO $pdo, object $httpClient, array $config) {
        $this->pdo = $pdo;
        $this->httpClient = $httpClient;
        $this->config = $config;
    }

    public function handleRequest(Request $request): Response {
        $path = $request->getUri()->getPath();

        if ($path === '/stats') {
            return $this->handleStats($request);
        }

        return $this->handleProxy($request);
    }

    private function handleStats(Request $request): Response {
        parse_str($request->getUri()->getQuery(), $queryParams);

        if (($queryParams['token'] ?? '') !== $this->config['stats_token']) {
            return new Response(403, ['content-type' => 'text/plain'], 'Forbidden: Invalid token');
        }

        $stmt = $this->pdo->query("SELECT url, file_path, content_type, expires_at, created_at, last_accessed_at FROM cache_entries ORDER BY last_accessed_at DESC");
        $entries = $stmt->fetchAll();

        $totalSize = 0;
        $rows = [];
        foreach ($entries as $row) {
            $size = file_exists($row['file_path']) ? filesize($row['file_path']) : 0;
            $totalSize += $size;

            $package = 'Unknown'; $version = 'N/A';
            if (preg_match('#p2/([^/]+/[^/]+)(?:~([^/\.]+))?\.json#', $row['url'], $m)) {
                $package = $m[1]; $version = $m[2] ?: 'any';
            } elseif (preg_match('#/d/([^/]+/[^/]+)/([^/]+)#', $row['url'], $m)) {
                // Packagist v2 использует /d/vendor/package/hash.zip
                $package = $m[1]; $version = $m[2];
            } elseif (preg_match('#dist/([^/]+/[^/]+)/([^/]+)#', $row['url'], $m)) {
                // Старый формат
                $package = $m[1]; $version = $m[2];
            }

            $rows[] = [
                'package' => htmlspecialchars($package),
                'version' => htmlspecialchars($version),
                'type' => str_contains($row['url'], '.zip') ? '📦 Archive' : '📄 Metadata',
                'size' => $size,
                'created' => date('Y-m-d H:i', $row['created_at']),
                'accessed' => date('Y-m-d H:i', $row['last_accessed_at']),
            ];
        }

        $formatBytes = fn(int $bytes) => round($bytes / (1024 ** ($pow = floor(log(max($bytes, 1), 1024)))), 2) . ['B', 'KB', 'MB', 'GB'][$pow];

        $html = "<!DOCTYPE html><html><head><title>Proxy Stats</title>
        <style>body{font-family:system-ui,sans-serif;padding:20px;background:#f8f9fa;} table{border-collapse:collapse;width:100%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);} th,td{padding:10px;text-align:left;border-bottom:1px solid #eee;} th{background:#f1f3f5;color:#495057;}</style>
        </head><body>
        <h2>Composer Proxy Cache</h2>
        <p>Files: <b>" . count($rows) . "</b> | Size: <b>" . $formatBytes($totalSize) . "</b></p>
        <table><tr><th>Package</th><th>Version</th><th>Type</th><th>Size</th><th>Created</th><th>Accessed</th></tr>";

        foreach ($rows as $r) {
            $html .= "<tr><td>{$r['package']}</td><td>{$r['version']}</td><td>{$r['type']}</td><td>{$formatBytes($r['size'])}</td><td>{$r['created']}</td><td>{$r['accessed']}</td></tr>";
        }
        $html .= "</table></body></html>";

        return new Response(200, ['content-type' => 'text/html; charset=utf-8'], $html);
    }

    private function handleProxy(Request $request): Response {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $targetUrl = str_starts_with($path, 'http') ? $path : rtrim($this->config['default_upstream'], '/') . $path;

        // 1. Проверка кэша (Future->await() - единственный способ в v3)
        $cacheFuture = async(function () use ($targetUrl) {
            $stmt = $this->pdo->prepare("SELECT file_path, content_type, expires_at FROM cache_entries WHERE url = :url");
            $stmt->execute(['url' => $targetUrl]);
            return $stmt->fetch();
        });
        $cacheRow = $cacheFuture->await();

        $isHit = $cacheRow && time() < $cacheRow['expires_at'] && file_exists($cacheRow['file_path']);

        if ($isHit) {
            async(function () use ($targetUrl) {
                $stmt = $this->pdo->prepare("UPDATE cache_entries SET last_accessed_at = :time WHERE url = :url");
                $stmt->execute(['time' => time(), 'url' => $targetUrl]);
            });

            // openFile возвращает объект File (реализует ReadableStream)
            /** @var File $file */
            $file = openFile($cacheRow['file_path'], 'r');
            return new Response(200, [
                'content-type' => $cacheRow['content_type'] ?: 'application/octet-stream',
                'x-cache-status' => 'HIT',
            ], $file);
        }

        $tempPath = $this->config['cache_dir'] . '/' . md5($targetUrl) . '.tmp';
        $finalPath = $this->config['cache_dir'] . '/' . md5($targetUrl);

        $clientRequest = new ClientRequest($targetUrl);
        $clientRequest->setHeader('User-Agent', 'Composer-Proxy/1.0');

        $upstreamResponse = $this->httpClient->request($clientRequest);
        $status = $upstreamResponse->getStatus();
        $contentType = $upstreamResponse->getHeader('content-type') ?: 'application/octet-stream';

        if ($status === 200) {
            $body = $upstreamResponse->getBody();

            // Логирование: куда пытаемся писать
            fwrite(STDERR, "[DEBUG] Attempting to cache: {$targetUrl}\n");
            fwrite(STDERR, "[DEBUG] Temp path: {$tempPath}\n");
            fwrite(STDERR, "[DEBUG] Final path: {$finalPath}\n");
            fwrite(STDERR, "[DEBUG] Cache dir writable: " . (is_writable($this->config['cache_dir']) ? 'YES' : 'NO') . "\n");

            // openFile синхронно возвращает объект File
            /** @var File $file */
            try {
                // openFile возвращает объект File напрямую
                /** @var File $file */
                $file = openFile($tempPath, 'w');

                // В AMPHP v3 методы потоков (read/write) возвращают результат напрямую через Fiber.
                // ->await() вызывать НЕ нужно. read() вернет строку или null.
                $bytesWritten = 0;
                while (($chunk = $body->read()) !== null) {
                    $file->write($chunk);
                    $bytesWritten += strlen($chunk);
                }
                $file->close();

                fwrite(STDERR, "[DEBUG] Downloaded {$bytesWritten} bytes to temp file\n");

                if (!rename($tempPath, $finalPath)) {
                    fwrite(STDERR, "[ERROR] Failed to rename {$tempPath} to {$finalPath}\n");
                } else {
                    fwrite(STDERR, "[DEBUG] Successfully cached to {$finalPath}\n");
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "[ERROR] Failed to write cache file: " . $e->getMessage() . "\n");
                fwrite(STDERR, $e->getTraceAsString() . "\n");
                throw $e;
            }

            $isArchive = str_contains($targetUrl, '.zip') || str_contains($targetUrl, '.tar') || str_contains($targetUrl, 'codeload.github.com');
            $ttl = $isArchive ? $this->config['archive_ttl'] : $this->config['default_ttl'];
            $now = time();

            async(function () use ($targetUrl, $finalPath, $contentType, $ttl, $now) {
                try {
                    fwrite(STDERR, "[DEBUG] Saving to database: {$targetUrl}\n");
                    $stmt = $this->pdo->prepare("REPLACE INTO cache_entries (url, file_path, content_type, expires_at, created_at, last_accessed_at) VALUES (:url, :path, :ct, :exp, :now, :now)");
                    $stmt->execute([
                        'url' => $targetUrl, 'path' => $finalPath, 'ct' => $contentType,
                        'exp' => $now + $ttl, 'now' => $now
                    ]);
                    fwrite(STDERR, "[DEBUG] Database entry created successfully\n");
                } catch (\Throwable $e) {
                    fwrite(STDERR, "[ERROR] Database write failed: " . $e->getMessage() . "\n");
                }
            });

            /** @var File $outFile */
            $outFile = openFile($finalPath, 'r');
            return new Response(200, [
                'content-type' => $contentType,
                'x-cache-status' => 'MISS',
            ], $outFile);
        } else {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            return new Response($status, ['content-type' => 'text/plain', 'x-cache-status' => 'ERROR'], "Upstream returned {$status}");
        }
    }
}

// Создаем простой логгер, который пишет ВСЁ (включая трейсы исключений) в STDERR (консоль)
$consoleLogger = new class implements LoggerInterface {
    public function emergency(\Stringable|string $message, array $context = []): void { $this->log('EMERGENCY', $message, $context); }
    public function alert(\Stringable|string $message, array $context = []): void { $this->log('ALERT', $message, $context); }
    public function critical(\Stringable|string $message, array $context = []): void { $this->log('CRITICAL', $message, $context); }
    public function error(\Stringable|string $message, array $context = []): void { $this->log('ERROR', $message, $context); }
    public function warning(\Stringable|string $message, array $context = []): void { $this->log('WARNING', $message, $context); }
    public function notice(\Stringable|string $message, array $context = []): void { $this->log('NOTICE', $message, $context); }
    public function info(\Stringable|string $message, array $context = []): void { $this->log('INFO', $message, $context); }
    public function debug(\Stringable|string $message, array $context = []): void { $this->log('DEBUG', $message, $context); }

    public function log($level, \Stringable|string $message, array $context = []): void {
        $timestamp = date('H:i:s');
        $msg = (string)$message;

        // Если AMPHP поймал исключение, он кладет его в $context['exception']
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $msg .= "\n   Exception: " . $context['exception']->getMessage() . "\n" . $context['exception']->getTraceAsString();
        }

        fwrite(STDERR, "[{$timestamp}] [{$level}] {$msg}\n");
    }
};

// 1. Создаем экземпляр сервера напрямую
$server = new SocketHttpServer(
    $consoleLogger,
    new \Amp\Socket\ResourceServerSocketFactory(),
    new Amp\Http\Server\Driver\SocketClientFactory(new NullLogger())
);
$server->expose($config['listen']);

$errorHandler = new DefaultErrorHandler();
$handler = new ComposerProxyHandler($pdo, $httpClient, $config);

// В v3 метод start() вызывается напрямую на экземпляре SocketHttpServer
$server->start($handler, $errorHandler);

echo "🚀 Starting Composer Proxy on {$config['listen']}...\n";

$signals = [];
if (defined('SIGINT')) $signals[] = SIGINT;
if (defined('SIGTERM')) $signals[] = SIGTERM;
if (empty($signals)) {
    // Fallback для систем без pcntl
    $signals = [2, 15];
}
trapSignal($signals);

echo "\n🛑 Shutting down gracefully...\n";
$server->stop();