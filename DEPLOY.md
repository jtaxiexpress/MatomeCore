# MatomeCore Deployment Guide (Dokploy)

This guide provides instructions for deploying MatomeCore to a Hetzner VPS using Dokploy, ensuring a zero-downtime deployment process with separated environments.

## 1. Environment Variables (`.env`)

In Dokploy, set the following environment variables for your application. **Never** include development variables or local ports in the production environment.

**Required Production Variables:**
```env
APP_NAME=MatomeCore
APP_ENV=production
APP_KEY=base64:your_generated_app_key_here
APP_DEBUG=false
APP_URL=https://your-production-domain.com

# Database Connection (Provided by Dokploy's MySQL service)
DB_CONNECTION=mysql
DB_HOST=mysql-service-name
DB_PORT=3306
DB_DATABASE=matomecore
DB_USERNAME=dokploy
DB_PASSWORD=your_secure_password

# Redis Connection (Provided by Dokploy's Redis service)
REDIS_HOST=redis-service-name
REDIS_PASSWORD=null
REDIS_PORT=6379

# Cache / Queue / Session
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# AI Integration
OLLAMA_BASE_URL=https://ollama.unicorn.tokyo
OLLAMA_INTEGRATION=true
```

## 2. Infrastructure Configuration

### `dokploy-compose.yml`
This repository uses `dokploy-compose.yml` for production deployments.
- **Port Security:** The `web` service only exposes port `8000` internally to the Docker network. Dokploy (Traefik) maps your external domain to this internal port.
- **Graceful Shutdowns:** AI and Scraping workers have a `stop_grace_period: 130s` defined. Ensure `queue:work` commands use `--timeout=120` to guarantee jobs complete or safely fail back to the queue before the container is destroyed during a redeploy.
- **Worker Limits:** Workers utilize `--max-jobs=500` (or `1000`) and `--max-time=3600` to prevent memory leaks over time.

### `Dockerfile.prod`
We use a multi-stage Docker build utilizing `dunglas/frankenphp:php8.5-alpine`.
- Stage 1 compiles Composer dependencies without `require-dev` and builds Node assets using Vite.
- Stage 2 copies only the optimized artifacts into the final container.

### `docker/entrypoint.sh`
When the container boots, it automatically handles the Laravel bootstrapping required for production:
- Optimizes config/routes (`php artisan optimize`)
- Compiles views (`php artisan view:cache`)
- Caches Filament (`php artisan filament:optimize`)
- Runs database migrations (`php artisan migrate --force`)
- Boots the server via Octane (`php artisan octane:start`)

## 3. GitHub Actions CI/CD Pipeline (Recommended)

To prevent deploying broken code, create a `.github/workflows/deploy.yml` pipeline that triggers on pushes to the `main` branch.

**Pipeline Steps:**
1. Checkout code
2. Setup PHP 8.5
3. Run `composer install --no-interaction --prefer-dist`
4. Run Static Analysis: `vendor/bin/phpstan analyse --memory-limit=2G`
5. Run Tests: `php artisan test`
6. (If successful) Trigger Dokploy Webhook URL to start the automated deployment.

```yaml
name: Deploy to Dokploy

on:
  push:
    branches: [ "main" ]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
      - name: Install Dependencies
        run: composer install -q --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist
      - name: Run PHPStan
        run: vendor/bin/phpstan analyse
      - name: Run Tests
        run: php artisan test

  deploy:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Dokploy Webhook
        run: curl -X POST "${{ secrets.DOKPLOY_WEBHOOK_URL }}"
```

## 4. Zero-Downtime Verification

Because we are utilizing Laravel Octane via FrankenPHP and Docker Compose in Dokploy:
1. The new image builds via `Dockerfile.prod`.
2. Dokploy brings up the new container. The `entrypoint.sh` optimizes and migrates.
3. Once the new container is healthy, Traefik switches routing to the new container.
4. Old workers are sent a `SIGTERM` and finish executing their current jobs within the 130s grace period.
5. Zero downtime is achieved safely.
