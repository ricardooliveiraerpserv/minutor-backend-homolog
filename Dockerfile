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

# OPcache: compila e cacheia bytecode PHP em memória
RUN printf 'opcache.enable=1\n\
opcache.enable_cli=1\n\
opcache.memory_consumption=80\n\
opcache.interned_strings_buffer=8\n\
opcache.max_accelerated_files=12000\n\
opcache.revalidate_freq=0\n\
opcache.validate_timestamps=0\n\
opcache.save_comments=1\n\
opcache.fast_shutdown=1\n' > /usr/local/etc/php/conf.d/opcache.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copia o projeto
COPY . .

# Instala dependências PHP (cache de pacotes entre builds)
RUN --mount=type=cache,id=minutor-composer,target=/root/.composer \
    composer install --no-dev --optimize-autoloader --no-interaction

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
    client_max_body_size 64M;\n\
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
        fastcgi_read_timeout 120;\n\
        fastcgi_send_timeout 120;\n\
    }\n\
}\n' > /etc/nginx/http.d/default.conf

# Limite de upload PHP + timeout de execução (render de documento via Gotenberg
# pode levar ~40-60s no plano free; o default 30s do PHP cortava e dava 500).
# ⚠️ memory_limit é TETO POR REQUISIÇÃO, não RAM reservada — NÃO baixar p/ economizar
# memória base (isso quem faz é opcache + max_children). Baixei p/ 192M por engano e
# quebrou toda request autenticada do admin (carrega permissões/empresas/nav de tudo,
# passa de 192M) → 500 fatal. 256M é o valor que funciona. A folga de RAM vem do opcache 80M.
RUN printf 'upload_max_filesize=52M\npost_max_size=64M\nmemory_limit=256M\nmax_execution_time=120\nmax_input_time=120\n' > /usr/local/etc/php/conf.d/uploads.ini

# PHP-FPM pool: default da imagem = max_children=5.
# ⚠️ 2026-08-20: o limite REAL do container é 512Mi (evento oomKilled memoryLimit=512Mi),
# NÃO os ~2 GB estimados. max_children=8 ainda estourava. Orçamento p/ 512Mi:
#   opcache 80 + nginx 15 + fpm master 30 + 2 workers de fila ~2×90 + 4 fpm children ~4×60
#   ≈ 470 MB de pico → cabe com folga. max_children=4 (homolog tem poucos usuários;
#   dashboard dispara ~15 chamadas juntas, mas curtas). memory_limit baixado p/ 192M
#   (uploads.ini) e opcache p/ 80M. Filas com --memory=96 reciclam antes de vazar.
RUN sed -i \
      -e 's|^pm.max_children = 5$|pm.max_children = 4|' \
      -e 's|^pm.start_servers = 2$|pm.start_servers = 2|' \
      -e 's|^pm.min_spare_servers = 1$|pm.min_spare_servers = 1|' \
      -e 's|^pm.max_spare_servers = 3$|pm.max_spare_servers = 2|' \
      /usr/local/etc/php-fpm.d/www.conf

# Supervisor: nginx + php-fpm + workers de fila (sem serviço pago à parte).
# Programas: helpdesk-worker (emails,default) · sourcedoc-worker (fila 'source-doc', timeout 310 p/
# reprocess pesado da Central de Fontes). O SCHEDULER roda num Render Cron dedicado
# (minutor-backend-homolog-scheduler → 'php artisan schedule:run' a cada minuto), pois o
# schedule:run dentro deste container não completa alguns comandos; um cron em container próprio sim.
# O worker (helpdesk-worker) processa a fila 'emails' (disparo de e-mail do Help Desk) quando
# QUEUE_CONNECTION=database. Com QUEUE_CONNECTION=sync os jobs rodam inline e a fila 'jobs' fica
# vazia → o worker apenas ociosa (inofensivo). Assim protege o Azure/Graph de rate limit/blacklist
# sem precisar de um serviço worker pago à parte. --max-time=3600 recicla o processo de hora em hora
# (supervisor reinicia). stopwaitsecs>timeout garante shutdown gracioso (termina o job atual).
RUN printf '[supervisord]\n\
nodaemon=true\n\
[program:php-fpm]\n\
command=php-fpm\n\
autostart=true\n\
autorestart=true\n\
[program:nginx]\n\
command=nginx -g "daemon off;"\n\
autostart=true\n\
autorestart=true\n\
[program:helpdesk-worker]\n\
command=php artisan queue:work database --queue=emails,default --sleep=3 --tries=5 --timeout=180 --max-time=3600 --memory=96\n\
directory=/var/www\n\
autostart=true\n\
autorestart=true\n\
startsecs=5\n\
stopwaitsecs=190\n\
stopsignal=TERM\n\
stdout_logfile=/dev/stdout\n\
stdout_logfile_maxbytes=0\n\
stderr_logfile=/dev/stderr\n\
stderr_logfile_maxbytes=0\n\
[program:sourcedoc-worker]\n\
command=php artisan queue:work database --queue=source-doc --tries=1 --timeout=310 --sleep=3 --max-time=3600 --memory=128\n\
directory=/var/www\n\
autostart=true\n\
autorestart=true\n\
startsecs=5\n\
stopwaitsecs=320\n\
stopsignal=TERM\n\
stdout_logfile=/dev/stdout\n\
stdout_logfile_maxbytes=0\n\
stderr_logfile=/dev/stderr\n\
stderr_logfile_maxbytes=0\n' > /etc/supervisord.conf

EXPOSE 8080

CMD sh -c "php artisan migrate --force && php artisan optimize 2>/dev/null || true && php artisan storage:link --force 2>/dev/null || true && chmod -R 777 storage bootstrap/cache && supervisord -c /etc/supervisord.conf"
