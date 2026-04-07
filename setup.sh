#!/bin/bash

echo "🚀 Configurando projeto Laravel com Docker..."

# Verificar se o Docker está rodando
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker não está rodando. Por favor, inicie o Docker e tente novamente."
    exit 1
fi

# Verificar se o docker-compose está disponível
if ! command -v docker compose &> /dev/null; then
    echo "❌ docker-compose não está instalado. Por favor, instale o docker-compose e tente novamente."
    exit 1
fi

# Criar o projeto Laravel se não existir
if [ ! -f "composer.json" ]; then
    echo "📦 Criando novo projeto Laravel..."

    # Salvar arquivos Docker em diretório temporário
    mkdir -p /tmp/docker-backup
    cp -r docker/ /tmp/docker-backup/ 2>/dev/null || true
    cp docker-compose.yml /tmp/docker-backup/ 2>/dev/null || true
    cp Dockerfile /tmp/docker-backup/ 2>/dev/null || true
    cp README.md /tmp/docker-backup/ 2>/dev/null || true
    cp -r .cursor/ /tmp/docker-backup/ 2>/dev/null || true
    cp setup.sh /tmp/docker-backup/ 2>/dev/null || true

    echo "📁 Arquivos Docker salvos temporariamente"

    # Limpar diretório atual (exceto arquivos importantes)
    find . -maxdepth 1 -not -name '.' -not -name '..' -not -name '.git' -exec rm -rf {} + 2>/dev/null || true

    # Criar projeto Laravel
    echo "📥 Baixando Laravel... (isso pode demorar alguns minutos)"
    if ! docker run --rm -v $(pwd):/app composer:latest create-project laravel/laravel . --prefer-dist; then
        echo "❌ Erro ao criar projeto Laravel. Restaurando arquivos..."
        cp -r /tmp/docker-backup/* . 2>/dev/null || true
        cp -r /tmp/docker-backup/.cursor . 2>/dev/null || true
        rm -rf /tmp/docker-backup
        exit 1
    fi

    # Verificar se o Laravel foi criado
    if [ ! -f "composer.json" ] || [ ! -f "artisan" ]; then
        echo "❌ Laravel não foi criado corretamente. Restaurando arquivos..."
        cp -r /tmp/docker-backup/* . 2>/dev/null || true
        cp -r /tmp/docker-backup/.cursor . 2>/dev/null || true
        rm -rf /tmp/docker-backup
        exit 1
    fi

    # Restaurar arquivos Docker
    cp -r /tmp/docker-backup/* . 2>/dev/null || true
    cp -r /tmp/docker-backup/.cursor . 2>/dev/null || true

    # Criar configuração .env personalizada para Docker
    cat > .env << 'EOF'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost:8000

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=database

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:3000,::1
API_PREFIX=api/v1

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=redis
CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"
EOF

    # Limpar backup temporário
    rm -rf /tmp/docker-backup

    echo "✅ Projeto Laravel criado e arquivos Docker restaurados!"
fi

# Subir os containers
echo "🐳 Subindo containers Docker..."
docker compose up -d

# Aguardar o MySQL estar pronto
echo "⏳ Aguardando MySQL estar pronto..."
sleep 30

# Instalar dependências
echo "📚 Instalando dependências..."
docker compose exec app composer install

# Gerar chave da aplicação
echo "🔑 Gerando chave da aplicação..."
docker compose exec app php artisan key:generate

# Instalar e configurar Sanctum
echo "🔐 Configurando Laravel Sanctum..."
docker compose exec app composer require laravel/sanctum
# docker-compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# Executar migrations
echo "🗄️  Executando migrations..."
docker compose exec app php artisan migrate

# Limpar cache
echo "🧹 Limpando cache..."
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan view:clear

echo "🎉 Projeto configurado com sucesso!"
echo "🌐 Acesse: http://localhost:8000"
echo ""
echo "Comandos úteis:"
echo "  docker-compose logs app     # Ver logs do Laravel"
echo "  docker-compose exec app bash # Entrar no container"
echo "  docker-compose down         # Parar containers"
echo "  docker-compose up -d        # Iniciar containers"
