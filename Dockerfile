# Runs the Laravel app on Render: PHP's own built-in server is enough for a
# small demo/assessment deployment (single free instance, no real traffic
# load) - it keeps the image simple and avoids needing a separate Nginx +
# PHP-FPM setup to explain.
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chmod -R 775 storage bootstrap/cache

# Render assigns the port to listen on via $PORT at runtime.
# Migrations and the (idempotent) seeders run on every boot so the demo data
# is always present, including after a cold start on the free plan.
# There's no separate worker/cron service on the free plan, so the scheduler
# (e.g. the daily bookings:complete-past job) runs in the background of this
# same container via schedule:work instead of a real system cron.
CMD php artisan config:cache \
    && php artisan migrate --force \
    && php artisan db:seed --force \
    && (php artisan schedule:work &) \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}
