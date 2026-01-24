@echo off
REM K-Docs - Script pour démarrer le worker de queue (Windows)
REM Usage: double-cliquer ou exécuter depuis la ligne de commande

echo [K-Docs Worker] Demarrage...
echo [K-Docs Worker] PID: %RANDOM%

cd /d "%~dp0\..\.."

php app\workers\queue_worker.php

pause
