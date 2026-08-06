@echo off
REM GEDv1 — Harness complet (gate finalisation)
REM Logique dans tools\run-harness.mjs (suites nommees, rapport machine
REM tests\reports\harness-latest.json). Ce .bat n'est qu'un lanceur.
REM Usage: run-harness.bat
REM Exit 1 si une suite echoue.

setlocal
cd /d "%~dp0"

node tools\run-harness.mjs
exit /b %errorlevel%
