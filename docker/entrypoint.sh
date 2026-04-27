#!/bin/sh
set -e

# Run optimizations
php artisan optimize
php artisan view:cache
php artisan filament:optimize

# Run migrations
php artisan migrate --force

# Ignore Octane execution if args passed and they are not starting octane
if [ $# -gt 0 ] && [ "$1" != "php" ] && [ "$2" != "artisan" ] && [ "$3" != "octane:start" ]; then
    exec "$@"
fi

exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=auto --task-workers=auto --max-requests=1000
