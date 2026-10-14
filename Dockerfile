FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Só o que o projeto usa e não vem na imagem oficial (opcache já vem). Compilar extensões
# pesadas (intl, gd, redis) fazia o build passar de 3 minutos e ser interrompido no VPS.
# O Redis é acessado pelo predis (PHP puro), sem extensão.
RUN apk add --no-cache nginx supervisor su-exec \
    && install-php-extensions pdo_mysql zip pcntl

WORKDIR /var/www/html

# Dependências primeiro (aproveita cache do build)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
