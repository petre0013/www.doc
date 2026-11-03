# syntax=docker/dockerfile:1

# ── Stage 1: build the frontend assets ──────────────────────────────────────
FROM node:26-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ── Stage 2: PHP runtime (php-fpm), with code + vendor + built assets baked in ─
FROM php:8.3-fpm-bookworm AS app
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev curl ca-certificates smbclient \
    libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo pdo_pgsql pgsql zip intl bcmath opcache gd \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

# Production opcache + sane PHP defaults.
COPY docker/app/php.prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first (better layer caching), without dev packages.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-autoloader

# Application code, then the compiled assets from stage 1.
COPY . .
COPY --from=assets /app/public/build ./public/build

# Install Node dependencies needed at runtime (e.g., for process_svg.js)
RUN npm ci --omit=dev

# storage/ and bootstrap/cache are .dockerignored — recreate the empty skeleton
# Laravel needs at runtime, then optimise the autoloader.
RUN mkdir -p \
      storage/app/public \
      storage/app/private \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      bootstrap/cache \
 && composer dump-autoload --optimize --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache

# The release tag this image was built from ("dev" outside CI). Read by
# config('app.version') → the Settings footer and backup manifests.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ── Stage 3: nginx serving the static assets + proxying php-fpm ──────────────
# nginx needs the whole public/ tree on disk, not just build/. php-fpm executes
# index.php from its OWN filesystem (passed by SCRIPT_FILENAME), but nginx still
# needs public/index.php present so the `index` directive can resolve a request
# for "/" — without it, "/" matches the bare directory and nginx returns 403.
# Copying public/ from the app stage also brings favicon.ico, robots.txt and the
# content-hashed build/ assets (immutable per-deploy, so never stale). Uploaded
# /storage/* arrives via the shared volume through the public/storage symlink.
FROM nginx:1.31-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
RUN ln -s /var/www/html/storage/app/public /var/www/html/public/storage
EXPOSE 80
