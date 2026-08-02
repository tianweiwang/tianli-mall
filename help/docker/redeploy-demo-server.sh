#!/bin/sh
set -eu

cd "$(dirname "$0")"

chgrp 33 .env.application .constant
chmod 0640 .env.application .constant

docker compose --env-file .env.runtime -f docker-compose.demo-server.yml up -d --wait
docker compose --env-file .env.runtime -f docker-compose.demo-server.yml restart nginx
docker compose --env-file .env.runtime -f docker-compose.demo-server.yml ps
