# ── Build stage para otimização ────────────────────
FROM php@sha256:aeb40b9198b799fb8050c63e34a82dfb1212efeff46510d6b8f5b5d9bfc72552 AS base

# Definir como non-interactive para o apt-get
ENV DEBIAN_FRONTEND=noninteractive

# Instalar extensões nativamente usando as melhores práticas
# Remove dependências inúteis após o build para imagem menor e segura
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype-dev \
    libonig-dev \
    libzip-dev \
    pkg-config \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli gd mbstring opcache \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Ativar módulos necessários no Apache
RUN a2enmod rewrite headers

# Mapeamento do Apache para porta 8080 (Non-root compliance)
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf 
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf

# Mover configurações
COPY docker/php.ini $PHP_INI_DIR/conf.d/99-pascom.ini
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf

# Criar os diretórios e abrir runtime dirs pro Apache girar livre de root PID
RUN mkdir -p /var/www/html/img/usuarios /var/www/html/img/tipos_atividade /var/run/apache2 /var/log/apache2 \
    && chown -R www-data:www-data /var/www/html /var/run/apache2 /var/log/apache2

# Mudar para o usuário não-root (UID 33 -> www-data)
USER www-data
EXPOSE 8080

# Healthcheck interno
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost:8080/login.php || exit 1

# ── Estágio Produção (Blindado e Estático) ────────────────────
FROM base AS production
COPY --chown=www-data:www-data . /var/www/html
RUN rm -f /var/www/html/.env.example /var/www/html/docker/php-dev.ini

# ── Estágio Desenvolvimento (Live Reload Roteado) ────────────────────
FROM base AS dev
COPY docker/php-dev.ini $PHP_INI_DIR/conf.d/99-pascom-dev.ini
