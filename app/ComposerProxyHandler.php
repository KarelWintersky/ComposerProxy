<?php

namespace App;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\File\File;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\File\openFile;
use function Amp\async;
use function Amp\trapSignal;

class ComposerProxyHandler
{
    private PDO $pdo;
    private object $httpClient;
    private array $config;

    public function __construct(PDO $pdo, object $httpClient, array $config)
    {
        $this->pdo = $pdo;
        $this->httpClient = $httpClient;
        $this->config = $config;
    }

    /**
     * Отдаёт файл из кэша по его оригинальному URL.
     * Используется для скачивания архивов прямо из страницы статистики.
     */
    public function handleDownload(Request $request): Response
    {
        parse_str($request->getUri()->getQuery(), $queryParams);
        $url = $queryParams['url'] ?? '';

        if (empty($url)) {
            return new Response(400, ['content-type' => 'text/plain'], 'Missing url parameter');
        }

        try {
            $stmt = $this->pdo->prepare("SELECT file_path, content_type FROM cache_entries WHERE url = :url");
            $stmt->execute(['url' => $url]);
            $row = $stmt->fetch();

            if (!$row || !file_exists($row['file_path'])) {
                return new Response(404, ['content-type' => 'text/plain'], 'File not found in cache');
            }

            // Безопасность: проверяем, что файл находится внутри cache_dir
            $realPath = realpath($row['file_path']);
            $cacheDir = realpath($this->config['cache_dir']);
            if ($realPath === false || $cacheDir === false || !str_starts_with($realPath, $cacheDir)) {
                return new Response(403, ['content-type' => 'text/plain'], 'Access denied');
            }

            // Обновляем время доступа
            async(function () use ($url) {
                $stmt = $this->pdo->prepare("UPDATE cache_entries SET last_accessed_at = :time WHERE url = :url");
                $stmt->execute(['time' => time(), 'url' => $url]);
            });

            $file = openFile($row['file_path'], 'r');
            return new Response(200, [
                'content-type' => $row['content_type'] ?: 'application/octet-stream',
                'content-disposition' => 'attachment; filename="' . basename($row['file_path']) . '"',
                'x-cache-status' => 'HIT',
            ], $file);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[FATAL ERROR in handleDownload] " . $e->getMessage() . "\n");
            return new Response(500, ['content-type' => 'text/plain'], "Download Error: " . $e->getMessage());
        }
    }

