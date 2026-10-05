#!/bin/sh
# Runs every time Render starts the backend.
set -e

# Render tells the app which port to use (default 10000).
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/__PORT__/${PORT}/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache

# Applies only NEW database changes. Existing data is kept (never migrate:fresh here).
php artisan migrate --force

exec apache2-foreground
