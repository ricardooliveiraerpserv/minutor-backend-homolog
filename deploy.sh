#!/bin/bash

# ===========================================
# SCRIPT DE DEPLOY MANUAL - MINUTOR BACKEND
# ===========================================

set -e  # Para o script se algum comando falhar

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Função para log colorido
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}"
    exit 1
}

info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO: $1${NC}"
}

# Detectar qual comando docker compose usar
if command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
elif docker compose version &> /dev/null; then
    DOCKER_COMPOSE="docker compose"
else
    error "Docker Compose não está instalado ou não está no PATH"
fi

info "Usando comando: $DOCKER_COMPOSE"

# Verificar se o Docker está rodando
if ! docker info &> /dev/null; then
    error "Docker não está rodando"
fi

# Função para backup do banco
backup_database() {
    log "Fazendo backup do banco de dados..."
    BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).sql"

    if $DOCKER_COMPOSE ps | grep -q "minutor_db.*Up"; then
        $DOCKER_COMPOSE exec -T db mysqldump -u laravel_user -plaravel_password laravel > "$BACKUP_FILE" 2>/dev/null || {
            warn "Não foi possível fazer backup do banco. Continuando..."
        }
        if [ -f "$BACKUP_FILE" ]; then
            log "Backup salvo em: $BACKUP_FILE"
        fi
    else
        warn "Container do banco não está rodando. Pulando backup."
    fi
}

# Função para parar containers
stop_containers() {
    log "Parando containers existentes..."
    $DOCKER_COMPOSE down || {
        warn "Erro ao parar containers. Tentando continuar..."
    }
}

# Função para limpar imagens antigas
cleanup_images() {
    log "Limpando imagens Docker antigas..."
    docker image prune -f || {
        warn "Erro ao limpar imagens. Continuando..."
    }
}

# Função para subir containers
start_containers() {
    log "Subindo containers..."
    $DOCKER_COMPOSE up -d --build || {
        error "Erro ao subir containers"
    }
}

# Função para aguardar containers ficarem prontos
wait_for_containers() {
    log "Aguardando containers ficarem prontos..."
    sleep 30

    # Verificar se os containers estão rodando
    if ! $DOCKER_COMPOSE ps | grep -q "minutor_app.*Up"; then
        error "Container da aplicação não está rodando"
    fi

    if ! $DOCKER_COMPOSE ps | grep -q "minutor_db.*Up"; then
        error "Container do banco não está rodando"
    fi

    log "Containers estão rodando!"
}

# Função para executar migrações
run_migrations() {
    log "Executando migrações do banco de dados..."
    $DOCKER_COMPOSE exec -T app php artisan migrate --force || {
        warn "Erro ao executar migrações. Verifique os logs."
    }
}

# Função para limpar caches
clear_caches() {
    log "Limpando caches da aplicação..."
    $DOCKER_COMPOSE exec -T app php artisan config:clear || true
    $DOCKER_COMPOSE exec -T app php artisan cache:clear || true
    $DOCKER_COMPOSE exec -T app php artisan route:clear || true
    $DOCKER_COMPOSE exec -T app php artisan view:clear || true
}

# Função para otimizar para produção
optimize_for_production() {
    log "Otimizando aplicação para produção..."
    $DOCKER_COMPOSE exec -T app php artisan config:cache || true
    $DOCKER_COMPOSE exec -T app php artisan route:cache || true
}

# Função para verificar saúde da aplicação
health_check() {
    log "Verificando saúde da aplicação..."
    sleep 10

    # Tentar acessar a aplicação
    if curl -f http://localhost:8000/api/v1/health &>/dev/null; then
        log "✅ Aplicação está respondendo corretamente!"
    else
        warn "⚠️  Aplicação pode não estar respondendo. Verifique os logs."
    fi
}

# Função para mostrar status final
show_status() {
    log "Status final dos containers:"
    $DOCKER_COMPOSE ps

    log "Deploy concluído!"
    log "Aplicação disponível em: http://localhost:8000"
    log "Para ver logs: $DOCKER_COMPOSE logs -f app"
}

# Função principal
main() {
    log "🚀 Iniciando deploy do Minutor Backend..."

    # Verificar se estamos no diretório correto
    if [ ! -f "docker-compose.yml" ]; then
        error "docker-compose.yml não encontrado. Execute este script no diretório do projeto."
    fi

    # Executar etapas do deploy
    backup_database
    stop_containers
    cleanup_images
    start_containers
    wait_for_containers
    run_migrations
    clear_caches
    optimize_for_production
    health_check
    show_status
}

# Função de rollback
rollback() {
    log "🔄 Executando rollback..."
    $DOCKER_COMPOSE down
    $DOCKER_COMPOSE up -d
    log "Rollback concluído!"
}

# Função para mostrar ajuda
show_help() {
    echo "Uso: $0 [opção]"
    echo ""
    echo "Opções:"
    echo "  deploy    - Executa o deploy completo (padrão)"
    echo "  rollback  - Executa rollback para versão anterior"
    echo "  status    - Mostra status dos containers"
    echo "  logs      - Mostra logs da aplicação"
    echo "  help      - Mostra esta ajuda"
    echo ""
    echo "Exemplos:"
    echo "  $0 deploy"
    echo "  $0 rollback"
    echo "  $0 status"
}

# Função para mostrar status
show_container_status() {
    log "Status dos containers:"
    $DOCKER_COMPOSE ps
}

# Função para mostrar logs
show_logs() {
    log "Logs da aplicação (Ctrl+C para sair):"
    $DOCKER_COMPOSE logs -f app
}

# Processar argumentos
case "${1:-deploy}" in
    "deploy")
        main
        ;;
    "rollback")
        rollback
        ;;
    "status")
        show_container_status
        ;;
    "logs")
        show_logs
        ;;
    "help"|"-h"|"--help")
        show_help
        ;;
    *)
        error "Opção inválida: $1. Use '$0 help' para ver as opções disponíveis."
        ;;
esac
