@echo off
REM K-Docs Smoke Test - Batch Windows
REM Usage: run_smoke_test.bat [--headless]

echo ============================================
echo K-DOCS SMOKE TEST
echo ============================================

cd /d "%~dp0"

REM Vérifier Python
python --version >nul 2>&1
if errorlevel 1 (
    echo [ERREUR] Python non trouvé. Installez Python 3.8+
    pause
    exit /b 1
)

REM Installer les dépendances si nécessaire
echo.
echo [INFO] Vérification des dépendances...
pip show selenium >nul 2>&1
if errorlevel 1 (
    echo [INFO] Installation de selenium...
    pip install selenium webdriver-manager
)

REM Lancer le test
echo.
echo [INFO] Lancement du smoke test...
echo.

if "%1"=="--headless" (
    python smoke_test.py --headless
) else (
    python smoke_test.py
)

echo.
echo ============================================
echo [INFO] Résultats dans: tests\smoke_test_results\
echo ============================================
pause
