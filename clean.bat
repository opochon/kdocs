@echo off
REM K-DOCS - Clean, Check & Status
REM Vérifie l'état complet du projet

setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo ========================================
echo   K-DOCS FULL STATUS CHECK
echo ========================================
echo.

set ERRORS=0
set WARNINGS=0

REM ========================================
REM 1. GIT STATUS
REM ========================================
echo [1/6] Git Status
echo ----------------------------------------

git rev-parse --git-dir >nul 2>&1
if errorlevel 1 (
    echo   WARNING: Not a git repository
    set /a WARNINGS+=1
) else (
    REM Fichiers non commités
    for /f %%i in ('git status --porcelain 2^>nul ^| find /c /v ""') do set UNCOMMITTED=%%i
    if !UNCOMMITTED! GTR 0 (
        echo   WARNING: !UNCOMMITTED! uncommitted changes
        git status --short
        set /a WARNINGS+=1
    ) else (
        echo   OK: Working directory clean
    )
    
    REM Dernier commit
    for /f "tokens=*" %%i in ('git log -1 --format^="%%h %%s" 2^>nul') do echo   Last commit: %%i
    
    REM Branch actuelle
    for /f "tokens=*" %%i in ('git branch --show-current 2^>nul') do echo   Branch: %%i
    
    REM Remote sync
    git fetch --dry-run 2>&1 | find /i "fatal" >nul
    if not errorlevel 1 (
        echo   WARNING: Cannot reach remote
        set /a WARNINGS+=1
    )
)

REM ========================================
REM 2. CONFIG CHECK
REM ========================================
echo.
echo [2/6] Configuration
echo ----------------------------------------

if not exist config\config.php (
    echo   ERROR: config/config.php not found
    set /a ERRORS+=1
) else (
    php -r "require 'config/config.php';" >nul 2>&1
    if errorlevel 1 (
        echo   ERROR: config.php has syntax errors
        set /a ERRORS+=1
    ) else (
        echo   OK: config.php valid
    )
)

REM ========================================
REM 3. PHP SYNTAX
REM ========================================
echo.
echo [3/6] PHP Syntax Check
echo ----------------------------------------

set SYNTAX_ERRORS=0
for /r app %%f in (*.php) do (
    php -l "%%f" >nul 2>&1
    if errorlevel 1 (
        echo   ERROR: %%f
        set /a SYNTAX_ERRORS+=1
    )
)
if %SYNTAX_ERRORS% EQU 0 (
    echo   OK: All PHP files valid
) else (
    echo   ERROR: %SYNTAX_ERRORS% file(s) with syntax errors
    set /a ERRORS+=%SYNTAX_ERRORS%
)

REM ========================================
REM 4. STRUCTURE CHECK
REM ========================================
echo.
echo [4/6] Project Structure
echo ----------------------------------------

set MISSING=0
for %%f in (
    "app\Core\ConfigValidator.php"
    "app\Core\ErrorTracker.php"
    "app\Core\Migrations.php"
    "app\Core\Validator.php"
    "app\Services\BackupService.php"
    "tests\smoke_test.php"
    "BEFORE_YOU_START.md"
) do (
    if not exist %%f (
        echo   MISSING: %%f
        set /a MISSING+=1
    )
)
if %MISSING% EQU 0 (
    echo   OK: All critical files present
) else (
    echo   WARNING: %MISSING% file(s) missing
    set /a WARNINGS+=%MISSING%
)

REM ========================================
REM 5. STORAGE PERMISSIONS
REM ========================================
echo.
echo [5/6] Storage Directories
echo ----------------------------------------

for %%d in (storage storage\logs storage\backups storage\documents storage\thumbnails) do (
    if not exist %%d (
        echo   Creating: %%d
        mkdir %%d 2>nul
    )
)
echo   OK: Storage directories exist

REM ========================================
REM 6. SMOKE TESTS
REM ========================================
echo.
echo [6/6] Smoke Tests
echo ----------------------------------------

php tests\smoke_test.php >nul 2>&1
if errorlevel 1 (
    echo   ERROR: Smoke tests failed
    echo   Run 'test.bat smoke' for details
    set /a ERRORS+=1
) else (
    echo   OK: Smoke tests pass
)

REM ========================================
REM SUMMARY
REM ========================================
echo.
echo ========================================
echo   SUMMARY
echo ========================================
echo.
echo   Errors:   %ERRORS%
echo   Warnings: %WARNINGS%
echo.

if %ERRORS% GTR 0 (
    echo   STATUS: FAILED
    echo   Fix errors before continuing.
    echo.
    exit /b 1
) else if %WARNINGS% GTR 0 (
    echo   STATUS: OK WITH WARNINGS
    echo   Review warnings above.
    echo.
    exit /b 0
) else (
    echo   STATUS: ALL CLEAR
    echo   Project is ready for development.
    echo.
    exit /b 0
)
