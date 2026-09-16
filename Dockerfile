FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_pgsql curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} index.php"]
