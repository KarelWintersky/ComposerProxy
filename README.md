### Как это работает на практике

| Сценарий | Что происходит |
|---|---|
| **Production (systemd)** | `Environment=COMPOSER_PROXY_CONFIG=/etc/composer-proxy/config.php` → конфиг из `/etc/` |
| **Локальная разработка** | Переменной нет → берётся `__DIR__ . '/config.php'` рядом с `server.php` |
| **Ручной запуск с другим конфигом** | `COMPOSER_PROXY_CONFIG=/tmp/test-config.php php server.php` |
| **DEB-пакет** | `postinst` кладёт конфиг в `/etc/composer-proxy/config.php`, юнит systemd подхватывает его автоматически |

http://127.0.0.1:8080/stats?token=wombat
