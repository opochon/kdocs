#!/bin/bash
# K-Docs - Arrêter OnlyOffice Document Server
cd "$(dirname "$0")"
echo "Arrêt OnlyOffice Document Server..."
docker-compose down
echo "OnlyOffice arrêté."
