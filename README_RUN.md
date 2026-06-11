```shell

php setup_database.php ./config.php

php server.php

```


```
server {
    listen 80;
    server_name composer-proxy.local; # Или ваш IP/домен

    root /var/www/composer-proxy;
    index index.php;

    client_max_body_size 50M; # На случай больших архивов

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Проверьте путь к вашему сокету PHP 8.2
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Увеличиваем таймауты для FPM, так как скачивание может занять время
        fastcgi_read_timeout 120s;
        fastcgi_send_timeout 120s;
    }

    # Запрещаем прямой доступ к файлам кэша и БД извне
    location ~ ^/(cache|cache\.db) {
        deny all;
    }
}

```

OR

```
php -S 0.0.0.0:8080 -t /var/www.projects/ComposerProxy index.php
```

Глобальная настройка:

```
composer config -g repositories.packagist.org composer http://composer-proxy.local
```

Для проекта

```
{
    "repositories": [
        {
            "type": "composer",
            "url": "http://composer-proxy.local"
        }
    ]
}
```