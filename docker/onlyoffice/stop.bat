@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul 2>&1

REM ============================================================
REM K-Docs - Arreter OnlyOffice Document Server
REM ============================================================

cd /d "%~dp0"

echo.
echo Arret OnlyOffice Document Server...
echo.

REM Trouver Docker
set DOCKER_EXE=

where docker >nul 2>&1
if %errorlevel% equ 0 (
    set DOCKER_EXE=docker
    goto :docker_found
)

if exist "C:\Program Files\Docker\Docker\resources\bin\docker.exe" (
    set DOCKER_EXE="C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    goto :docker_found
)

echo [ERREUR] Docker non trouve.
pause
exit /b 1

:docker_found
%DOCKER_EXE% stop kdocs-onlyoffice 2>nul
if %errorlevel% equ 0 (
    echo [OK] OnlyOffice arrete.
) else (
    echo [INFO] OnlyOffice n'etait pas en cours d'execution.
)

echo.
pause
