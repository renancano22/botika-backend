#!/bin/sh
# Runs every time Render starts the backend.
set -e

# Render tells the app which port to use (default 10000).
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/__PORT__/${PORT}/" /etc/apache2/sites-available/000-default.conf

# Render's secret files can only be read by root, but the website runs as www-data.
# Copy the database SSL certificate to a place the website can read.
if [ -n "$MYSQL_ATTR_SSL_CA" ] && [ -f "$MYSQL_ATTR_SSL_CA" ]; then
  cp "$MYSQL_ATTR_SSL_CA" /var/www/html/storage/db-ca.pem
  chmod 644 /var/www/html/storage/db-ca.pem
  export MYSQL_ATTR_SSL_CA=/var/www/html/storage/db-ca.pem
fi

php artisan config:cache

# Applies only NEW database changes. Existing data is kept (never migrate:fresh here).
php artisan migrate --force

exec apache2-foreground
