# composer-proxy.service

Этот файл должен быть включен в DEB-пакет и установлен в /lib/systemd/system/.

```unit file (systemd)
[Unit]
Description=Composer Packagist Caching Proxy
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
ExecStart=/usr/bin/php /usr/bin/composer-proxy-server
Restart=on-failure
RestartSec=5

# Безопасность
ProtectSystem=full
ProtectHome=true
ReadWritePaths=/var/lib/composer-proxy /var/log/composer-proxy
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

# debian/postinst

```
#!/bin/sh
set -e

# Создание пользователя, если его нет (опционально, можно использовать www-data)
# adduser --system --group --no-create-home composer-proxy

# Запуск скрипта инициализации БД
php /usr/bin/composer-proxy-setup /etc/composer-proxy/config.php

# Установка правильных прав
chown -R www-data:www-data /var/lib/composer-proxy
chown -R www-data:www-data /var/log/composer-proxy
chmod 755 /var/lib/composer-proxy
chmod 600 /etc/composer-proxy/config.php # Скрываем токен статистики

# Перезапуск или включение сервиса
systemctl daemon-reload
systemctl enable composer-proxy
systemctl restart composer-proxy

#DEBHELPER#
exit 0
```

