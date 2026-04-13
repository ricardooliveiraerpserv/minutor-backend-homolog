FROM php:8.3-fpm-alpine

WORKDIR /var/www

# Dependências do sistema
RUN apk add --no-cache \
    nginx \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    supervisor

# Extensões PHP
RUN docker-php-ext-install \
    pdo pdo_pgsql pdo_mysql \
    mbstring exif pcntl bcmath gd zip opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copia o projeto
COPY . .

# Instala dependências PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissões
RUN mkdir -p storage/logs storage/framework/cache \
        storage/framework/sessions storage/framework/views \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www

# Configuração nginx
RUN printf 'server {\n\
    listen 8080;\n\
    root /var/www/public;\n\
    index index.php;\n\
    disable_symlinks off;\n\
    location /storage/ {\n\
        alias /var/www/storage/app/public/;\n\
        try_files $uri =404;\n\
    }\n\
    location / {\n\
        try_files $uri $uri/ /index.php?$query_string;\n\
    }\n\
    location ~ \\.php$ {\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
        include fastcgi_params;\n\
    }\n\
}\n' > /etc/nginx/http.d/default.conf

# Supervisor para rodar nginx + php-fpm juntos
RUN printf '[supervisord]\n\
nodaemon=true\n\
[program:php-fpm]\n\
command=php-fpm\n\
autostart=true\n\
autorestart=true\n\
[program:nginx]\n\
command=nginx -g "daemon off;"\n\
autostart=true\n\
autorestart=true\n' > /etc/supervisord.conf

EXPOSE 8080

CMD sh -c "php artisan config:clear && php artisan storage:link --force 2>/dev/null || true && php artisan migrate --force && supervisord -c /etc/supervisord.conf"
