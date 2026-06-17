@echo off
REM K-DOCS - Test Runner (avec séparation bloquant/non-bloquant)
REM
REM Tests BLOQUANTS (empêchent le commit si fail):
REM   - syntax check
REM   - smoke tests
REM   - config check
REM
REM Tests NON-BLOQUANTS (warnings seulement):
REM   - visual regression
REM   - style checks

setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo ========================================
echo   K-DOCS TEST RUNNER
echo ========================================
echo.

set SUITE=%1
if "%SUITE%"=="" set SUITE=all

if "%SUITE%"=="smoke" goto :smoke
if "%SUITE%"=="api" goto :api
if "%SUITE%"=="unit" goto :unit
if "%SUITE%"=="integration" goto :integration
if "%SUITE%"=="ui" goto :ui
if "%SUITE%"=="visual" goto :visual
if "%SUITE%"=="visual:ref" goto :visual_ref
if "%SUITE%"=="check" goto :check
if "%SUITE%"=="all" goto :all
if "%SUITE%"=="report" goto :report

echo Unknown suite: %SUITE%
echo.
echo Available:
echo   smoke         Quick health check (30s)
echo   api           API endpoint tests
echo   integration   Full integration tests
echo   ui            UI page tests
echo   visual        Visual regression (non-blocking)
echo   visual:ref    Set visual reference
echo   check         Pre-commit validation (BLOCKING)
echo   all           Complete test suite
echo   report        Generate HTML report
echo.
exit /b 1

:smoke
echo [SMOKE] Running smoke tests...
php tests/smoke_test.php
goto :end

:api
echo [API] Running API tests...
php tests/api_test.php
goto :end

:unit
echo [UNIT] Running PHPUnit tests...
if exist vendor\bin\phpunit.bat (
    vendor\bin\phpunit --testsuite=unit
) else (
    echo PHPUnit not installed. Run: composer install
)
goto :end

:integration
echo [INTEGRATION] Running integration tests...
php tests/integration_test.php
goto :end

:ui
echo [UI] Running UI tests...
php tests/ui_test.php
goto :end

:visual
echo [VISUAL] Running visual regression tests...
php tests/visual_test.php compare
if errorlevel 1 (
    echo.
    echo WARNING: Visual changes detected
    echo This is non-blocking. Review tests/screenshots/visual_report.html
)
goto :end

:visual_ref
echo [VISUAL] Setting visual reference...
php tests/visual_test.php set-reference
echo.
echo Reference set. Future visual tests will compare against this.
goto :end

:check
echo ========================================
echo   PRE-COMMIT VALIDATION (BLOCKING)
echo ========================================
echo.

set CHECK_ERRORS=0

REM 1. Config check
echo [1/4] Config validation...
php -r "require 'vendor/autoload.php'; KDocs\Core\ConfigValidator::validate();" >nul 2>&1
if errorlevel 1 (
    echo   FAILED: Configuration errors
    set /a CHECK_ERRORS+=1
) else (
    echo   OK
)

REM 2. PHP Syntax
echo [2/4] PHP syntax check...
set SYNTAX_OK=1
for /r app %%f in (*.php) do (
    php -l "%%f" >nul 2>&1
    if errorlevel 1 (
        echo   ERROR: %%f
        set SYNTAX_OK=0
        set /a CHECK_ERRORS+=1
    )
)
if %SYNTAX_OK%==1 echo   OK

REM 3. Smoke tests
echo [3/4] Smoke tests...
php tests/smoke_test.php >nul 2>&1
if errorlevel 1 (
    echo   FAILED: Smoke tests
    set /a CHECK_ERRORS+=1
) else (
    echo   OK
)

REM 4. Credentials check
echo [4/4] Credentials check...
findstr /r /s /i "api_key.*=.*['\"][a-zA-Z0-9_-]\{20,\}['\"]" app\*.php >nul 2>&1
if not errorlevel 1 (
    echo   WARNING: Possible credentials in code
    REM Non bloquant mais warning
)
echo   OK

echo.
if %CHECK_ERRORS% GTR 0 (
    echo ========================================
    echo   COMMIT BLOCKED - %CHECK_ERRORS% error(s)
    echo ========================================
    echo.
    echo Fix errors and run 'test.bat check' again.
    exit /b 1
) else (
    echo ========================================
    echo   ALL CHECKS PASSED
    echo ========================================
    echo.
    echo You can commit safely.
    echo.
    REM Visual check (non-blocking)
    echo Running visual check (non-blocking)...
    php tests/visual_test.php compare >nul 2>&1
    if errorlevel 1 (
        echo   Note: Visual changes detected - review if needed
    )
)
goto :end

:all
echo Running ALL tests...
echo.

call :smoke
echo.
call :api
echo.
call :integration
echo.
call :visual

echo.
echo ========================================
echo   ALL TESTS COMPLETED
echo ========================================
echo.
echo Reports:
echo   tests/output/consolidated_report.json
echo   tests/screenshots/visual_report.html
goto :end

:report
echo Generating HTML report...
php tests/run_all.php --html
echo.
echo Report: tests/output/report.html
if exist tests\output\report.html start tests\output\report.html
goto :end

:end
echo.
