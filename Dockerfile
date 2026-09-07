# ==============================================================================
# PKP SecureGate - Production Lightweight Dockerfile
# Based on PHP 8.2 FPM Alpine + Nginx + Supervisord
# ==============================================================================

FROM php:8.2-fpm-alpine

# Set build & runtime environment
ENV TZ=Asia/Jakarta
ENV COMPOSER_ALLOW_SUPERUSER=1

# 1. Install System Dependencies & Alpine Packages
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    sqlite-dev \
    sqlite \
    bash \
    tzdata \
    && cp /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo "${TZ}" > /etc/timezone

# 2. Install Required PHP Extensions
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    bcmath \
    mbstring \
    xml \
    zip \
    pcntl \
    opcache

# 3. Install Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# 4. Copy Composer Manifests & Install Dependencies First (Layer Caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# 5. Copy Application Source Code
COPY . .

# 6. Copy Configurations (Nginx, Supervisor, PHP, Entrypoint)
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
RUN rm -rf /etc/nginx/conf.d/*
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini $PHP_INI_DIR/conf.d/custom.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 7. Finalize Composer Autoloader
RUN composer dump-autoload --optimize --no-dev

# 8. Create Required Directories and Set Permissions
RUN mkdir -p \
    /var/www/html/storage/app \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    /var/www/html/database \
    /var/log/supervisor \
    /run/nginx \
    && chown -R www-data:www-data /var/www/html /run/nginx \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Expose Web Port
EXPOSE 80

# Define Entrypoint and Default Command
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
