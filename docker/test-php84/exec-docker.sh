#!/bin/bash
cd "$(dirname "$0")"

if ! docker ps --format '{{.Names}}' | grep -q '^duckcoverage-test84$'; then
    echo "duckcoverage-test84 is not running, run ./start-docker.sh first"
    exit 1
fi

docker exec -w /DATA duckcoverage-test84 "$@"
