#!/bin/bash
cd "$(dirname "$0")"

if docker ps --format '{{.Names}}' | grep -q '^duckcoverage-test84$'; then
    docker stop duckcoverage-test84
    echo "duckcoverage-test84 stopped"
else
    echo "duckcoverage-test84 is not running"
fi
