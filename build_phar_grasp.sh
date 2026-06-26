#!/bin/bash
set -e

PROJECT_DIR="$(basename "$(pwd)")"
echo "Building $PROJECT_DIR PHAR..."

IMAGE_NAME="phar-builder-grasp"

# ---- Read box.json ----
if [ ! -f "box.json" ]; then
    echo "Error: box.json not found"
    exit 1
fi

BOX_MAIN=$(php -r "echo json_decode(file_get_contents('box.json'), true)['main'] ?? 'index.php';")
BOX_OUTPUT=$(php -r "echo json_decode(file_get_contents('box.json'), true)['output'] ?? 'output.phar';")
VERSION_DIR=$(dirname "$BOX_MAIN")

echo "   Main script: $BOX_MAIN"
echo "   Output PHAR: $BOX_OUTPUT"

# ---- Rebuild image if requested ----
if [ "$1" = "--rebuild" ]; then
    echo "Removing existing image $IMAGE_NAME for rebuild..."
    docker rmi "$IMAGE_NAME" 2>/dev/null || true
fi

# ---- Build image only once ----
if ! docker image inspect "$IMAGE_NAME" >/dev/null 2>&1; then
    echo "Image $IMAGE_NAME not found, building..."

    TMP_DOCKERFILE="/tmp/grasp_phar_builder.Dockerfile"
    trap 'rm -f "$TMP_DOCKERFILE"' EXIT

    if [ -f "box.phar" ]; then
        echo "Found local box.phar, will COPY it into image"
        BOX_INSTALL="COPY box.phar /usr/local/bin/box
RUN chmod +x /usr/local/bin/box"
    else
        echo "box.phar not found locally, will download from GitHub"
        BOX_INSTALL="RUN curl -LSs https://github.com/box-project/box/releases/download/4.5.0/box.phar -o /usr/local/bin/box && chmod +x /usr/local/bin/box"
    fi

    cat > "$TMP_DOCKERFILE" << DOCKERFILE
FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    unzip curl \
    libbz2-dev liblz4-dev libzstd-dev libzip-dev \
    libwebp-dev libjpeg-dev libpng-dev libfreetype-dev \
    libmagickwand-dev \
    libsodium-dev libssl-dev \
    libpq-dev libsqlite3-dev unixodbc-dev libldap-dev \
    libxml2-dev libxslt1-dev \
    libicu-dev libgmp-dev libonig-dev \
    libedit-dev libkrb5-dev libsnmp-dev libtidy-dev libmemcached-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j\$(nproc) \
        bcmath bz2 calendar dom exif fileinfo ftp \
        gd gettext gmp iconv intl ldap mbstring mysqli \
        opcache pcntl pdo pdo_mysql pdo_pgsql pdo_sqlite pgsql phar \
        posix simplexml snmp soap sockets \
        sodium sqlite3 tidy xml \
        xmlreader xmlwriter xsl zip \
    || echo "Some core extensions failed (non-fatal)"

RUN pecl install imagick redis apcu xmlrpc || echo "Some PECL extensions failed (non-fatal)"
RUN pecl install memcached lz4 zstd rar || echo "Some PECL extensions failed (non-fatal)"
RUN docker-php-ext-enable imagick redis apcu xmlrpc memcached lz4 zstd rar || true

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

${BOX_INSTALL}

WORKDIR /app
DOCKERFILE

    docker build -f "$TMP_DOCKERFILE" -t "$IMAGE_NAME" .
    echo "Image $IMAGE_NAME built"
else
    echo "Using existing image $IMAGE_NAME (pass --rebuild to rebuild)"
fi

CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

echo "   Extracting version info..."
GIT_TAG=$(git describe --tags --always 2>/dev/null || echo "0.0.0")
GIT_HASH=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
GIT_SUBJECT=$(git log --oneline --format=%B -n 1 HEAD 2>/dev/null | head -n 1 || echo "no commit")
GIT_DATE=$(git log --oneline --format="%at" -n 1 HEAD 2>/dev/null | xargs -I{} date -d @{} +%Y-%m-%d 2>/dev/null || echo "unknown")
APP_VERSION="Version: ${GIT_SUBJECT} #${GIT_HASH} (${GIT_DATE})"
echo "   $APP_VERSION"

echo "Running build..."
docker run --rm \
    -v "$(pwd)":/app \
    -e HOST_UID=$CURRENT_UID \
    -e HOST_GID=$CURRENT_GID \
    -e GIT_TAG="$GIT_TAG" \
    -e GIT_HASH="$GIT_HASH" \
    -e GIT_SUBJECT="$GIT_SUBJECT" \
    -e GIT_DATE="$GIT_DATE" \
    -e APP_VERSION="$APP_VERSION" \
    "$IMAGE_NAME" sh -c "
        echo '   Installing dependencies...' && \
        composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --ignore-platform-req=ext-redis && \
        echo '   Generating version file...' && \
        mkdir -p $VERSION_DIR && \
        echo \"\$GIT_SUBJECT\"    > $VERSION_DIR/_version && \
        echo \"\$GIT_DATE\"      >> $VERSION_DIR/_version && \
        echo \"\$GIT_HASH\"      >> $VERSION_DIR/_version && \
        echo \"\$APP_VERSION\"      >> $VERSION_DIR/_version && \
        echo '   Version file written to $VERSION_DIR/_version' && \
        chown \${HOST_UID}:\${HOST_GID} $VERSION_DIR/_version && \
        echo '   Compiling PHAR...' && \
        box compile && \
        rm $VERSION_DIR/_version && \
        echo '   Fixing permissions...' && \
        chown \${HOST_UID}:\${HOST_GID} /app/$BOX_OUTPUT && \
        echo 'Done!'
    "
if [ -f "$BOX_OUTPUT" ]; then
    echo "PHAR ready:"
    ls -lh "$BOX_OUTPUT"
else
    echo "Error: $BOX_OUTPUT was not created."
    exit 1
fi
