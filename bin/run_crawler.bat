@echo off
REM K-Docs - Script batch pour lancer le worker d'indexation
REM Usage: run_crawler.bat

cd /d C:\wamp64\www\kdocs
C:\wamp64\bin\php\php8.3.14\php.exe cron\folder_crawler.php

pause
