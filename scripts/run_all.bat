@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion

echo ============================================================
echo  INDEXATION AFFAIRE VPO vs OPO
echo ============================================================
echo.

:: Configuration
set "SOURCE_DIR=C:\Users\opochon\Documents\Affaire VPO vs OPO"
set "DB_FILE=vpo_affaire.db"

:: Vérifier Python
python --version >nul 2>&1
if errorlevel 1 (
    echo ERREUR: Python non trouvé dans le PATH
    pause
    exit /b 1
)

:: Installer les dépendances
echo [1/5] Installation des dépendances Python...
pip install extract-msg python-dateutil pymupdf python-docx pillow --quiet
if errorlevel 1 (
    echo ERREUR: Echec installation des packages
    pause
    exit /b 1
)
echo      OK
echo.

:: Vérifier le dossier source
if not exist "%SOURCE_DIR%" (
    echo ERREUR: Dossier source non trouvé:
    echo        %SOURCE_DIR%
    pause
    exit /b 1
)

:: Supprimer ancienne base si existe
if exist "%DB_FILE%" (
    echo [2/5] Suppression ancienne base...
    del "%DB_FILE%"
    echo      OK
)
echo.

:: Initialiser la base
echo [3/5] Initialisation de la base SQLite + FTS5...
python init_db.py "%DB_FILE%"
if errorlevel 1 (
    echo ERREUR: Echec initialisation base
    pause
    exit /b 1
)
echo.

:: Importer les emails
echo [4/5] Import des emails .msg...
echo      Source: %SOURCE_DIR%
echo      Cela peut prendre plusieurs minutes...
echo.
python ingest_msg.py "%SOURCE_DIR%" "%DB_FILE%"
if errorlevel 1 (
    echo ATTENTION: Erreurs lors de l'import des emails
)
echo.

:: Importer les documents
echo [5/5] Import des documents (PDF, DOCX, images)...
echo      OCR automatique si necessaire...
echo.
python ingest_docs.py "%SOURCE_DIR%" "%DB_FILE%"
if errorlevel 1 (
    echo ATTENTION: Erreurs lors de l'import des documents
)
echo.

:: Afficher les stats
echo ============================================================
echo  STATISTIQUES FINALES
echo ============================================================
python query_db.py --stats --db "%DB_FILE%"

echo.
echo ============================================================
echo  TERMINE
echo ============================================================
echo.
echo Base creee: %DB_FILE%
echo.
echo Exemples de recherche:
echo   python query_db.py "pension alimentaire"
echo   python query_db.py "expertise" --type email
echo   python query_db.py --detail email:1
echo.
echo Pour utiliser avec Claude:
echo   Uploadez le fichier %DB_FILE% dans la conversation
echo.

pause
