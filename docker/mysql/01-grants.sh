#!/bin/sh
# Permite ao usuário da aplicação criar os bancos dos clientes (prefixo cli_).
set -e

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
GRANT ALL PRIVILEGES ON \`${TENANT_DB_PREFIX:-cli_}%\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
