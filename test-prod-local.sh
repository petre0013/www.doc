#!/bin/bash
set -e

echo "Starting local production build..."

# Create a temporary override file to build from local source instead of pulling from GHCR
cat << 'OVERRIDE' > docker-compose.override.prod.yml
services:
  app:
    image: ""
    build:
      context: .
      dockerfile: docker/app/Dockerfile
      target: app
  web:
    image: ""
    build:
      context: .
      dockerfile: docker/app/Dockerfile
      target: web
OVERRIDE

echo "Building images..."
docker compose -f docker-compose.prod.yml -f docker-compose.override.prod.yml build

echo "Starting containers..."
docker compose -f docker-compose.prod.yml -f docker-compose.override.prod.yml up -d

echo "Production environment is now running locally on port 8080."
echo "To stop, run: docker compose -f docker-compose.prod.yml -f docker-compose.override.prod.yml down"
