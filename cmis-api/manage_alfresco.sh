#!/bin/bash

# Script para gerenciar Alfresco via Docker
# Gerencia clonagem e execução do Alfresco Community

set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info() {
    echo -e "${BLUE}ℹ ${NC}$1"
}

success() {
    echo -e "${GREEN}✅ ${NC}$1"
}

warning() {
    echo -e "${YELLOW}⚠️  ${NC}$1"
}

error() {
    echo -e "${RED}❌ ${NC}$1"
}

# Verificar se Docker está instalado
check_docker() {
    if ! command -v docker &> /dev/null; then
        error "Docker não está instalado!"
        exit 1
    fi
}

check_docker_compose() {
    if ! command -v docker-compose &> /dev/null; then
        error "Docker Compose não está instalado!"
        exit 1
    fi
}

# Clone Alfresco se não existir
clone_alfresco() {
    if [ ! -d "acs-deployment" ]; then
        info "Clonando repositório do Alfresco..."
        git clone https://github.com/Alfresco/acs-deployment.git
        success "Repositório clonado"
    else
        info "Alfresco já está clonado"
    fi
}

# Iniciar Alfresco
start_alfresco() {
    check_docker
    check_docker_compose
    
    cd acs-deployment/docker-compose
    
    if [ ! -f "community-compose.yml" ]; then
        error "Arquivo community-compose.yml não encontrado!"
        exit 1
    fi
    
    info "Iniciando Alfresco Community..."
    info "Isso pode levar alguns minutos..."
    echo ""
    
    docker-compose -f community-compose.yml up -d
    
    success "Alfresco iniciado!"
    echo ""
    info "Aguardando Alfresco inicializar completamente..."
    echo "Isso pode levar 2-3 minutos..."
    echo ""
    info "Você pode verificar os logs com:"
    echo "  docker-compose -f acs-deployment/docker-compose/community-compose.yml logs -f"
}

# Parar Alfresco
stop_alfresco() {
    if [ ! -d "acs-deployment/docker-compose" ]; then
        error "Alfresco não está instalado!"
        exit 1
    fi
    
    cd acs-deployment/docker-compose
    docker-compose -f community-compose.yml down
    success "Alfresco parado"
}

# Ver status do Alfresco
status_alfresco() {
    if [ ! -d "acs-deployment/docker-compose" ]; then
        warning "Alfresco não está instalado"
        exit 0
    fi
    
    cd acs-deployment/docker-compose
    docker-compose -f community-compose.yml ps
}

# Ver logs do Alfresco
logs_alfresco() {
    if [ ! -d "acs-deployment/docker-compose" ]; then
        error "Alfresco não está instalado!"
        exit 1
    fi
    
    cd acs-deployment/docker-compose
    docker-compose -f community-compose.yml logs -f
}

# Reiniciar Alfresco
restart_alfresco() {
    stop_alfresco
    start_alfresco
}

# Menu principal
show_menu() {
    echo ""
    echo "┌─────────────────────────────────────────────┐"
    echo "│      Alfresco Community - Gerenciador         │"
    echo "└─────────────────────────────────────────────┘"
    echo ""
    echo "1) Clonar repositório Alfresco"
    echo "2) Iniciar Alfresco"
    echo "3) Parar Alfresco"
    echo "4) Reiniciar Alfresco"
    echo "5) Ver status"
    echo "6) Ver logs"
    echo "7) Sair"
    echo ""
    read -p "Escolha uma opção: " option
    
    case $option in
        1)
            clone_alfresco
            ;;
        2)
            clone_alfresco
            start_alfresco
            ;;
        3)
            stop_alfresco
            ;;
        4)
            restart_alfresco
            ;;
        5)
            status_alfresco
            ;;
        6)
            logs_alfresco
            ;;
        7)
            exit 0
            ;;
        *)
            error "Opção inválida"
            exit 1
            ;;
    esac
}

# Processar argumentos
case "${1}" in
    clone)
        clone_alfresco
        ;;
    start)
        clone_alfresco
        start_alfresco
        ;;
    stop)
        stop_alfresco
        ;;
    restart)
        restart_alfresco
        ;;
    status)
        status_alfresco
        ;;
    logs)
        logs_alfresco
        ;;
    *)
        show_menu
        ;;
esac

success "Operação concluída!"

