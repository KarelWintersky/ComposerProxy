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

    public function __construct(PDO $pdo, array $config) {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    public function handleRequest(Request $request): Response {
        parse_str($request->getUri()->getQuery(), $queryParams);

        // Проверка токена
        if (($queryParams['token'] ?? '') !== $this->config['stats_token']) {
            return new Response(403, ['content-type' => 'text/plain'], 'Forbidden: Invalid token');
        }

        // Получение данных из БД
        $stmt = $this->pdo->query("SELECT url, file_path, content_type, expires_at, created_at, last_accessed_at FROM cache_entries ORDER BY last_accessed_at DESC");
        $entries = $stmt->fetchAll();

        $totalSize = 0;
        $rows = [];

        foreach ($entries as $row) {
            $size = file_exists($row['file_path']) ? filesize($row['file_path']) : 0;
            $totalSize += $size;

            $info = $this->parsePackageInfo($row['url']);

            $rows[] = [
                'package' => htmlspecialchars($info['package']),
                'version' => htmlspecialchars($info['version']),
                'type' => str_contains($row['file_path'], '.zip') ? '📦 Archive' : '📄 Metadata',
                'size' => $size,
                'created' => $this->formatDate($row['created_at']),
                'accessed' => $this->formatDate($row['last_accessed_at']),
            ];
        }

        $html = $this->generateHtml($rows, $totalSize);

        return new Response(200, ['content-type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * Парсит URL и извлекает имя пакета и версию
     */
    private function parsePackageInfo(string $url): array {
        $package = 'Unknown';
        $version = 'N/A';

        if (preg_match('#/p2/([^/]+)/([^/]+?)(?:~([^/\.]+))?\.json#', $url, $m)) {
            $package = $m[1] . '/' . $m[2];
            $version = $m[3] ?: 'any';
        } elseif (preg_match('#/d/([^/]+)/([^/]+)/([^/]+)\.zip#', $url, $m)) {
            $package = $m[1] . '/' . $m[2];
            $version = substr($m[3], 0, 8); // Короткий хеш
        } elseif (preg_match('#api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/]+)#', $url, $m)) {
            $package = $m[1] . '/' . $m[2];
            $version = substr($m[3], 0, 8);
        } elseif (preg_match('#codeload\.github\.com/([^/]+)/([^/]+)/legacy\.zip/([^/]+)#', $url, $m)) {
            $package = $m[1] . '/' . $m[2];
            $version = substr($m[3], 0, 8);
        }

        return ['package' => $package, 'version' => $version];
    }

    /**
     * Форматирует размер файла в читаемый вид
     */
    private function formatBytes(int $bytes): string {
        if ($bytes === 0) return '0 B';
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, 3); // Ограничиваем до GB
        return round($bytes / (1024 ** $pow), 2) . ' ' . ['B', 'KB', 'MB', 'GB'][$pow];
    }

    /**
     * Форматирует timestamp в дату
     */
    private function formatDate(?int $timestamp): string {
        return $timestamp ? date('Y-m-d H:i', $timestamp) : 'N/A';
    }

    /**
     * Генерирует HTML-страницу
     */
    private function generateHtml(array $rows, int $totalSize): string {
        $formatBytes = fn($b) => $this->formatBytes($b);

        $html = "<!DOCTYPE html><html><head><title>Proxy Stats</title>
        <style>
            body{font-family:system-ui,sans-serif;padding:20px;background:#f8f9fa;} 
            table{border-collapse:collapse;width:100%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);} 
            th,td{padding:10px;text-align:left;border-bottom:1px solid #eee;} 
            th{background:#f1f3f5;color:#495057;}
        </style>
        </head><body>
        <h2>Composer Proxy Cache</h2>
        <p>Files: <b>" . count($rows) . "</b> | Total Size: <b>" . $formatBytes($totalSize) . "</b></p>
        <table>
            <tr>
                <th>Package</th>
                <th>Version</th>
                <th>Type</th>
                <th>Size</th>
                <th>Created</th>
                <th>Accessed</th>
            </tr>";

        foreach ($rows as $r) {
            $html .= "<tr>
                <td>{$r['package']}</td>
                <td>{$r['version']}</td>
                <td>{$r['type']}</td>
                <td>{$formatBytes($r['size'])}</td>
                <td>{$r['created']}</td>
                <td>{$r['accessed']}</td>
            </tr>";
        }
        $html .= "</table></body></html>";

        return $html;
    }
}