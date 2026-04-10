#!/bin/bash
# ============================================================
#  SKYGUARD — Script de instalação automática
#  Execute na VM Azure com:
#    bash setup.sh
# ============================================================

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

# FIX 1: Resolve caminho absoluto do script para o exec sg funcionar
SCRIPT_PATH="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"

# Detecta IP público da VM
MINHA_IP=$(curl -s --max-time 5 https://api.ipify.org 2>/dev/null || \
           curl -s --max-time 5 http://ifconfig.me 2>/dev/null || \
           hostname -I | awk '{print $1}')

PROJETO_DIR="$HOME/skyguard-docker"

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║        SKYGUARD — Setup Automático       ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════╝${NC}"
echo ""
info "IP detectado: ${BOLD}$MINHA_IP${NC}"
echo ""

# ============================================================
section "ETAPA 1 — Limpeza de espaço"
# ============================================================

info "Verificando espaço em disco..."
df -h / | tail -1

# FIX 2: Instala unzip e curl que podem não estar no Ubuntu mínimo
info "Garantindo dependências básicas (unzip, curl)..."
sudo apt-get update -qq
sudo apt-get install -y -qq unzip curl

info "Removendo pacotes desnecessários..."
sudo apt-get autoremove -y -qq 2>/dev/null || true
sudo apt-get autoclean -qq 2>/dev/null || true

info "Limpando logs antigos do sistema..."
sudo journalctl --vacuum-size=50M 2>/dev/null || true

