@echo off
REM GEDv1 — Passe fonctions UI, Lot B (bibliothèque F-LIB-04..08)

setlocal
cd /d "%~dp0"

echo === Passe Lot B — operations bibliotheque ===
echo.

pushd tests\visual
set KDOCS_HOST=127.0.0.1
set KDOCS_PORT=8765
set KDOCS_BASE_PATH=/kdocs
call npx playwright test lib-operations.spec.ts
if errorlevel 1 (
  popd
  echo.
  echo === Lot B : ROUGE ===
  exit /b 1
)
popd

echo.
echo === Lot B : VERT ===
exit /b 0
