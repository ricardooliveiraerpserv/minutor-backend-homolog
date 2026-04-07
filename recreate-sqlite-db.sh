#!/bin/bash

echo "🔧 Recriando banco de dados SQLite para desenvolvimento local..."
echo ""

# Verificar se está no diretório correto
if [ ! -f "artisan" ]; then
    echo "❌ Erro: Execute este script na raiz do projeto Laravel"
    exit 1
fi

# Verificar se Docker está disponível e containers estão rodando
if ! command -v docker &> /dev/null; then
    echo "❌ Docker não está instalado ou não está no PATH"
    exit 1
fi

if ! docker ps &> /dev/null; then
    echo "❌ Docker não está rodando. Por favor, inicie o Docker e tente novamente."
    exit 1
fi

# Verificar se o container do app está rodando
if ! docker ps | grep -q "minutor_app"; then
    echo "⚠️  Container 'minutor_app' não está rodando. Tentando iniciar..."
    docker-compose up -d app 2>/dev/null || docker compose up -d app 2>/dev/null || {
        echo "❌ Não foi possível iniciar o container. Execute: docker-compose up -d"
        exit 1
    }
    echo "⏳ Aguardando container iniciar..."
    sleep 5
fi

# Criar arquivo database.sqlite se não existir
if [ ! -f "database/database.sqlite" ]; then
    echo "📁 Criando arquivo database.sqlite..."
    touch database/database.sqlite
    echo "✅ Arquivo database.sqlite criado"
else
    echo "⚠️  Arquivo database.sqlite já existe. Removendo..."
    rm database/database.sqlite
    touch database/database.sqlite
    echo "✅ Arquivo database.sqlite recriado"
fi

# Verificar se .env existe
if [ ! -f ".env" ]; then
    echo "📝 Criando arquivo .env..."
    cat > .env << 'EOF'
APP_NAME=Minutor
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost:8000

APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=pt_BR

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

SESSION_DRIVER=database
SESSION_LIFETIME=120

SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:3000,::1
API_PREFIX=api/v1

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=file
CACHE_PREFIX=

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@minutor.com"
MAIL_FROM_NAME="Minutor"
EOF
    echo "✅ Arquivo .env criado"
else
    echo "📝 Arquivo .env já existe. Verificando configuração..."
    
    # Verificar se DB_CONNECTION está configurado para sqlite
    if ! grep -q "DB_CONNECTION=sqlite" .env; then
        echo "⚠️  DB_CONNECTION não está configurado para sqlite"
        echo "   Atualizando .env para usar SQLite..."
        # Atualizar DB_CONNECTION se existir
        if grep -q "DB_CONNECTION=" .env; then
            sed -i.bak 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
        else
            echo "DB_CONNECTION=sqlite" >> .env
        fi
        # Atualizar DB_DATABASE se existir
        if grep -q "DB_DATABASE=" .env; then
            sed -i.bak 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' .env
        else
            echo "DB_DATABASE=database/database.sqlite" >> .env
        fi
        rm -f .env.bak
        echo "✅ Configuração atualizada"
    fi
fi

# Verificar se as dependências do Composer estão instaladas
if [ ! -d "vendor" ]; then
    echo "📦 Instalando dependências do Composer..."
    if command -v docker &> /dev/null && docker ps &> /dev/null; then
        docker-compose exec app composer install 2>/dev/null || \
        docker compose exec app composer install 2>/dev/null || {
            echo "❌ Erro ao instalar dependências do Composer"
            echo "   Execute manualmente: docker-compose exec app composer install"
            exit 1
        }
        echo "✅ Dependências instaladas"
    else
        echo "❌ Docker não está disponível. Execute manualmente:"
        echo "   docker-compose exec app composer install"
        exit 1
    fi
else
    echo "✅ Dependências do Composer já estão instaladas"
fi

# Verificar se APP_KEY está configurada
if ! grep -q "APP_KEY=base64:" .env; then
    echo "🔑 Gerando APP_KEY..."
    if command -v docker &> /dev/null && docker ps &> /dev/null; then
        docker-compose exec app php artisan key:generate 2>/dev/null || \
        docker compose exec app php artisan key:generate 2>/dev/null || \
        echo "⚠️  Não foi possível gerar APP_KEY automaticamente. Execute: docker-compose exec app php artisan key:generate"
    else
        echo "⚠️  Docker não está disponível. Execute manualmente: docker-compose exec app php artisan key:generate"
    fi
fi

echo ""
echo "📦 Executando migrations..."
if command -v docker &> /dev/null && docker ps &> /dev/null; then
    docker-compose exec app php artisan migrate:fresh 2>/dev/null || \
    docker compose exec app php artisan migrate:fresh 2>/dev/null || {
        echo "❌ Erro ao executar migrations"
        exit 1
    }
else
    echo "⚠️  Docker não está disponível. Execute manualmente:"
    echo "   docker-compose exec app php artisan migrate:fresh"
    exit 1
fi

echo ""
echo "🌱 Executando seeders..."
if command -v docker &> /dev/null && docker ps &> /dev/null; then
    docker-compose exec app php artisan db:seed 2>/dev/null || \
    docker compose exec app php artisan db:seed 2>/dev/null || {
        echo "❌ Erro ao executar seeders"
        exit 1
    }
else
    echo "⚠️  Docker não está disponível. Execute manualmente:"
    echo "   docker-compose exec app php artisan db:seed"
    exit 1
fi

echo ""
echo "✅ Banco de dados SQLite recriado com sucesso!"
echo ""
echo "📋 Credenciais de acesso:"
echo "   Admin: admin@minutor.com / admin123456 (Administrator)"
echo "   Teste: teste@minutor.com / teste123456 (Project Manager)"
echo "   Demo: demo@minutor.com / demo123456 (Consultant)"
echo ""