info "Limpando /tmp..."
sudo rm -rf /tmp/* 2>/dev/null || true

if command -v docker &>/dev/null; then
    info "Limpando cache do Docker..."
    docker system prune -af --volumes 2>/dev/null || true
fi

log "Espaço após limpeza:"
df -h / | tail -1

# ============================================================
section "ETAPA 2 — Instalação do Docker"
# ============================================================

if command -v docker &>/dev/null; then
    log "Docker já está instalado: $(docker --version)"
else
    info "Instalando Docker..."
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$USER"
    log "Docker instalado com sucesso"
fi

if docker compose version &>/dev/null 2>&1; then
    log "Docker Compose já disponível: $(docker compose version)"
else
    info "Instalando Docker Compose plugin..."
    sudo apt-get install -y -qq docker-compose-plugin
    log "Docker Compose instalado"
fi

# FIX 2: Usa caminho absoluto no exec sg para não quebrar
if ! docker ps &>/dev/null; then
    warn "Aplicando permissões Docker (requer re-execução do script)..."
    sudo usermod -aG docker "$USER"
    exec sg docker "$SCRIPT_PATH"
fi

log "Docker funcionando corretamente"

# ============================================================
section "ETAPA 3 — Preparando o projeto"
# ============================================================

ZIP_PATH=""
for f in "$HOME/skyguard-docker.zip" "$(dirname "$SCRIPT_PATH")/skyguard-docker.zip" "$(pwd)/skyguard-docker.zip"; do
    if [ -f "$f" ]; then
        ZIP_PATH="$f"
        break
    fi
done

if [ -z "$ZIP_PATH" ]; then
    error "Arquivo skyguard-docker.zip não encontrado.\nEnvie o zip para a VM antes de rodar este script:\n  scp skyguard-docker.zip azureuser@<IP>:~/"
fi

info "Zip encontrado: $ZIP_PATH"

if [ -d "$PROJETO_DIR" ]; then
    warn "Pasta $PROJETO_DIR já existe. Fazendo backup..."
    mv "$PROJETO_DIR" "${PROJETO_DIR}_backup_$(date +%Y%m%d_%H%M%S)"
fi

info "Extraindo projeto..."
cd "$HOME"
unzip -q "$ZIP_PATH"
cd "$PROJETO_DIR"
log "Projeto extraído em $PROJETO_DIR"

# ============================================================
section "ETAPA 4 — Configurando SSL (HTTPS)"
# ============================================================

info "Gerando certificado SSL autoassinado para IP: $MINHA_IP"

mkdir -p docker/ssl

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout docker/ssl/skyguard.key \
    -out    docker/ssl/skyguard.crt \
    -subj "/C=BR/ST=SP/L=SaoPaulo/O=SkyGuard/CN=${MINHA_IP}" \
    2>/dev/null

log "Certificado SSL gerado"

# Reescreve apache.conf com HTTP redirect + HTTPS
cat > docker/apache.conf << EOF
# HTTP -> redireciona para HTTPS
<VirtualHost *:80>
    ServerName ${MINHA_IP}
    RewriteEngine On
    RewriteRule ^(.*)$ https://%{HTTP_HOST}\$1 [R=301,L]
</VirtualHost>

# HTTPS
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

# Reescreve Dockerfile com SSL
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

log "Dockerfile atualizado com SSL"

# Reescreve docker-compose.yml com porta 443
# FIX 3: start_period aumentado para 60s e retries para 15 (MySQL demora no primeiro boot)
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

# Garante .env com single-quote heredoc (sem expansão de variáveis bash)
cat > .env << 'ENVEOF'
# SKYGUARD — Variáveis de Ambiente
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

# ============================================================
section "ETAPA 5 — Subindo os containers"
# ============================================================

info "Fazendo build da imagem (pode demorar 1-2 minutos)..."
docker compose build --no-cache

info "Iniciando containers..."
docker compose up -d

# FIX 3: Aguarda o banco ficar HEALTHY de verdade em vez de sleep fixo
info "Aguardando banco de dados inicializar (pode levar até 2 minutos na primeira vez)..."
TENTATIVAS=0
MAX_TENTATIVAS=36
until [ "$(docker inspect --format='{{.State.Health.Status}}' skyguard_db 2>/dev/null)" = "healthy" ]; do
    TENTATIVAS=$((TENTATIVAS + 1))
    if [ $TENTATIVAS -ge $MAX_TENTATIVAS ]; then
        warn "Banco demorou mais que o esperado. Verificando logs..."
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

# ============================================================
section "ETAPA 6 — Verificação final"
# ============================================================

# Testa HTTPS
HTTP_CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://localhost/login.html" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    log "Site HTTPS respondendo corretamente (HTTP $HTTP_CODE)"
else
    warn "Site retornou HTTP $HTTP_CODE. Aguardando mais 15s..."
    sleep 15
    HTTP_CODE=$(curl -sk -o /dev/null -w "%{http_code}" "https://localhost/login.html" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        log "Site respondendo na segunda tentativa (HTTP $HTTP_CODE)"
    else
        warn "Problema detectado. Logs do app:"
        docker compose logs app --tail=25
    fi
fi

# Testa redirect HTTP -> HTTPS
REDIRECT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/login.html" 2>/dev/null || echo "000")
if [ "$REDIRECT_CODE" = "301" ] || [ "$REDIRECT_CODE" = "302" ]; then
    log "Redirecionamento HTTP → HTTPS funcionando (HTTP $REDIRECT_CODE)"
else
    warn "Redirecionamento HTTP retornou $REDIRECT_CODE (esperado 301)"
fi

# Status do banco
DB_STATUS=$(docker inspect --format='{{.State.Health.Status}}' skyguard_db 2>/dev/null || echo "unknown")
if [ "$DB_STATUS" = "healthy" ]; then
    log "Banco de dados saudável"
else
    warn "Status do banco: $DB_STATUS — verifique com: docker compose logs db"
fi

# ============================================================
section "INSTALACAO CONCLUIDA"
# ============================================================

echo ""
echo -e "${BOLD}  Acesse o SkyGuard:${NC}"
echo -e "  ${GREEN}https://${MINHA_IP}/login.html${NC}"
echo ""
echo -e "${BOLD}  phpMyAdmin:${NC}"
echo -e "  ${GREEN}http://${MINHA_IP}:8080${NC}"
echo ""
echo -e "${BOLD}  Logins padrao:${NC}"
echo -e "  Admin : admin@skyguard.com  /  admin123"
echo -e "  User  : user@skyguard.com   /  user123"
echo ""
echo -e "${YELLOW}  ATENCAO: Ao abrir no navegador, clique em${NC}"
echo -e "${YELLOW}  'Avancado -> Continuar para o site'${NC}"
echo -e "${YELLOW}  (certificado autoassinado - normal para TCC)${NC}"
echo ""
echo -e "${BOLD}  Portas que devem estar abertas no Azure NSG:${NC}"
echo -e "  22   -> SSH"
echo -e "  80   -> HTTP  (redireciona para HTTPS)"
echo -e "  443  -> HTTPS (site principal)"
echo -e "  8080 -> phpMyAdmin"
echo ""
echo -e "${BOLD}  Comandos uteis:${NC}"
echo -e "  docker compose logs -f        # logs em tempo real"
echo -e "  docker compose ps             # status dos containers"
echo -e "  docker compose restart app    # reiniciar aplicacao"
echo -e "  docker compose down           # parar tudo"
echo ""
