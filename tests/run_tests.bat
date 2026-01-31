@echo off
REM ============================================================
REM K-Docs - Test Suite Runner
REM ============================================================

cd /d "%~dp0.."

echo.
echo K-Docs Test Suite
echo ==================
echo.
echo Modes disponibles:
echo   --quick   : Tests rapides (infrastructure, services)
echo   --full    : Tests complets (+ workflows, embeddings)
echo   --stress  : Tests de stress (+ bulk upload, concurrent)
echo.

set MODE=%1
if "%MODE%"=="" set MODE=--quick

echo Mode: %MODE%
echo.

php tests/full_test_suite.php %MODE%

echo.
pause
