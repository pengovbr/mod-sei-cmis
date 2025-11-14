#!/bin/bash

# Script principal de gestão - CMIS API
# Gerencia Alfresco e API PHP

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

# Verificar Docker
check_docker() {
    if ! command -v docker &> /dev/null || ! command -v docker-compose &> /dev/null; then
        error "Docker ou Docker Compose não está instalado!"
        echo ""
        echo "Instale em: https://docs.docker.com/get-docker/"
        exit 1
    fi
}

# Verificar Alfresco
check_alfresco() {
    if ! docker ps | grep -q alfresco; then
        warning "Alfresco não está rodando!"
        echo ""
        read -p "Deseja iniciar o Alfresco agora? (s/n): " choice
        
        if [ "$choice" = "s" ] || [ "$choice" = "S" ]; then
            info "Iniciando Alfresco..."
            bash manage_alfresco.sh start
            echo ""
            info "Aguardando Alfresco inicializar (isso pode levar 2-3 minutos)..."
            sleep 30
            success "Alfresco está pronto!"
        else
            error "Você precisa do Alfresco rodando para usar a API"
            exit 1
        fi
    else
        success "Alfresco está rodando"
    fi
}

# Instalar dependências PHP
install_php() {
    if [ ! -d "vendor" ]; then
        info "Instalando dependências PHP..."
        
        if [ ! -f "composer.phar" ]; then
            curl -sS https://getcomposer.org/installer | php
        fi
        
        php composer.phar install --no-interaction
        success "Dependências instaladas"
    fi
}

# Construir API
build_api() {
    check_docker
    
    info "Construindo imagem Docker da API..."
    docker-compose build
    
    success "Imagem construída com sucesso"
}

# Iniciar API
start_api() {
    check_docker
    
    info "Iniciando API CMIS..."
    docker-compose up -d cmis-api

    success "API está rodando em http://localhost"
}

# Parar API
stop_api() {
    docker-compose stop cmis-api
    success "API parada"
}

# Ver status
status() {
    echo ""
    echo "=== Status dos Serviços ==="
    echo ""
    
    # API
    if docker ps | grep -q cmis-api; then
        success "API CMIS: Rodando (http://localhost)"
    else
        warning "API CMIS: Parada"
    fi
    
    echo ""
    
    # Alfresco
    if docker ps | grep -q alfresco; then
        success "Alfresco: Rodando (http://localhost:8080)"
        info "URL CMIS: http://localhost:8080/alfresco/api/-default-/public/cmis/versions/1.1/browser"
    else
        warning "Alfresco: Parado"
        info "Para iniciar: bash manage_alfresco.sh start"
    fi
}

# Menu
show_menu() {
    echo ""
    echo "┌─────────────────────────────────────────────┐"
    echo "│         CMIS API - Menu Principal              │"
    echo "└─────────────────────────────────────────────┘"
    echo ""
    echo "1) Construir imagem Docker (build)"
    echo "2) Iniciar API CMIS"
    echo "3) Parar API CMIS"
    echo "4) Ver status"
    echo "5) Iniciar Alfresco"
    echo "6) Parar Alfresco"
    echo "7) Instalar dependências PHP"
    echo "8) Setup completo (instalar tudo)"
    echo "9) Sair"
    echo ""
    read -p "Escolha uma opção: " option
    
    case $option in
        1)
            build_api
            ;;
        2)
            check_docker
            start_api
            ;;
        3)
            stop_api
            ;;
        4)
            status
            ;;
        5)
            bash manage_alfresco.sh start
            ;;
        6)
            bash manage_alfresco.sh stop
            ;;
        7)
            install_php
            ;;
        8)
            check_docker
            build_api
            install_php
            bash manage_alfresco.sh start
            sleep 30
            start_api
            success "Setup completo! Tudo está rodando"
            ;;
        9)
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
    build)
        check_docker
        build_api
        ;;
    start)
        check_docker
        check_alfresco
        start_api
        ;;
    stop)
        stop_api
        ;;
    status)
        status
        ;;
    alfresco-start)
        bash manage_alfresco.sh start
        ;;
    alfresco-stop)
        bash manage_alfresco.sh stop
        ;;
    *)
        show_menu
        ;;
esac

