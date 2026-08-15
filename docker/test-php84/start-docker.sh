#!/bin/bash
cd "$(dirname "$0")"

if docker ps -a --format '{{.Names}}' | grep -q '^duckcoverage-test84$'; then
    echo "duckcoverage-test84 already exists"
    exit 0
fi

docker compose run -d --name duckcoverage-test84 --use-aliases duckcoverage84 tail -f /dev/null

echo "duckcoverage-test84 started"
echo "hint: ./exec-docker.sh composer install --no-interaction --prefer-dist"
echo "      ./exec-docker.sh php -l src/DuckCoverage.php"
