# BulanBotikaCare backend for Render (Docker web service).
# Apache + PHP, as in the paper's server specification (Table 3.2).
# PHP 8.4 because composer.lock contains packages (Symfony 8) that need PHP >= 8.4.
FROM php:8.4-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP packages first (cached between deploys when composer files don't change)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

CMD ["sh", "docker/start.sh"]
