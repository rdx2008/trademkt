#!/bin/sh
set -e

cd /var/www/html

# O volume de storage pode chegar vazio no primeiro deploy.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs storage/clientes
chown -R www-data:www-data storage bootstrap/cache

# Só o container web roda migrações (worker e scheduler só esperam).
if [ "${CONTAINER_ROLE:-web}" = "web" ]; then
    su-exec www-data php artisan migrate --force
    su-exec www-data php artisan tenants:migrate --force
    su-exec www-data php artisan db:seed --force
fi

su-exec www-data php artisan config:cache
su-exec www-data php artisan view:cache

# supervisord (web) roda como root e rebaixa cada programa;
# worker e scheduler rodam direto como www-data.
if [ "$1" = "supervisord" ]; then
    exec "$@"
fi

exec su-exec www-data "$@"
