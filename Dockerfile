# ── Build Stage ──────────────────────────────────────────────────────────────
FROM php:8.2-apache AS base

# Extensões necessárias para MySQL + PHPMailer (openssl, mbstring)
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

# Habilita mod_rewrite para URLs limpas (Apache)
RUN a2enmod rewrite headers

# Configuração Apache: DocumentRoot, AllowOverride e headers de segurança
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copia a aplicação
COPY app/ /var/www/html/

# Permissões corretas
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
