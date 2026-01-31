@echo off
REM K-Docs Queue Worker - Script de démarrage
REM Ce script lance le worker en boucle avec redémarrage automatique

setlocal enabledelayedexpansion

set PHP_PATH=c:\wamp64\bin\php\php8.3.14\php.exe
set WORKER_PATH=c:\wamp64\www\kdocs\app\workers\queue_worker.php
set LOG_DIR=c:\wamp64\www\kdocs\storage\logs
set PID_FILE=c:\wamp64\www\kdocs\storage\queue_worker.pid

REM Créer le dossier logs si nécessaire
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

:START
echo [%date% %time%] Demarrage du Queue Worker...

REM Enregistrer le PID
for /f "tokens=2" %%a in ('tasklist /fi "imagename eq php.exe" /fo list ^| find "PID:"') do (
    echo %%a > "%PID_FILE%"
)

REM Lancer le worker
"%PHP_PATH%" "%WORKER_PATH%" >> "%LOG_DIR%\queue_worker.log" 2>&1

REM Le worker s'est arrêté (normal après 1h ou 100 jobs)
echo [%date% %time%] Worker arrete, redemarrage dans 5 secondes...
timeout /t 5 /nobreak > nul

goto START
