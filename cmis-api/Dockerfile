FROM php:8.2-apache

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    vim \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libjpeg-dev \
    libfreetype6-dev \
    unzip \
    libicu-dev \
    libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP necessárias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo mysqli zip gd intl soap

# Habilitar mod_rewrite e mod_headers do Apache
RUN a2enmod rewrite && a2enmod headers

# Configurar ServerName do Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Configurar Apache
COPY .htaccess /var/www/html/.htaccess

# Copiar aplicação
COPY . /var/www/html/

# Criar .env se não existir
RUN if [ ! -f /var/www/html/.env ]; then \
    cp /var/www/html/env.example /var/www/html/.env; \
    fi

# Definir permissões
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar dependências PHP
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# Criar diretório de logs
RUN mkdir -p /var/www/html/logs && \
    chown -R www-data:www-data /var/www/html/logs

EXPOSE 80

WORKDIR /var/www/html

CMD ["apache2-foreground"]

