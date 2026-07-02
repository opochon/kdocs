@echo off

REM GEDv1 — Passe fonctions UI, Lot C (fiche document F-DOC-03..09)



setlocal

cd /d "%~dp0"



echo === Passe Lot C — fiche document ===
echo.

php tools\fix-documents-innodb.php
if errorlevel 1 goto :fail

pushd tests\visual

set KDOCS_HOST=127.0.0.1

set KDOCS_PORT=8765

set KDOCS_BASE_PATH=/kdocs

call npx playwright test fiche-document.spec.ts

if errorlevel 1 (

  popd

  echo.

  echo === Lot C : ROUGE ===

  exit /b 1

)

popd



echo.

echo === Lot C : VERT ===
exit /b 0

:fail
echo.
echo === Lot C : ROUGE ===
exit /b 1

