#!/bin/bash
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log()     { echo -e "${GREEN}[✔]${NC} $1"; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
info()    { echo -e "${BLUE}[→]${NC} $1"; }
error()   { echo -e "${RED}[✘]${NC} $1"; exit 1; }
section() { echo -e "\n${CYAN}${BOLD}══════════════════════════════════════${NC}"; \
            echo -e "${CYAN}${BOLD}  $1${NC}"; \
            echo -e "${CYAN}${BOLD}══════════════════════════════════════${NC}\n"; }

SCRIPT_PATH="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
PROJETO_DIR="$(cd "$(dirname "$0")" && pwd)"

MINHA_IP=$(curl -s --max-time 5 https://api.ipify.org 2>/dev/null || \
           curl -s --max-time 5 http://ifconfig.me 2>/dev/null || \
           hostname -I | awk '{print $1}')

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║        SKYGUARD — Setup Automático       ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════╝${NC}"
echo ""
info "IP detectado: ${BOLD}$MINHA_IP${NC}"
info "Pasta do projeto: ${BOLD}$PROJETO_DIR${NC}"
echo ""

section "ETAPA 1 — Limpeza de espaço"

info "Verificando espaço em disco..."
df -h / | tail -1

info "Garantindo dependências básicas (curl, openssl)..."
sudo apt-get update -qq
sudo apt-get install -y -qq curl openssl

info "Removendo pacotes desnecessários..."
sudo apt-get autoremove -y -qq 2>/dev/null || true
sudo apt-get autoclean -qq 2>/dev/null || true
sudo journalctl --vacuum-size=50M 2>/dev/null || true
sudo rm -rf /tmp/* 2>/dev/null || true

if command -v docker &>/dev/null; then
    info "Limpando cache do Docker..."
    docker system prune -af --volumes 2>/dev/null || true
fi

log "Espaço após limpeza:"
df -h / | tail -1

section "ETAPA 2 — Instalação do Docker"

if command -v docker &>/dev/null; then
    log "Docker já instalado: $(docker --version)"
else
    info "Instalando Docker..."
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$USER"
    log "Docker instalado"
fi

if docker compose version &>/dev/null 2>&1; then
    log "Docker Compose disponível: $(docker compose version)"
else
    info "Instalando Docker Compose plugin..."
    sudo apt-get install -y -qq docker-compose-plugin
    log "Docker Compose instalado"
fi

if ! docker ps &>/dev/null; then
    warn "Aplicando permissões Docker (requer re-execução)..."
    sudo usermod -aG docker "$USER"
    exec sg docker "$SCRIPT_PATH"
fi

log "Docker funcionando corretamente"

section "ETAPA 3 — Projeto encontrado via Git"

cd "$PROJETO_DIR"
log "Usando projeto em: $PROJETO_DIR"

section "ETAPA 4 — Configurando SSL (HTTPS)"

info "Gerando certificado SSL para IP: $MINHA_IP"
mkdir -p docker/ssl

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout docker/ssl/skyguard.key \
    -out    docker/ssl/skyguard.crt \
    -subj "/C=BR/ST=SP/L=SaoPaulo/O=SkyGuard/CN=${MINHA_IP}" \
    2>/dev/null

log "Certificado SSL gerado"

cat > docker/apache.conf << EOF
<VirtualHost *:80>
    ServerName ${MINHA_IP}
    RewriteEngine On
    RewriteRule ^(.*)$ https://%{HTTP_HOST}\$1 [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile    /etc/apache2/ssl/skyguard.crt
    SSLCertificateKeyFile /etc/apache2/ssl/skyguard.key

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        <FilesMatch "\.(env|sql|lock|md)$">
            Require all denied
        </FilesMatch>
        <Files "manifest.json">
            Require all granted
        </Files>
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"

    ErrorLog  \${APACHE_LOG_DIR}/skyguard_error.log
    CustomLog \${APACHE_LOG_DIR}/skyguard_access.log combined
</VirtualHost>
EOF

log "apache.conf configurado"

cat > Dockerfile << 'DOCKERFILE'
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl-dev \
        libonig-dev \
    && docker-php-ext-install \
        mysqli \
        pdo_mysql \
        mbstring \
    && docker-php-ext-enable \
        mysqli \
        mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers ssl
RUN mkdir -p /etc/apache2/ssl

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/ssl/skyguard.crt /etc/apache2/ssl/skyguard.crt
COPY docker/ssl/skyguard.key /etc/apache2/ssl/skyguard.key

COPY app/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80 443
DOCKERFILE

log "Dockerfile atualizado"

cat > docker-compose.yml << 'COMPOSE'
version: '3.9'

services:

  db:
    image: mysql:8.0
    container_name: skyguard_db
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE:      ${DB_NAME}
      MYSQL_USER:          ${DB_USER}
      MYSQL_PASSWORD:      ${DB_PASS}
    volumes:
      - db_data:/var/lib/mysql
      - ./skyguard_db.sql:/docker-entrypoint-initdb.d/skyguard_db.sql:ro
    networks:
      - skyguard_net
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p${DB_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 15
      start_period: 60s

  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: skyguard_app
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      DB_HOST:        db
      DB_USER:        ${DB_USER}
      DB_PASS:        ${DB_PASS}
      DB_NAME:        ${DB_NAME}
      MAIL_HOST:      ${MAIL_HOST}
      MAIL_PORT:      ${MAIL_PORT}
      MAIL_USERNAME:  ${MAIL_USERNAME}
      MAIL_PASSWORD:  ${MAIL_PASSWORD}
      MAIL_FROM_NAME: ${MAIL_FROM_NAME}
      MAIL_RECIPIENT: ${MAIL_RECIPIENT}
      CORS_ORIGIN:    ${CORS_ORIGIN:-*}
      ESP_TOKEN:      ${ESP_TOKEN:-skyguard_secret_token}
    ports:
      - "80:80"
      - "443:443"
    networks:
      - skyguard_net

  phpmyadmin:
    image: phpmyadmin:5
    container_name: skyguard_phpmyadmin
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      PMA_HOST:     db
      PMA_USER:     root
      PMA_PASSWORD: ${DB_ROOT_PASSWORD}
    ports:
      - "8080:80"
    networks:
      - skyguard_net

volumes:
  db_data:

networks:
  skyguard_net:
    driver: bridge
COMPOSE

log "docker-compose.yml atualizado"

cat > .env << 'ENVEOF'
DB_ROOT_PASSWORD=SkyGuard@Root2025!
DB_NAME=skyguard_db
DB_USER=skyuser
DB_PASS=SkyGuard@2025!

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=ryanfguedes14@gmail.com
MAIL_PASSWORD=frix kqnf mnff yfpi
MAIL_FROM_NAME=SkyGuard Sistema
MAIL_RECIPIENT=ryanfonsecaguedes@gmail.com

CORS_ORIGIN=*
ESP_TOKEN=skyguard_secret_token
ENVEOF

log ".env configurado"

section "ETAPA 5 — Subindo os containers"

info "Fazendo build da imagem..."
docker compose build --no-cache

info "Iniciando containers..."
docker compose up -d

info "Aguardando banco de dados inicializar..."
TENTATIVAS=0
MAX_TENTATIVAS=36
until [ "$(docker inspect --format='{{.State.Health.Status}}' skyguard_db 2>/dev/null)" = "healthy" ]; do
    TENTATIVAS=$((TENTATIVAS + 1))
    if [ $TENTATIVAS -ge $MAX_TENTATIVAS ]; then
        warn "Banco demorou mais que o esperado. Logs:"
        docker compose logs db --tail=15
        break
    fi
    echo -n "."
    sleep 5
done
echo ""
log "Banco de dados pronto!"

info "Status dos containers:"
docker compose ps

section "ETAPA 6 — Verificação final"

HTTP_CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://localhost/login.html" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    log "Site HTTPS respondendo corretamente (HTTP $HTTP_CODE)"
else
    warn "Site retornou HTTP $HTTP_CODE. Aguardando mais 15s..."
    sleep 15
    HTTP_CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://localhost/login.html" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        log "Site OK na segunda tentativa (HTTP $HTTP_CODE)"
    else
        warn "Logs do app:"
        docker compose logs app --tail=25
    fi
fi

REDIRECT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/login.html" 2>/dev/null || echo "000")
if [ "$REDIRECT_CODE" = "301" ] || [ "$REDIRECT_CODE" = "302" ]; then
    log "Redirecionamento HTTP -> HTTPS funcionando"
else
    warn "Redirect retornou $REDIRECT_CODE (esperado 301)"
fi

DB_STATUS=$(docker inspect --format='{{.State.Health.Status}}' skyguard_db 2>/dev/null || echo "unknown")
[ "$DB_STATUS" = "healthy" ] && log "Banco saudavel" || warn "Status do banco: $DB_STATUS"

section "INSTALACAO CONCLUIDA"

echo ""
echo -e "  ${GREEN}Site: https://${MINHA_IP}/login.html${NC}"
echo -e "  ${GREEN}phpMyAdmin: http://${MINHA_IP}:8080${NC}"
echo ""
echo -e "  Admin : admin@skyguard.com  /  admin123"
echo -e "  User  : user@skyguard.com   /  user123"
echo ""
echo -e "${YELLOW}  No navegador: Avancado -> Continuar para o site${NC}"
echo ""
echo -e "  Portas para abrir no Azure NSG: 80, 443, 8080"
echo ""
