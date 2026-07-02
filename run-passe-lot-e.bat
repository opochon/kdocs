@echo off
REM GEDv1 — Passe fonctions UI, Lot E (hub admin F-ADM-01..05)

setlocal
cd /d "%~dp0"

echo === Passe Lot E — hub admin ===
echo.

pushd tests\visual
set KDOCS_HOST=127.0.0.1
set KDOCS_PORT=8765
set KDOCS_BASE_PATH=/kdocs
call npx playwright test admin-hub.spec.ts
if errorlevel 1 (
  popd
  echo.
  echo === Lot E : ROUGE ===
  exit /b 1
)
popd

echo.
echo === Lot E : VERT ===
exit /b 0
