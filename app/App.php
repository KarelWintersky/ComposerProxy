<?php

namespace App;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

class App implements RequestHandler
{
    private static ?self $instance = null;

    // Конфигурация
    private array $config = [];

    // Флаги запуска
    public bool $isVerbose = false;
    public bool $isDebug = false;

    public ?string $configPath = null;

    // Зависимости (ленивая инициализация)
    private ?StatsHandler $statsHandler = null;
    private ?ComposerProxyHandler $proxyHandler = null;
    private ?\PDO $pdo = null;

    private function __construct() {}

    /**
     * Получение синглтона
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Инициализация приложения (вызывается один раз в server.php)
     */
    public function init(array $argv): void
    {
        $this->parseArgs($argv);
        $this->loadConfig();

        if ($this->isDebug) {
            fwrite(STDERR, "[APP] Debug mode enabled\n");
            fwrite(STDERR, "[APP] Config loaded from: {$this->configPath}\n");
        }
    }

    /**
     * @return bool
     */
    public function isDebug(): bool { return $this->isDebug; }

    /**
     * @return bool
     */
    public function isVerbose(): bool { return $this->isVerbose; }

    /**
     * @return array
     */
    public function getConfig(): array { return $this->config; }

    /**
     * @return string
     */
    public function getConfigPath(): string { return $this->configPath ?? ''; }

    /**
     * @param string $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function getConfigValue(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    /**
     * Парсинг аргументов CLI
     */
    private function parseArgs(array $argv): void
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--config=')) {
                $this->configPath = substr($arg, strlen('--config='));
            } elseif ($arg === '--verbose') {
                $this->isVerbose = true;
            } elseif ($arg === '--debug') {
                $this->isDebug = true;
            }
        }
    }

    /**
     * Загрузка и валидация конфига
     */
    private function loadConfig(): void
    {
        // Логика поиска конфига (ENV -> /etc -> рядом с PHAR)
        $path = $this->configPath ?? getenv('COMPOSER_PROXY_CONFIG') ?: null;

        if (!$path) {
            $candidates = [
                '/etc/composer-proxy/config.php',
                dirname(\Phar::running(false) ?: __FILE__) . '/config.php',
            ];
            foreach ($candidates as $c) {
                if (file_exists($c)) { $path = $c; break; }
            }
        }

        if (!$path || !file_exists($path)) {
            fwrite(STDERR, "❌ Config not found. Use --config=/path/to/config.php\n");
            exit(1);
        }

        $this->config = require $path;
        $this->configPath = $path;

        // Базовая валидация
        foreach (['listen', 'db_path', 'cache_dir'] as $key) {
            if (!isset($this->config[$key])) {
                fwrite(STDERR, "❌ Missing config key: {$key}\n");
                exit(1);
            }
        }
    }


    /**
     * Получение PDO (ленивое подключение)
     */
    public function getPDO(): \PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new \PDO('sqlite:' . $this->config['db_path'], null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                $this->pdo->exec('PRAGMA journal_mode = WAL;');
                $this->pdo->exec('PRAGMA busy_timeout = 5000;');
            } catch (\PDOException $e) {
                throw new \RuntimeException("DB Connection failed: " . $e->getMessage());
            }
        }
        return $this->pdo;
    }

    /**
     * Главный роутер
     */
    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();

        // Внутренние служебные пути
        if ($path === '/health') {
            return new Response(200, ['content-type' => 'application/json'], json_encode(['status' => 'ok']));
        }

        // Маршруты приложения
        if ($path === '/stats') {
            return $this->getStatsHandler()->handleRequest($request);
        }

        if ($path === '/proxy') {
            return $this->getProxyHandler()->handleProxyDownload($request);
        }

        if ($path === '/download') {
            return $this->proxyHandler->handleDownload($request);
        }

        // Основной прокси (default)
        return $this->getProxyHandler()->handleProxy($request);
    }

    // --- Ленивая загрузка хендлеров ---
    private function getStatsHandler(): StatsHandler
    {
        if ($this->statsHandler === null) {
            $this->statsHandler = new StatsHandler($this->getPDO(), $this->config);
        }
        return $this->statsHandler;
    }

    private function getProxyHandler(): ComposerProxyHandler
    {
        if ($this->proxyHandler === null) {
            $httpClient = \Amp\Http\Client\HttpClientBuilder::buildDefault();
            $logger = new ConsoleLogger();

            $this->proxyHandler = new ComposerProxyHandler($this->getPDO(), $httpClient, $this->config, $logger);
        }
        return $this->proxyHandler;
    }

    public static function setup()
    {

    }
}