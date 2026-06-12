<?php
declare(strict_types=1);

return [
    'listen' => '0.0.0.0:8080',

    // Основной upstream для относительных путей (например, /p2/vendor/package.json)
    'default_upstream' => 'https://repo.packagist.org',

    // Директория для сохранения скачанных файлов (.zip, .json и т.д.)
    'cache_dir' => __DIR__ . '/cache',

    // Путь к файлу базы данных SQLite
    'db_path' => __DIR__ . '/cache.sqlite',

    // Время жизни кэша по умолчанию (в секундах). 1 час.
    // Для .zip и .tar архивов прокси автоматически установит TTL в 1 год,
    // так как они привязаны к хешу коммита и неизменяемы.
    'default_ttl' => 3600,

    'archive_ttl' => 31536000, // 1 год для .zip/.tar

    // Простой токен для доступа к странице статистики (задайте при установке)
    'stats_token' => 'wombat',
];
