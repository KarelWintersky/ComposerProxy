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

        // Новый маршрут для скачивания архивов через прокси
        if ($path === '/proxy') {
            return $this->handleProxyDownload($request);
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
                $package = $m[1]; $version = $m[2] ?? 'any';
            } elseif (preg_match('#/d/([^/]+/[^/]+)/([^/]+)#', $row['url'], $m)) {
                $package = $m[1]; $version = $m[2];
            } elseif (preg_match('#api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/]+)#', $row['url'], $m)) {
                $package = $m[1] . '/' . $m[2]; $version = substr($m[3], 0, 8);
            }

            $rows[] = [
                'package' => htmlspecialchars($package),
                'version' => htmlspecialchars($version),
                'type' => str_contains($row['file_path'], '.zip') ? '📦 Archive' : '📄 Metadata',
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

    /**
     * Извлекает vendor/package из URL и формирует путь к файлу
     */
    private function getCachePath(string $url, string $type): string {
        $vendor = 'unknown';
        $package = 'unknown';
        $hash = null;

        // Для метаданных: https://repo.packagist.org/p2/vendor/package.json
        if (preg_match('#/p2/([^/]+)/([^/]+?)(?:~[^/]+)?\.json#', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
        }
        // Для архивов Packagist: https://repo.packagist.org/d/vendor/package/hash.zip
        elseif (preg_match('#/d/([^/]+)/([^/]+)/([^/]+)\.zip#', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
            $hash = $m[3];
        }
        // Для архивов GitHub: https://api.github.com/repos/vendor/package/zipball/hash
        elseif (preg_match('#api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/]+)#', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
            $hash = $m[3];
        }
        // Для архивов GitHub (codeload): https://codeload.github.com/vendor/package/legacy.zip/hash
        elseif (preg_match('#codeload\.github\.com/([^/]+)/([^/]+)/legacy\.zip/([^/]+)#', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
            $hash = $m[3];
        }

        // Формируем путь
        if ($type === 'metadata') {
            // cache/vendor/package/package.json
            return $this->config['cache_dir'] . '/' . $vendor . '/' . $package . '/package.json';
        } else {
            // cache/vendor/package/hash.zip
            return $this->config['cache_dir'] . '/' . $vendor . '/' . $package . '/' . $hash . '.zip';
        }
    }

    private function handleProxy(Request $request): Response {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $targetUrl = str_starts_with($path, 'http') ? $path : rtrim($this->config['default_upstream'], '/') . $path;

        try {
            // 1. Проверка кэша
            $cacheFuture = async(function () use ($targetUrl) {
                $stmt = $this->pdo->prepare("SELECT file_path, content_type, expires_at FROM cache_entries WHERE url = :url");
                $stmt->execute(['url' => $targetUrl]);
                return $stmt->fetch();
            });
            $cacheRow = $cacheFuture->await();

            $isHit = $cacheRow && time() < $cacheRow['expires_at'] && file_exists($cacheRow['file_path']);

            if ($isHit) {
                // Обновляем время последнего доступа
                async(function () use ($targetUrl) {
                    $stmt = $this->pdo->prepare("UPDATE cache_entries SET last_accessed_at = :time WHERE url = :url");
                    $stmt->execute(['time' => time(), 'url' => $targetUrl]);
                });

                $content = file_get_contents($cacheRow['file_path']);

                // Если это JSON, переписываем URL внутри
                if (str_contains($cacheRow['content_type'] ?? '', 'application/json')) {
                    $content = $this->rewriteJsonUrls($content, $request);
                }

                return new Response(200, [
                    'content-type' => $cacheRow['content_type'] ?: 'application/octet-stream',
                    'x-cache-status' => 'HIT',
                ], $content);
            }

            fwrite(STDERR, "[DEBUG] MISS: {$targetUrl}\n");

            // 2. Скачивание с upstream
            $clientRequest = new ClientRequest($targetUrl);
            $clientRequest->setHeader('User-Agent', 'Composer-Proxy/1.0');

            $upstreamResponse = $this->httpClient->request($clientRequest);
            $status = $upstreamResponse->getStatus();
            $contentType = $upstreamResponse->getHeader('content-type') ?: 'application/octet-stream';

            if ($status === 200) {
                $body = $upstreamResponse->getBody();

                // Определяем тип контента для формирования пути
                $type = str_contains($contentType, 'application/json') ? 'metadata' : 'archive';
                $finalPath = $this->getCachePath($targetUrl, $type);
                $tempPath = $finalPath . '.tmp';

                // Создаём иерархию директорий
                $dir = dirname($finalPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                // Стримим на диск
                $file = openFile($tempPath, 'w');
                $bytesWritten = 0;
                while (($chunk = $body->read()) !== null) {
                    $file->write($chunk);
                    $bytesWritten += strlen($chunk);
                }
                $file->close();

                rename($tempPath, $finalPath);
                fwrite(STDERR, "[DEBUG] Cached {$bytesWritten} bytes to {$finalPath}\n");

                // 3. КРИТИЧНО: Если это метаданные, парсим их и сохраняем маппинг архивов
                if ($type === 'metadata') {
                    $jsonContent = file_get_contents($finalPath);
                    $metadata = json_decode($jsonContent, true);

                    if ($metadata) {
                        $vendorPackage = null;

                        // Пытаемся получить vendor/package из URL (например, /p2/vendor/package.json)
                        if (preg_match('#/p2/([^/]+)/([^/]+?)(?:~[^/]+)?\.json#', $targetUrl, $m)) {
                            $vendorPackage = $m[1] . '/' . $m[2];
                        }

                        // Если не получилось из URL, берём из первой записи в packages
                        if (!$vendorPackage && isset($metadata['packages'])) {
                            $firstPackage = reset($metadata['packages']);
                            if (is_array($firstPackage) && !empty($firstPackage)) {
                                $firstVersion = reset($firstPackage);
                                $vendorPackage = $firstVersion['name'] ?? null;
                            }
                        }

                        // Если имя пакета определено, сохраняем маппинг всех его архивов
                        if ($vendorPackage && isset($metadata['packages'])) {
                            async(function () use ($metadata, $vendorPackage) {
                                try {
                                    $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO archive_mapping (archive_url, vendor_package) VALUES (:url, :pkg)");

                                    foreach ($metadata['packages'] as $packageName => $versions) {
                                        if (!is_array($versions)) continue;

                                        foreach ($versions as $version => $versionData) {
                                            if (!is_array($versionData) || !isset($versionData['dist']['url'])) continue;

                                            $archiveUrl = $versionData['dist']['url'];
                                            $stmt->execute([
                                                'url' => $archiveUrl,
                                                'pkg' => $vendorPackage
                                            ]);
                                        }
                                    }
                                    fwrite(STDERR, "[DEBUG] Saved archive mappings for {$vendorPackage}\n");
                                } catch (\Throwable $e) {
                                    fwrite(STDERR, "[DEBUG] Failed to save archive mapping: " . $e->getMessage() . "\n");
                                }
                            });
                        }
                    }
                }

                // 4. Сохраняем запись о файле в основную таблицу кэша
                $isArchive = $type === 'archive';
                $ttl = $isArchive ? $this->config['archive_ttl'] : $this->config['default_ttl'];
                $now = time();

                async(function () use ($targetUrl, $finalPath, $contentType, $ttl, $now) {
                    $stmt = $this->pdo->prepare("REPLACE INTO cache_entries (url, file_path, content_type, expires_at, created_at, last_accessed_at) VALUES (:url, :path, :ct, :exp, :now, :now)");
                    $stmt->execute([
                        'url' => $targetUrl, 'path' => $finalPath, 'ct' => $contentType,
                        'exp' => $now + $ttl, 'now' => $now
                    ]);
                });

                // 5. Формируем ответ (с переписанными URL для JSON)
                $content = file_get_contents($finalPath);
                if (str_contains($contentType, 'application/json')) {
                    $content = $this->rewriteJsonUrls($content, $request);
                }

                return new Response(200, [
                    'content-type' => $contentType,
                    'x-cache-status' => 'MISS',
                ], $content);
            } else {
                // Ошибка upstream
                if (isset($tempPath) && file_exists($tempPath)) {
                    unlink($tempPath);
                }
                return new Response($status, ['content-type' => 'text/plain', 'x-cache-status' => 'ERROR'], "Upstream returned {$status}");
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "[FATAL ERROR in handleProxy] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            return new Response(500, ['content-type' => 'text/plain'], "Proxy Error: " . $e->getMessage());
        }
    }

    /**
     * Обработчик скачивания архивов через прокси
     * Вызывается когда Composer запрашивает URL вида: /proxy?url=https://api.github.com/...
     */
    private function handleProxyDownload(Request $request): Response {
        parse_str($request->getUri()->getQuery(), $queryParams);
        $targetUrl = $queryParams['url'] ?? '';

        if (!$targetUrl) {
            return new Response(400, ['content-type' => 'text/plain'], 'Missing url parameter');
        }

        fwrite(STDERR, "[DEBUG] Proxy download request: {$targetUrl}\n");

        try {
            // Проверяем кэш
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

                fwrite(STDERR, "[DEBUG] Archive HIT: {$targetUrl}\n");
                $file = openFile($cacheRow['file_path'], 'r');
                return new Response(200, [
                    'content-type' => $cacheRow['content_type'] ?: 'application/octet-stream',
                    'x-cache-status' => 'HIT',
                ], $file);
            }

            fwrite(STDERR, "[DEBUG] Archive MISS: {$targetUrl}\n");

            // ПРОВЕРЯЕМ МАППИНГ: получаем composer-имя пакета для этого URL архива
            $mappingFuture = async(function () use ($targetUrl) {
                $stmt = $this->pdo->prepare("SELECT vendor_package FROM archive_mapping WHERE archive_url = :url");
                $stmt->execute(['url' => $targetUrl]);
                return $stmt->fetchColumn();
            });
            $vendorPackage = $mappingFuture->await();

            // Формируем путь с учётом маппинга
            if ($vendorPackage) {
                // Извлекаем хеш из URL
                $hash = null;
                if (preg_match('#/(?:zipball|legacy\.zip)/([^/]+)#', $targetUrl, $m)) {
                    $hash = $m[1];
                } else {
                    $hash = md5($targetUrl);
                }

                $finalPath = $this->config['cache_dir'] . '/' . $vendorPackage . '/' . $hash . '.zip';
                fwrite(STDERR, "[DEBUG] Using mapping: {$targetUrl} -> {$vendorPackage}\n");
            } else {
                // Если маппинга нет, используем стандартный путь
                $finalPath = $this->getCachePath($targetUrl, 'archive');
                fwrite(STDERR, "[DEBUG] No mapping found, using default path\n");
            }

            $tempPath = $finalPath . '.tmp';

            $dir = dirname($finalPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $clientRequest = new ClientRequest($targetUrl);
            $clientRequest->setHeader('User-Agent', 'Composer-Proxy/1.0');

            $upstreamResponse = $this->httpClient->request($clientRequest);
            $status = $upstreamResponse->getStatus();
            $contentType = $upstreamResponse->getHeader('content-type') ?: 'application/zip';

            if ($status === 200) {
                $body = $upstreamResponse->getBody();
                $file = openFile($tempPath, 'w');

                $bytesWritten = 0;
                while (($chunk = $body->read()) !== null) {
                    $file->write($chunk);
                    $bytesWritten += strlen($chunk);
                }
                $file->close();

                rename($tempPath, $finalPath);
                fwrite(STDERR, "[DEBUG] Downloaded {$bytesWritten} bytes to {$finalPath}\n");

                $ttl = $this->config['archive_ttl'];
                $now = time();

                async(function () use ($targetUrl, $finalPath, $contentType, $ttl, $now) {
                    $stmt = $this->pdo->prepare("REPLACE INTO cache_entries (url, file_path, content_type, expires_at, created_at, last_accessed_at) VALUES (:url, :path, :ct, :exp, :now, :now)");
                    $stmt->execute([
                        'url' => $targetUrl, 'path' => $finalPath, 'ct' => $contentType,
                        'exp' => $now + $ttl, 'now' => $now
                    ]);
                });

                $outFile = openFile($finalPath, 'r');
                return new Response(200, [
                    'content-type' => $contentType,
                    'x-cache-status' => 'MISS',
                ], $outFile);
            } else {
                if (file_exists($tempPath)) unlink($tempPath);
                return new Response($status, ['content-type' => 'text/plain', 'x-cache-status' => 'ERROR'], "Upstream returned {$status}");
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "[FATAL ERROR in handleProxyDownload] " . $e->getMessage() . "\n");
            return new Response(500, ['content-type' => 'text/plain'], "Proxy Download Error: " . $e->getMessage());
        }
    }

    /**
     * Переписывает URL в JSON-метаданных, заменяя прямые ссылки на GitHub
     * на ссылки через наш прокси
     */
    /**
     * Переписывает URL в JSON-метаданных
     */
    private function rewriteJsonUrls(string $content, Request $request): string {
        $data = json_decode($content, true);
        if (!$data) {
            fwrite(STDERR, "[DEBUG] JSON decode failed\n");
            return $content;
        }

        $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
        $replacedCount = 0;

        // Переписываем metadata-url в packages.json
        if (isset($data['metadata-url']) && is_string($data['metadata-url'])) {
            $original = $data['metadata-url'];
            $data['metadata-url'] = preg_replace('#https?://[^/]+#', $baseUrl, $original);
            if ($data['metadata-url'] !== $original) {
                fwrite(STDERR, "[DEBUG] Rewrote metadata-url: {$original} -> {$data['metadata-url']}\n");
                $replacedCount++;
            }
        }

        // Переписываем другие URL в packages.json
        $urlFields = ['providers-url', 'metadata-changes-url', 'search', 'list', 'providers-api'];
        foreach ($urlFields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $original = $data[$field];
                $data[$field] = preg_replace('#https?://[^/]+#', $baseUrl, $original);
                if ($data[$field] !== $original) {
                    $replacedCount++;
                }
            }
        }

        // Рекурсивно переписываем URL архивов
        $this->rewriteUrlsRecursive($data, $baseUrl, $replacedCount);

        if ($replacedCount > 0) {
            fwrite(STDERR, "[DEBUG] Total rewritten: {$replacedCount} URLs\n");
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function rewriteUrlsRecursive(mixed &$data, string $baseUrl, int &$count): void {
        if (is_array($data)) {
            foreach ($data as &$value) {
                $this->rewriteUrlsRecursive($value, $baseUrl, $count);
            }
        } elseif (is_string($data)) {
            if (str_contains($data, 'api.github.com') || str_contains($data, 'codeload.github.com')) {
                $data = $baseUrl . '/proxy?url=' . urlencode($data);
                $count++;
            }
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

// --- Запуск сервера ---
$server = new SocketHttpServer(
    $consoleLogger,
    new \Amp\Socket\ResourceServerSocketFactory(),
    new Amp\Http\Server\Driver\SocketClientFactory(new \Psr\Log\NullLogger())
);

$server->expose($config['listen']);

$errorHandler = new DefaultErrorHandler();
$handler = new ComposerProxyHandler($pdo, $httpClient, $config);

echo "🚀 Starting Composer Proxy on {$config['listen']}...\n";
$server->start($handler, $errorHandler);

trapSignal([2, 15]); // SIGINT, SIGTERM
echo "\n🛑 Shutting down gracefully...\n";
$server->stop();
