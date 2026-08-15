#!/bin/bash
cd "$(dirname "$0")"

if docker ps --format '{{.Names}}' | grep -q '^duckcoverage-test84$'; then
    docker stop duckcoverage-test84
fi

if docker ps -a --format '{{.Names}}' | grep -q '^duckcoverage-test84$'; then
    docker rm duckcoverage-test84
    echo "duckcoverage-test84 removed"
else
    echo "duckcoverage-test84 not found"
fi
