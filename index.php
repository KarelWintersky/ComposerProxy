<?php
declare(strict_types=1);

// 1. Загрузка конфигурации
$config = require __DIR__ . '/config.php';

// 2. Инициализация директории кэша
if (!is_dir($config['cache_dir'])) {
    mkdir($config['cache_dir'], 0755, true);
}

// 3. Инициализация SQLite с настройками для высокой конкурентности (WAL mode)
$dsn = 'sqlite:' . $config['db_path'];
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
// Включаем WAL (Write-Ahead Logging) для безопасных параллельных чтений/записей
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA synchronous = NORMAL;');

// Создаем таблицу, если её нет
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cache_entries (
        url TEXT PRIMARY KEY,
        file_path TEXT NOT NULL,
        content_type TEXT,
        expires_at INTEGER NOT NULL
    )
");

// 4. Обработка запроса
$requestedUri = $_SERVER['REQUEST_URI'] ?? '/';

// Определяем целевой URL. Если запрос абсолютный (начинается с http), используем его.
// Иначе добавляем default_upstream (для совместимости с тем, как Composer формирует запросы к репозиторию).
$targetUrl = str_starts_with($requestedUri, 'http')
    ? $requestedUri
    : rtrim($config['default_upstream'], '/') . $requestedUri;

// 5. Проверка кэша
$stmt = $pdo->prepare("SELECT file_path, content_type, expires_at FROM cache_entries WHERE url = :url");
$stmt->execute(['url' => $targetUrl]);
$cacheRow = $stmt->fetch();

$isHit = $cacheRow && time() < $cacheRow['expires_at'] && file_exists($cacheRow['file_path']);

if ($isHit) {
    // Отдаем из кэша
    header("Content-Type: " . ($cacheRow['content_type'] ?: 'application/octet-stream'));
    header("X-Cache-Status: HIT");
    header("X-Cache-Url: " . $targetUrl);

    // Отдаем файл эффективно, не загружая его целиком в память PHP
    readfile($cacheRow['file_path']);
    exit;
}

// 6. Запрос к upstream (MISS)
$tempFilePath = $config['cache_dir'] . '/' . md5($targetUrl) . '.tmp';
$finalFilePath = $config['cache_dir'] . '/' . md5($targetUrl);

$ch = curl_init();
$fp = fopen($tempFilePath, 'w+');

curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_FILE => $fp, // Стримим ответ напрямую на диск
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    // Packagist и GitHub могут требовать User-Agent. Указываем корректный.
    CURLOPT_USERAGENT => 'Composer-Package-Proxy/1.0 (+https://github.com/your-org/composer-proxy)',
    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
        $responseHeaders[] = $header;
        return strlen($header);
    },
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlError = curl_error($ch);

fclose($fp);
curl_close($ch);

// 7. Обработка ответа
if ($httpCode === 200 && $response !== false) {
    // Переименовываем временный файл в постоянный (атомарная операция)
    rename($tempFilePath, $finalFilePath);

    // Определяем TTL: архивы кэшируем надолго, остальное (json) - по умолчанию
    $isArchive = str_contains($targetUrl, '.zip') || str_contains($targetUrl, '.tar') || str_contains($targetUrl, 'codeload.github.com');
    $ttl = $isArchive ? 31536000 : $config['default_ttl']; // 1 год для архивов

    // Сохраняем метаданные в SQLite (REPLACE обновит запись, если URL уже был)
    $stmt = $pdo->prepare("
        REPLACE INTO cache_entries (url, file_path, content_type, expires_at) 
        VALUES (:url, :file_path, :content_type, :expires_at)
    ");
    $stmt->execute([
        'url' => $targetUrl,
        'file_path' => $finalFilePath,
        'content_type' => $contentType,
        'expires_at' => time() + $ttl,
    ]);

    header("Content-Type: " . ($contentType ?: 'application/octet-stream'));
    header("X-Cache-Status: MISS");
    header("X-Cache-Url: " . $targetUrl);

    readfile($finalFilePath);
} else {
    // Если upstream вернул ошибку (404, 403 Rate Limit и т.д.), удаляем временный файл и проксируем ошибку
    if (file_exists($tempFilePath)) {
        unlink($tempFilePath);
    }

    http_response_code($httpCode ?: 502);
    header("Content-Type: text/plain");
    header("X-Cache-Status: ERROR");
    echo $curlError ?: "Upstream returned HTTP {$httpCode}";
}
