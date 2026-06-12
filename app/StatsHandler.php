<?php

declare(strict_types=1);

namespace App;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use PDO;

class StatsHandler implements RequestHandler
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function handleRequest(Request $request): Response
    {
        parse_str($request->getUri()->getQuery(), $queryParams);

        if (($queryParams['token'] ?? '') !== $this->config['stats_token']) {
            return new Response(403, ['content-type' => 'text/plain'], 'Forbidden: Invalid token');
        }

        $stmt = $this->pdo->query("
            SELECT url, file_path, content_type, expires_at, created_at, last_accessed_at,
                   composer_package, package_version, reference, source_url 
            FROM cache_entries 
            ORDER BY last_accessed_at DESC
        ");
        $entries = $stmt->fetchAll();

        $totalSize = 0;
        $packages = [];

        foreach ($entries as $row) {
            $size = file_exists($row['file_path']) ? filesize($row['file_path']) : 0;
            $totalSize += $size;

            // Используем composer-имя из БД, fallback на парсинг URL
            $pkgName = !empty($row['composer_package'])
                ? $row['composer_package']
                : $this->parsePackageName($row['url']);

            $version = !empty($row['package_version']) ? $row['package_version'] : 'unknown';
            $sourceUrl = $row['source_url'] ?? '';
            $reference = $row['reference'] ?? '';
            $type = str_contains($row['file_path'], '.zip') ? 'archive' : 'metadata';

            if (!isset($packages[$pkgName])) {
                $packages[$pkgName] = [
                    'metadata_size' => 0,
                    'metadata_created' => 0,
                    'metadata_accessed' => 0,
                    'source_url' => $sourceUrl,
                    'versions' => []
                ];
            }

            // Обновляем source_url, если он появился
            if (!empty($sourceUrl) && empty($packages[$pkgName]['source_url'])) {
                $packages[$pkgName]['source_url'] = $sourceUrl;
            }

            if ($type === 'metadata') {
                $packages[$pkgName]['metadata_size'] += $size;
                $packages[$pkgName]['metadata_created'] = max($packages[$pkgName]['metadata_created'], $row['created_at']);
                $packages[$pkgName]['metadata_accessed'] = max($packages[$pkgName]['metadata_accessed'], $row['last_accessed_at']);
            } else {
                if (!isset($packages[$pkgName]['versions'][$version])) {
                    $packages[$pkgName]['versions'][$version] = [
                        'size' => 0,
                        'created' => 0,
                        'accessed' => 0,
                        'reference' => $reference,
                    ];
                }
                $packages[$pkgName]['versions'][$version]['size'] += $size;
                $packages[$pkgName]['versions'][$version]['created'] = max($packages[$pkgName]['versions'][$version]['created'], $row['created_at']);
                $packages[$pkgName]['versions'][$version]['accessed'] = max($packages[$pkgName]['versions'][$version]['accessed'], $row['last_accessed_at']);
                // Обновляем reference, если он появился позже
                if (!empty($reference) && empty($packages[$pkgName]['versions'][$version]['reference'])) {
                    $packages[$pkgName]['versions'][$version]['reference'] = $reference;
                }
            }
        }

        // Сортируем версии внутри пакета по дате доступа
        foreach ($packages as &$pkg) {
            uasort($pkg['versions'], function ($a, $b) {
                return $b['accessed'] <=> $a['accessed'];
            });
        }
        unset($pkg);

        // Сортируем пакеты: packagist.org первым, остальные по алфавиту
        $rootPackage = null;
        if (isset($packages['packagist.org'])) {
            $rootPackage = ['packagist.org' => $packages['packagist.org']];
            unset($packages['packagist.org']);
        }

        ksort($packages, SORT_STRING);

        if ($rootPackage) {
            $packages = $rootPackage + $packages;
        }

        $html = $this->generateHtml($packages, $totalSize);

        return new Response(200, ['content-type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Fallback: парсит имя пакета из URL, если composer_package пуст
     */
    private function parsePackageName(string $url): string
    {
        if (preg_match('#/packages\.json$#', $url)) {
            return 'packagist.org';
        }
        if (preg_match('#/p2/([^/]+)/([^/]+?)(?:~[^/]+)?\.json#', $url, $m)) {
            return $m[1] . '/' . $m[2];
        }
        if (preg_match('#/d/([^/]+)/([^/]+)/([^/]+)\.zip#', $url, $m)) {
            return $m[1] . '/' . $m[2];
        }
        return 'unknown/unknown';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, 3);
        return round($bytes / (1024 ** $pow), 2) . ' ' . ['B', 'KB', 'MB', 'GB'][$pow];
    }

    private function formatDate(?int $timestamp): string
    {
        return $timestamp && $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : 'N/A';
    }

    private function generateHtml(array $packages, int $totalSize): string
    {
        $formatBytes = fn($b) => $this->formatBytes($b);
        $formatDate = fn($d) => $this->formatDate($d);

        $html = "<!DOCTYPE html><html><head><title>Proxy Stats</title>
        <style>
            body { font-family: system-ui, -apple-system, sans-serif; padding: 20px; background: #f8f9fa; color: #333; }
            h2 { margin-bottom: 5px; }
            .summary { margin-bottom: 20px; color: #666; }
            table { border-collapse: collapse; width: 100%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.1); border-radius: 4px; overflow: hidden; }
            th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #eee; }
            th { background: #f1f3f5; color: #495057; font-weight: 600; font-size: 14px; }
            
            .pkg-row { background: #e9ecef; font-weight: 600; }
            .pkg-row td { border-bottom: 1px solid #dee2e6; }
            .pkg-name { color: #228be6; font-family: monospace; font-size: 15px; }
            .pkg-repo { display: block; font-size: 11px; color: #868e96; font-weight: normal; margin-top: 2px; }
            .pkg-repo a { color: #868e96; text-decoration: none; }
            .pkg-repo a:hover { text-decoration: underline; }
            
            .ver-row { background: #fff; }
            .ver-row:hover { background: #f1f3f5; }
            .ver-name { padding-left: 30px; color: #495057; font-family: monospace; }
            .ver-name::before { content: '└─ '; color: #adb5bd; }
            .ver-ref { display: block; font-size: 10px; color: #adb5bd; margin-left: 30px; font-family: monospace; }
            
            .type-meta { color: #e67700; font-size: 13px; }
            .type-arch { color: #2b8a3e; font-size: 13px; }
        </style>
        </head><body>
        <h2>📦 Composer Proxy Cache</h2>
        <div class=\"summary\">Total Packages: <b>" . count($packages) . "</b> | Total Size: <b>" . $formatBytes($totalSize) . "</b></div>
        
        <table>
            <thead>
                <tr>
                    <th style=\"width: 35%\">Package / Version</th>
                    <th style=\"width: 15%\">Type</th>
                    <th style=\"width: 15%\">Size</th>
                    <th style=\"width: 15%\">Created</th>
                    <th style=\"width: 20%\">Last Accessed</th>
                </tr>
            </thead>
            <tbody>";

        foreach ($packages as $pkgName => $data) {
            // Ссылка на репозиторий
            $repoUrl = $data['source_url'] ?? '';
            $repoWebUrl = '';
            if (!empty($repoUrl)) {
                $repoWebUrl = preg_replace('#^git://#', 'https://', $repoUrl);
                $repoWebUrl = preg_replace('#\.git$#', '', $repoWebUrl);
            }
            $repoLink = $repoWebUrl
                ? "<span class=\"pkg-repo\"><a href=\"{$repoWebUrl}\" target=\"_blank\">{$repoWebUrl}</a></span>"
                : '';

            $html .= "<tr class=\"pkg-row\">
                <td>
                    <span class=\"pkg-name\">{$pkgName}</span>
                    {$repoLink}
                </td>
                <td><span class=\"type-meta\">📄 Metadata</span></td>
                <td>" . $formatBytes($data['metadata_size']) . "</td>
                <td>" . $formatDate($data['metadata_created']) . "</td>
                <td>" . $formatDate($data['metadata_accessed']) . "</td>
            </tr>";

            if (!empty($data['versions'])) {
                foreach ($data['versions'] as $verName => $verData) {
                    $ref = $verData['reference'] ?? '';
                    $refShort = !empty($ref) ? substr($ref, 0, 8) : '';
                    $refLine = !empty($refShort) ? "<span class=\"ver-ref\">ref: {$refShort}</span>" : '';

                    $html .= "<tr class=\"ver-row\">
                        <td>
                            <span class=\"ver-name\">{$verName}</span>
                            {$refLine}
                        </td>
                        <td><span class=\"type-arch\">📦 Archive</span></td>
                        <td>" . $formatBytes($verData['size']) . "</td>
                        <td>" . $formatDate($verData['created']) . "</td>
                        <td>" . $formatDate($verData['accessed']) . "</td>
                    </tr>";
                }
            } else {
                $html .= "<tr class=\"ver-row\">
                    <td><span class=\"ver-name\" style=\"color:#adb5bd;\">(no cached archives yet)</span></td>
                    <td colspan=\"4\" style=\"color:#adb5bd; font-style:italic;\">Only metadata is cached</td>
                </tr>";
            }
        }

        $html .= "</tbody></table></body></html>";

        return $html;
    }
}