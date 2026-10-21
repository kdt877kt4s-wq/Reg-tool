#!/bin/sh
# Container roles. Configuration comes only from environment variables
# (in Azure, Key Vault references); nothing secret is baked into the image.
set -e

cache_config() {
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache
}

case "${1:-web}" in
  web)
    cache_config
    exec frankenphp php-server --root /app/public --listen :8080
    ;;
  worker)
    cache_config
    exec php artisan queue:work --tries=3 --backoff=30 --max-time=3600 --sleep=3
    ;;
  scheduler)
    cache_config
    exec php artisan schedule:work
    ;;
  migrate)
    # Idempotent: migrations, then reference data (updateOrCreate).
    php artisan migrate --force
    exec php artisan db:seed --force
    ;;
  *)
    exec "$@"
    ;;
esac