    /**
     * Извлекает vendor/package из URL и формирует путь к файлу
     */
    /**
     * Извлекает vendor/package из URL и формирует путь к файлу
     */
    private function getCachePath(string $url, string $type): string {
        // Корневой packages.json
        if (preg_match('~/packages\.json$~', $url)) {
            return $this->config['cache_dir'] . '/package.json';
        }

        $vendor = 'unknown';
        $package = 'unknown';
        $hash = null;

        if (preg_match('~/p2/([^/]+)/([^/]+?)(?:\~[^/]+)?\.json~', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
        } elseif (preg_match('~/d/([^/]+)/([^/]+)/([^/]+)\.zip~', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
            $hash = $m[3];
        } elseif (preg_match('~api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/]+)~', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
            $hash = $m[3];
        } elseif (preg_match('~codeload\.github\.com/([^/]+)/([^/]+)/legacy\.zip/([^/]+)~', $url, $m)) {
            $vendor = $m[1];
            $package = $m[2];
            $hash = $m[3];
        }

        if ($type === 'metadata') {
            return $this->config['cache_dir'] . '/' . $vendor . '/' . $package . '/package.json';
        } else {
            return $this->config['cache_dir'] . '/' . $vendor . '/' . $package . '/' . $hash . '.zip';
        }
    }

    public function handleProxy(Request $request): Response
    {
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
                async(function () use ($targetUrl) {
                    $stmt = $this->pdo->prepare("UPDATE cache_entries SET last_accessed_at = :time WHERE url = :url");
                    $stmt->execute(['time' => time(), 'url' => $targetUrl]);
                });

                $content = file_get_contents($cacheRow['file_path']);

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

                $type = str_contains($contentType, 'application/json') ? 'metadata' : 'archive';
                $finalPath = $this->getCachePath($targetUrl, $type);
                $tempPath = $finalPath . '.tmp';

                $dir = dirname($finalPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                $file = openFile($tempPath, 'w');
                $bytesWritten = 0;
                while (($chunk = $body->read()) !== null) {
                    $file->write($chunk);
                    $bytesWritten += strlen($chunk);
                }
                $file->close();

                rename($tempPath, $finalPath);
                fwrite(STDERR, "[DEBUG] Cached {$bytesWritten} bytes to {$finalPath}\n");

                // 3. Определяем composer-имя и версию для метаданных
                $composerPackage = '';
                $packageVersion = '';
                $reference = '';
                $sourceUrl = '';

                if ($type === 'metadata') {
                    // Корневой packages.json
                    if (preg_match('~/packages\.json$~', $targetUrl)) {
                        $composerPackage = 'packagist.org';
                        $packageVersion = 'root';
                    }
                    // Метаданные пакета: /p2/vendor/package.json или /p2/vendor/package~version.json
                    elseif (preg_match('~/p2/([^/]+)/([^/]+?)(?:\~([^/\.]+))?\.json~', $targetUrl, $m)) {
                        $composerPackage = $m[1] . '/' . $m[2];
                        $packageVersion = $m[3] ?? 'any';
                    }

                    // Парсим JSON и сохраняем маппинг архивов
                    $jsonContent = file_get_contents($finalPath);
                    $metadata = json_decode($jsonContent, true);

                    if ($metadata && isset($metadata['packages'])) {
                        if (empty($composerPackage)) {
                            $firstPackage = reset($metadata['packages']);
                            if (is_array($firstPackage) && !empty($firstPackage)) {
                                $firstVersion = reset($firstPackage);
                                $composerPackage = $firstVersion['name'] ?? '';
                            }
                        }

                        // СОХРАНЯЕМ МАППИНГ СИНХРОННО
                        if (!empty($composerPackage)) {
                            try {
                                $stmt = $this->pdo->prepare("
                                    INSERT OR REPLACE INTO archive_mapping 
                                    (archive_url, vendor_package, version, reference, source_url) 
                                    VALUES (:url, :pkg, :ver, :ref, :src)
                                ");

                                $mappingCount = 0;
                                foreach ($metadata['packages'] as $pkgName => $versions) {
                                    if (!is_array($versions)) continue;

                                    foreach ($versions as $version => $versionData) {
                                        if (!is_array($versionData)) continue;

                                        $archiveUrl = $versionData['dist']['url'] ?? '';
                                        $ref = $versionData['source']['reference'] ?? '';
                                        $src = $versionData['source']['url'] ?? '';
                                        $ver = $versionData['version'] ?? $version;

                                        if (empty($archiveUrl)) continue;

                                        $stmt->execute([
                                            'url' => $archiveUrl,
                                            'pkg' => $composerPackage,
                                            'ver' => $ver,
                                            'ref' => $ref,
                                            'src' => $src,
                                        ]);
                                        $mappingCount++;
                                    }
                                }
                                fwrite(STDERR, "[DEBUG] Saved {$mappingCount} archive mappings for {$composerPackage}\n");
                            } catch (\Throwable $e) {
                                fwrite(STDERR, "[DEBUG] Failed to save archive mapping: " . $e->getMessage() . "\n");
                            }
                        }
                    }
                }

                // 4. Сохраняем запись в cache_entries
                $isArchive = $type === 'archive';
                $ttl = $isArchive ? $this->config['archive_ttl'] : $this->config['default_ttl'];
                $now = time();

                async(function () use ($targetUrl, $finalPath, $contentType, $ttl, $now, $composerPackage, $packageVersion, $reference, $sourceUrl) {
                    $stmt = $this->pdo->prepare("
                        REPLACE INTO cache_entries 
                        (url, file_path, content_type, expires_at, created_at, last_accessed_at, 
                         composer_package, package_version, reference, source_url) 
                        VALUES (:url, :path, :ct, :exp, :now, :now, :pkg, :ver, :ref, :src)
                    ");
                    $stmt->execute([
                        'url' => $targetUrl,
                        'path' => $finalPath,
                        'ct' => $contentType,
                        'exp' => $now + $ttl,
                        'now' => $now,
                        'pkg' => $composerPackage,
                        'ver' => $packageVersion,
                        'ref' => $reference,
                        'src' => $sourceUrl,
                    ]);
                });

                // 5. Формируем ответ
                $content = file_get_contents($finalPath);
                if (str_contains($contentType, 'application/json')) {
                    $content = $this->rewriteJsonUrls($content, $request);
                }

                return new Response(200, [
                    'content-type' => $contentType,
                    'x-cache-status' => 'MISS',
                ], $content);
            } else {
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

    public function handleProxyDownload(Request $request): Response {
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

            // Получаем ВСЕ данные из маппинга
            $mappingFuture = async(function () use ($targetUrl) {
                $stmt = $this->pdo->prepare("
                    SELECT vendor_package, version, reference, source_url 
                    FROM archive_mapping WHERE archive_url = :url
                ");
                $stmt->execute(['url' => $targetUrl]);
                return $stmt->fetch();
            });
            $mappingData = $mappingFuture->await();

            if ($mappingData) {
                $shortRef = !empty($mappingData['reference']) ? substr($mappingData['reference'], 0, 8) : '(none)';
                fwrite(STDERR, "[DEBUG] Mapping found: pkg={$mappingData['vendor_package']}, ver={$mappingData['version']}, ref={$shortRef}\n");
            } else {
                fwrite(STDERR, "[DEBUG] WARNING: No mapping found for {$targetUrl}\n");
                $countStmt = $this->pdo->query("SELECT COUNT(*) FROM archive_mapping");
                $count = $countStmt->fetchColumn();
                fwrite(STDERR, "[DEBUG] Total mappings in DB: {$count}\n");
            }

            $composerPackage = $mappingData['vendor_package'] ?? '';
            $packageVersion = $mappingData['version'] ?? '';
            $reference = $mappingData['reference'] ?? '';
            $sourceUrl = $mappingData['source_url'] ?? '';

            // Извлекаем хеш из URL архива
            $hash = '';
            if (preg_match('~/(?:zipball|legacy\.zip)/([^/?#]+)~', $targetUrl, $m)) {
                $hash = $m[1];
            }
            if (empty($reference)) {
                $reference = $hash;
            }

            // Формируем путь
            if (!empty($composerPackage)) {
                $filename = !empty($reference) ? $reference : ($hash ?: md5($targetUrl));
                $finalPath = $this->config['cache_dir'] . '/' . $composerPackage . '/' . $filename . '.zip';
                fwrite(STDERR, "[DEBUG] Using mapping: {$targetUrl} -> {$composerPackage} v{$packageVersion}\n");
            } else {
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

                async(function () use ($targetUrl, $finalPath, $contentType, $ttl, $now, $composerPackage, $packageVersion, $reference, $sourceUrl) {
                    $stmt = $this->pdo->prepare("
                        REPLACE INTO cache_entries 
                        (url, file_path, content_type, expires_at, created_at, last_accessed_at, 
                         composer_package, package_version, reference, source_url) 
                        VALUES (:url, :path, :ct, :exp, :now, :now, :pkg, :ver, :ref, :src)
                    ");
                    $stmt->execute([
                        'url' => $targetUrl,
                        'path' => $finalPath,
                        'ct' => $contentType,
                        'exp' => $now + $ttl,
                        'now' => $now,
                        'pkg' => $composerPackage,
                        'ver' => $packageVersion,
                        'ref' => $reference,
                        'src' => $sourceUrl,
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
            fwrite(STDERR, "[FATAL ERROR in handleProxyDownload] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
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