Сделали нейросетью скрипт билда через докер:

```shell

docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli sh -c "
    # 1. Установка системных зависимостей
    apt-get update && apt-get install -y git unzip curl libzip-dev && \
    docker-php-ext-install pcntl zip && \

    # 2. Исправление git ownership
    git config --global --add safe.directory /app && \

    # 3. Установка Composer
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \

    # 4. Установка Box
    curl -LSs https://github.com/box-project/box/releases/download/4.5.0/box.phar -o box.phar && \
    chmod +x box.phar && \

    # 5. Обновление lock-файла (на случай устаревания)
    php /usr/local/bin/composer update --lock --no-interaction && \

    # 6. Установка production-зависимостей
    php /usr/local/bin/composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction && \

    # 7. Сборка PHAR
    php box.phar compile && \

    # Прибираемся за собой
    rm box.phar && \

    # 8. Проверка
    echo '✅ PHAR готов!' && \
    ls -lh composer-proxy.phar
"
```
Он прекрасно всё билдит, но есть проблема - сборочная среда собирается каждый раз заново.

Поэтому переписали на два этапа:
- сборка образа билд-среды
- сборка phar

## Dockerfile.builder

```dockerfile
FROM php:8.3-cli

# Устанавливаем системные зависимости один раз
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    coreutils \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl zip

# Настраиваем Git для работы с монтированными томами
RUN git config --global --add safe.directory '*'

# Устанавливаем Composer глобально
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Устанавливаем Box глобально
RUN curl -LSs https://github.com/box-project/box/releases/download/4.5.0/box.phar -o /usr/local/bin/box \
    && chmod +x /usr/local/bin/box

WORKDIR /app

# Команда по умолчанию: сборка PHAR
CMD ["sh", "-c", "composer update --lock --no-interaction && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction && box compile"]
```

Билдим образ:

```shell
docker build -f Dockerfile.builder -t composer-proxy-builder .
```

Docker скачает базовый образ, установит все библиотеки, Composer и Box, и сохранит это как локальный образ composer-proxy-builder.

Теперь собираем:

```shell
docker run --rm -v "$(pwd)":/app composer-proxy-builder
```

```shell

# Запускаем сборку, передавая текущий UID и GID
docker run --rm \
    -v "$(pwd)":/app \
    -e HOST_UID=$(id -u) \
    -e HOST_GID=$(id -g) \
    composer-proxy-builder sh -c "
        composer update --lock --no-interaction && \
        composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction && \
        box compile && \
        chown ${HOST_UID}:${HOST_GID} /app/composer-proxy.phar
    "

```

```shell
#!/bin/bash
set -e

echo "🔨 Building Composer Proxy PHAR..."

# Определяем текущего пользователя
CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

docker run --rm \
    -v "$(pwd)":/app \
    -e HOST_UID=$CURRENT_UID \
    -e HOST_GID=$CURRENT_GID \
    composer-proxy-builder sh -c "
        echo '📦 Installing dependencies...' && \
        composer update --lock --no-interaction && \
        composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction && \
        echo '📦 Compiling PHAR...' && \
        box compile && \
        echo '🔧 Fixing permissions...' && \
        chown \${HOST_UID}:\${HOST_GID} /app/composer-proxy.phar && \
        echo '✅ Done!'
    "

ls -lh composer-proxy.phar
```

Но на самом деле сделали скрипт `build.sh` который и собирает образ, и компилирует
