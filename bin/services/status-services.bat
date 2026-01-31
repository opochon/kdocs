@echo off
REM ============================================================
REM K-Docs - Statut des services Docker
REM ============================================================
cd /d "%~dp0"

echo.
echo ══════════════════════════════════════════════════════════
echo   STATUT DES SERVICES K-DOCS
echo ══════════════════════════════════════════════════════════
echo.

docker-compose ps

echo.
echo ── Tests de connectivité ──
echo.

REM Test OnlyOffice
curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo [OK] OnlyOffice:  http://localhost:8080
) else (
    echo [!!] OnlyOffice:  Non disponible
)

REM Test Qdrant
curl -s http://localhost:6333/collections 2>nul | findstr /i "result" >nul
if %errorlevel% equ 0 (
    echo [OK] Qdrant:      http://localhost:6333/dashboard
) else (
    echo [!!] Qdrant:      Non disponible
)

REM Test Ollama
curl -s http://localhost:11434/api/tags 2>nul | findstr /i "models" >nul
if %errorlevel% equ 0 (
    echo [OK] Ollama:      http://localhost:11434
) else (
    echo [--] Ollama:      Non démarré (optionnel)
)

REM Test K-Docs
curl -s http://localhost/kdocs/health 2>nul | findstr /i "healthy" >nul
if %errorlevel% equ 0 (
    echo [OK] K-Docs:      http://localhost/kdocs
) else (
    echo [!!] K-Docs:      Non disponible (Apache/WAMP démarré?)
)

echo.
pause
