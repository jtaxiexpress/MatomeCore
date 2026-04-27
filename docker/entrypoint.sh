#!/bin/sh
set -e

# Run optimizations
php artisan optimize
php artisan view:cache
php artisan filament:optimize

# Run migrations
php artisan migrate --force

exec "$@"
