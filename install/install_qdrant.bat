@echo off
chcp 65001 >nul 2>&1
setlocal EnableDelayedExpansion

echo ============================================================
echo   K-Docs - Installation Qdrant Vector Database
echo ============================================================
echo.

REM Vérifier Docker
echo [1/4] Vérification Docker...
docker --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Docker n'est pas installé ou pas dans le PATH.
    echo Installez Docker Desktop: https://www.docker.com/products/docker-desktop
    pause
    exit /b 1
)
echo       Docker OK

REM Vérifier que Docker tourne
docker info >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Docker Desktop n'est pas démarré.
    echo Lancez Docker Desktop et réessayez.
    pause
    exit /b 1
)
echo       Docker Desktop running

REM Créer le dossier de stockage
echo.
echo [2/4] Création du dossier de stockage...
if not exist "%~dp0..\storage\qdrant" mkdir "%~dp0..\storage\qdrant"
echo       storage\qdrant OK

REM Télécharger l'image Qdrant
echo.
echo [3/4] Téléchargement de l'image Qdrant (peut prendre quelques minutes)...
docker pull qdrant/qdrant:latest
if %errorlevel% neq 0 (
    echo [ERREUR] Impossible de télécharger l'image Qdrant.
    echo Vérifiez votre connexion internet et les credentials Docker.
    pause
    exit /b 1
)
echo       Image téléchargée

REM Créer et démarrer le conteneur
echo.
echo [4/4] Création du conteneur Qdrant...

REM Supprimer l'ancien conteneur s'il existe
docker rm -f kdocs-qdrant >nul 2>&1

REM Créer le nouveau conteneur
docker run -d ^
    --name kdocs-qdrant ^
    --restart unless-stopped ^
    -p 6333:6333 ^
    -p 6334:6334 ^
    -v "%~dp0..\storage\qdrant:/qdrant/storage" ^
    qdrant/qdrant:latest

if %errorlevel% neq 0 (
    echo [ERREUR] Impossible de créer le conteneur.
    pause
    exit /b 1
)

echo       Conteneur créé

REM Attendre le démarrage
echo.
echo Attente du démarrage de Qdrant...
timeout /t 10 /nobreak >nul

REM Vérifier la santé
curl -s http://localhost:6333/health >nul 2>&1
if %errorlevel% equ 0 (
    echo.
    echo ============================================================
    echo   [OK] Qdrant installé et démarré avec succès !
    echo ============================================================
    echo.
    echo   REST API:   http://localhost:6333
    echo   gRPC:       localhost:6334
    echo   Dashboard:  http://localhost:6333/dashboard
    echo.
    echo   Commandes utiles:
    echo     - Démarrer:  docker start kdocs-qdrant
    echo     - Arrêter:   docker stop kdocs-qdrant
    echo     - Logs:      docker logs kdocs-qdrant
    echo     - Status:    curl http://localhost:6333/health
    echo.
) else (
    echo.
    echo [ATTENTION] Qdrant démarre en arrière-plan.
    echo Attendez quelques secondes puis testez: curl http://localhost:6333/health
)

pause
