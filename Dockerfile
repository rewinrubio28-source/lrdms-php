# --- Stage 1: install Composer (PHP) dependencies ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- Stage 2: the actual PHP + Apache runtime ---
FROM php:8.2-apache

# Fast PHP extension installer (uses pre-built binaries when available,
# instead of compiling every extension from source — much faster builds).
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mbstring gd curl \
    && a2enmod rewrite

WORKDIR /var/www/html

# App code
COPY . .

# vendor/ is git-ignored (see .gitignore), so it doesn't exist in the
# checkout HostForge builds from — bring it in from Stage 1 instead.
COPY --from=vendor /app/vendor ./vendor

# config/database.php and config/email.php are git-ignored on purpose,
# but the .example versions are functionally identical (they only read
# from environment variables — no secrets live in either file), so we
# activate them here at build time.
RUN cp config/database.example.php config/database.php \
    && cp config/email.example.php config/email.php \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 uploads

# Raise PHP's upload limits (defaults are only 2 MB per file / 8 MB per request).
# post_max_size must stay larger than upload_max_filesize. HostForge's nginx
# in front of the app has its own body-size limit that must be raised too.
RUN { \
    echo "upload_max_filesize=25M"; \
    echo "post_max_size=30M"; \
    echo "memory_limit=256M"; \
    echo "max_execution_time=120"; \
  } > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80
