FROM php:8.3-cli

WORKDIR /var/www

# Dependências do sistema
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    libzip-dev zip unzip libpq-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Extensões PHP (incluindo pgsql para PostgreSQL)
RUN docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copia o projeto
COPY . .

# Instala dependências PHP (sem dev)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissões de storage e cache
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Porta exposta pelo Render
EXPOSE 8080

# Inicia o servidor Laravel na porta correta
CMD php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force && \
    php artisan serve --host=0.0.0.0 --port=8080
