@echo off
REM GEDv1 — Harness complet (gate finalisation)
REM 1. Migration smoke
REM 2. PHPUnit (unit + feature)
REM 3. eval-full personas + fixtures (eval_redx_expert, types ECM)
REM 4. Playwright (personas visuels + workflow)
REM Usage: run-harness.bat
REM Exit 1 si une gate echoue.

setlocal EnableDelayedExpansion
cd /d "%~dp0"

set HOST=127.0.0.1
set PORT=8765
set BASE=http://%HOST%:%PORT%/kdocs

echo.
echo === GEDv1 Harness complet ===
echo.

echo [1/4] Migration smoke...
php tests\migration_smoke_test.php
if errorlevel 1 goto :fail

echo [1b/4] documents InnoDB (FK document_notes)...
php tools\fix-documents-innodb.php
if errorlevel 1 goto :fail

if not exist vendor\autoload.php (
    echo [ERREUR] vendor absent — composer install
    exit /b 1
)

echo.
echo [2/4] PHPUnit (toutes suites)...
call vendor\bin\phpunit --colors=always --no-coverage
if errorlevel 1 goto :fail

echo.
echo [3/4] eval-full (personas + types ECM + lot eval)...
php tools\eval-full.php --no-ocr
if errorlevel 1 goto :fail

echo.
echo [4/4] Playwright (specs visuels)...
pushd tests\visual
if not exist node_modules (
    call npm install
    call npx playwright install chromium
)
set KDOCS_HOST=%HOST%
set KDOCS_PORT=%PORT%
set KDOCS_BASE_PATH=/kdocs
call npx playwright test specs/persona-redx-expert.spec.ts specs/workflow-doc-identification.spec.ts specs/persona.spec.ts specs/persona-preview.spec.ts specs/shell.spec.ts specs/chrome-coherence.spec.ts specs/ai-confidence-badge.spec.ts specs/bugs-click.spec.ts specs/bugs-misc.spec.ts specs/ai-assistant.spec.ts specs/a11y.spec.ts specs/persona-parcours-ecm.spec.ts specs/lib-operations.spec.ts specs/fiche-document.spec.ts specs/pipeline-ui.spec.ts
set PW_ERR=!errorlevel!
popd
if !PW_ERR! neq 0 goto :fail

echo.
echo === Harness complet : VERT ===
exit /b 0

:fail
echo.
echo === Harness complet : ROUGE ===
exit /b 1
