#!/bin/bash
# K-Docs Smoke Test - Script Linux/macOS
# Usage: ./run_smoke_test.sh [--headless]

echo "============================================"
echo "K-DOCS SMOKE TEST"
echo "============================================"

cd "$(dirname "$0")"

# Vérifier Python
if ! command -v python3 &> /dev/null; then
    echo "[ERREUR] Python3 non trouvé. Installez Python 3.8+"
    exit 1
fi

# Installer les dépendances si nécessaire
echo ""
echo "[INFO] Vérification des dépendances..."
if ! python3 -c "import selenium" 2>/dev/null; then
    echo "[INFO] Installation de selenium..."
    pip3 install selenium webdriver-manager
fi

# Lancer le test
echo ""
echo "[INFO] Lancement du smoke test..."
echo ""

if [ "$1" == "--headless" ]; then
    python3 smoke_test.py --headless
else
    python3 smoke_test.py
fi

echo ""
echo "============================================"
echo "[INFO] Résultats dans: tests/smoke_test_results/"
echo "============================================"
