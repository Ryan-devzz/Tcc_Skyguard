FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl-dev \
        libonig-dev \
        unzip \
        curl \
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

# Instala o Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/ssl/skyguard.crt /etc/apache2/ssl/skyguard.crt
COPY docker/ssl/skyguard.key /etc/apache2/ssl/skyguard.key

COPY app/ /var/www/html/

# Instala dependências PHP (PHPMailer)
RUN cd /var/www/html && composer require phpmailer/phpmailer --no-interaction

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80 443
