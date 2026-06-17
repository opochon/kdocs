@echo off
REM GEDv1 — Lanceur de tests (migration + suites existantes)
REM Usage:
REM   run-tests.bat              -> smoke migration offline + PHPUnit unit si vendor present
REM   run-tests.bat full         -> run_all.php (necessite serveur WAMP sur localhost/kdocs)
REM   run-tests.bat smoke        -> smoke_test.php HTTP uniquement

setlocal
cd /d "%~dp0"

echo.
echo === GEDv1 Test Harness ===
echo.

php tests\migration_smoke_test.php
if errorlevel 1 (
    echo.
    echo [ERREUR] Migration smoke tests echoues.
    exit /b 1
)

if not exist vendor\autoload.php (
    echo.
    echo [INFO] vendor absent — lancer: composer install
    exit /b 0
)

if /i "%~1"=="full" (
    php tests\run_all.php http://localhost/kdocs
    exit /b %errorlevel%
)

if /i "%~1"=="smoke" (
    php tests\smoke_test.php http://localhost/kdocs
    exit /b %errorlevel%
)

echo.
echo --- PHPUnit (suite unit) ---
php vendor\bin\phpunit --testsuite=unit --colors=always
exit /b %errorlevel%
