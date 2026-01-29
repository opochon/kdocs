#!/bin/bash
# K-Docs - Démarrer OnlyOffice Document Server
cd "$(dirname "$0")"
echo "Démarrage OnlyOffice Document Server..."
docker-compose up -d
echo ""
echo "OnlyOffice démarré sur http://localhost:8080"
echo "Test: curl http://localhost:8080/healthcheck"
