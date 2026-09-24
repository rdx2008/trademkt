FROM mysql:8.4

# Roda só na primeira inicialização (volume vazio).
COPY 01-grants.sh /docker-entrypoint-initdb.d/01-grants.sh
