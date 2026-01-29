@echo off
REM ============================================================
REM K-Docs - Trouve le chemin Docker
REM Définit DOCKER_EXE et DOCKER_COMPOSE_CMD
REM ============================================================

set DOCKER_EXE=
set DOCKER_COMPOSE_CMD=

REM Méthode 1: Docker dans le PATH
where docker >nul 2>&1
if %errorlevel% equ 0 (
    set DOCKER_EXE=docker
    set DOCKER_COMPOSE_CMD=docker compose
    goto :found
)

REM Méthode 2: Docker Desktop Windows (emplacement standard)
if exist "C:\Program Files\Docker\Docker\resources\bin\docker.exe" (
    set DOCKER_EXE="C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    set DOCKER_COMPOSE_CMD="C:\Program Files\Docker\Docker\resources\bin\docker.exe" compose
    goto :found
)

REM Méthode 3: Docker via Docker Desktop CLI
if exist "%LOCALAPPDATA%\Docker\wsl\docker-desktop\docker.exe" (
    set DOCKER_EXE="%LOCALAPPDATA%\Docker\wsl\docker-desktop\docker.exe"
    set DOCKER_COMPOSE_CMD="%LOCALAPPDATA%\Docker\wsl\docker-desktop\docker.exe" compose
    goto :found
)

REM Méthode 4: Docker Toolbox (legacy)
if exist "C:\Program Files\Docker Toolbox\docker.exe" (
    set DOCKER_EXE="C:\Program Files\Docker Toolbox\docker.exe"
    set DOCKER_COMPOSE_CMD="C:\Program Files\Docker Toolbox\docker-compose.exe"
    goto :found
)

REM Méthode 5: Chercher dans Program Files
for /f "tokens=*" %%i in ('dir /b /s "C:\Program Files\docker.exe" 2^>nul ^| findstr /i "docker.exe$"') do (
    set DOCKER_EXE="%%i"
    set DOCKER_COMPOSE_CMD="%%i" compose
    goto :found
)

REM Non trouvé
exit /b 1

:found
exit /b 0
