FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache bash git unzip openssh-client

RUN install-php-extensions pcntl pdo_mysql pdo_pgsql gd intl zip bcmath redis

WORKDIR /app

# Instala o composer primeiro
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 1. Copia APENAS os arquivos de gerenciamento de dependências
COPY composer.json composer.lock /app/

# 2. Instala as dependências MAS pula a geração do autoloader (--no-autoloader)
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader

# 3. Copia o resto do projeto (agora as pastas app/ e database/ entram no contêiner)
COPY . /app

# 4. Agora sim, gera o autoloader otimizado com todas as pastas presentes
RUN composer dump-autoload --optimize

# 5. Ajusta permissões e roda os scripts finais do Laravel
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache
RUN composer run-script post-autoload-dump

ENV FRANKENPHP_DOCUMENT_ROOT=/app/public
EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
