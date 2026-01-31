@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul 2>&1

REM ============================================================
REM K-Docs - Configuration des services Docker
REM (OnlyOffice + Qdrant)
REM ============================================================

set SCRIPT_DIR=%~dp0
set KDOCS_ROOT=%SCRIPT_DIR%..\..

echo.
echo ══════════════════════════════════════════════════════════
echo   Configuration des services Docker K-Docs
echo ══════════════════════════════════════════════════════════
echo.
echo Services:
echo   - OnlyOffice Document Server (prévisualisation Office)
echo   - Qdrant Vector Database (recherche sémantique)
echo.

REM Trouver Docker
call "%SCRIPT_DIR%find-docker.bat"
if %errorlevel% neq 0 (
    echo [ERREUR] Docker non trouvé.
    echo.
    echo Installez Docker Desktop: https://www.docker.com/products/docker-desktop/
    echo Ou exécutez: installer\scripts\install-docker.bat
    echo.
    pause
    exit /b 1
)
echo [OK] Docker trouvé: %DOCKER_EXE%
echo.

REM Vérifier que Docker daemon tourne
echo Vérification du daemon Docker...
%DOCKER_EXE% info >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Docker Desktop n'est pas démarré.
    echo.
    echo 1. Lancez Docker Desktop depuis le menu Démarrer
    echo 2. Attendez que l'icône baleine soit stable
    echo 3. Relancez ce script
    echo.
    
    REM Tenter de lancer Docker Desktop
    echo Tentative de lancement automatique...
    start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe" 2>nul
    if !errorlevel! equ 0 (
        echo Docker Desktop lancé. Patientez 60 secondes...
        timeout /t 60 /nobreak
        %DOCKER_EXE% info >nul 2>&1
        if !errorlevel! neq 0 (
            echo [ERREUR] Docker n'a pas démarré. Lancez-le manuellement.
            pause
            exit /b 1
        )
    ) else (
        pause
        exit /b 1
    )
)
echo [OK] Docker daemon opérationnel
echo.

REM Aller à la racine K-Docs
cd /d "%KDOCS_ROOT%"

REM Vérifier si les containers existent déjà
echo Vérification des containers existants...
set NEED_RECREATE=0

%DOCKER_EXE% ps -a --filter "name=kdocs-onlyoffice" --format "{{.ID}}" 2>nul > "%TEMP%\kdocs_oo.tmp"
set /p OO_ID=<"%TEMP%\kdocs_oo.tmp"
del "%TEMP%\kdocs_oo.tmp" 2>nul

%DOCKER_EXE% ps -a --filter "name=kdocs-qdrant" --format "{{.ID}}" 2>nul > "%TEMP%\kdocs_qd.tmp"
set /p QD_ID=<"%TEMP%\kdocs_qd.tmp"
del "%TEMP%\kdocs_qd.tmp" 2>nul

if defined OO_ID (
    echo [INFO] Container OnlyOffice existant détecté: %OO_ID%
    set /p RECREATE="Voulez-vous le recréer via docker-compose ? (O/N): "
    if /i "!RECREATE!"=="O" (
        echo Arrêt et suppression du container OnlyOffice...
        %DOCKER_EXE% stop kdocs-onlyoffice >nul 2>&1
        %DOCKER_EXE% rm kdocs-onlyoffice >nul 2>&1
        set NEED_RECREATE=1
    )
)

if defined QD_ID (
    echo [INFO] Container Qdrant existant détecté: %QD_ID%
    set /p RECREATE="Voulez-vous le recréer via docker-compose ? (O/N): "
    if /i "!RECREATE!"=="O" (
        echo Arrêt et suppression du container Qdrant...
        %DOCKER_EXE% stop kdocs-qdrant >nul 2>&1
        %DOCKER_EXE% rm kdocs-qdrant >nul 2>&1
        set NEED_RECREATE=1
    )
)

REM Lancer docker-compose
echo.
echo Lancement des services via docker-compose...
echo (Premier téléchargement: ~2-3 GB pour OnlyOffice, ~100 MB pour Qdrant)
echo.

%DOCKER_COMPOSE_CMD% -f "%KDOCS_ROOT%\docker-compose.yml" up -d
if %errorlevel% neq 0 (
    echo.
    echo [ERREUR] Docker Compose a échoué.
    echo.
    echo Vérifiez les logs: docker-compose logs
    pause
    exit /b 1
)

echo.
echo [OK] Containers lancés
echo.

REM Attendre OnlyOffice
echo Attente du démarrage d'OnlyOffice (30-90 secondes)...
set ATTEMPTS=0
:check_oo
set /a ATTEMPTS+=1
if %ATTEMPTS% gtr 40 goto :oo_timeout

curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo [OK] OnlyOffice prêt !
    goto :check_qdrant
)

echo   Tentative %ATTEMPTS%/40...
timeout /t 3 /nobreak >nul
goto :check_oo

:oo_timeout
echo [ATTENTION] OnlyOffice met du temps à démarrer.
echo            Il continuera en arrière-plan.

:check_qdrant
REM Attendre Qdrant
echo.
echo Attente du démarrage de Qdrant...
set ATTEMPTS=0
:check_qd
set /a ATTEMPTS+=1
if %ATTEMPTS% gtr 20 goto :qd_timeout

curl -s http://localhost:6333/collections 2>nul | findstr /i "result" >nul
if %errorlevel% equ 0 (
    echo [OK] Qdrant prêt !
    goto :show_status
)

echo   Tentative %ATTEMPTS%/20...
timeout /t 2 /nobreak >nul
goto :check_qd

:qd_timeout
echo [ATTENTION] Qdrant met du temps à démarrer.

:show_status
echo.
echo ══════════════════════════════════════════════════════════
echo   STATUT DES SERVICES
echo ══════════════════════════════════════════════════════════
echo.

%DOCKER_EXE% ps --filter "name=kdocs-" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo.
echo URLs:
echo   - OnlyOffice:  http://localhost:8080/healthcheck
echo   - Qdrant:      http://localhost:6333/dashboard
echo   - K-Docs:      http://localhost/kdocs
echo.

REM Tests finaux
set ALL_OK=1

curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo [OK] OnlyOffice fonctionne
) else (
    echo [!!] OnlyOffice ne répond pas encore
    set ALL_OK=0
)

curl -s http://localhost:6333/collections 2>nul | findstr /i "result" >nul
if %errorlevel% equ 0 (
    echo [OK] Qdrant fonctionne
) else (
    echo [!!] Qdrant ne répond pas encore
    set ALL_OK=0
)

echo.
if %ALL_OK%==1 (
    echo ══════════════════════════════════════════════════════════
    echo   TOUS LES SERVICES SONT OPÉRATIONNELS !
    echo ══════════════════════════════════════════════════════════
) else (
    echo Certains services démarrent encore. Réessayez dans 1-2 minutes.
    echo Vérifiez les logs: docker-compose logs
)

echo.
pause
exit /b 0
