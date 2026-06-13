#!/bin/bash
set -e

echo "🔨 Building Composer Proxy PHAR..."

# 1. Создаем временный Dockerfile
DOCKERFILE_CONTENT=$(cat <<'EOF'
FROM php:8.3-cli

RUN apt-get update && apt-get install -y git unzip curl libzip-dev coreutils && \
    docker-php-ext-install pcntl zip && \
    rm -rf /var/lib/apt/lists/*

RUN git config --global --add safe.directory '*'

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN curl -LSs https://github.com/box-project/box/releases/download/4.5.0/box.phar -o /usr/local/bin/box && \
    chmod +x /usr/local/bin/box

WORKDIR /app
EOF
)

# Записываем во временный файл
TMP_DOCKERFILE="Dockerfile.builder.tmp"
echo "$DOCKERFILE_CONTENT" > "$TMP_DOCKERFILE"

# 2. Собираем образ (используем кэш, если он уже есть)
echo "📦 Checking/Building builder image..."
docker build -f "$TMP_DOCKERFILE" -t composer-proxy-builder .

# 3. Удаляем временный файл
rm -f "$TMP_DOCKERFILE"

# 4. Определяем текущего пользователя для прав доступа
CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

# 5. Запускаем сборку внутри контейнера
echo "🚀 Running build process..."
docker run --rm \
    -v "$(pwd)":/app \
    -e HOST_UID=$CURRENT_UID \
    -e HOST_GID=$CURRENT_GID \
    composer-proxy-builder sh -c "
        echo '   Installing dependencies...' && \
        composer update --lock --no-interaction && \
        composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction && \
        echo '   Compiling PHAR...' && \
        box compile && \
        echo '   Fixing permissions...' && \
        chown \${HOST_UID}:\${HOST_GID} /app/composer-proxy.phar && \
        echo '✅ Build complete!'
    "

# 6. Финальная проверка
if [ -f "composer-proxy.phar" ]; then
    echo ""
    echo "🎉 Success! PHAR is ready:"
    ls -lh composer-proxy.phar
else
    echo "❌ Error: composer-proxy.phar was not created."
    exit 1
fi