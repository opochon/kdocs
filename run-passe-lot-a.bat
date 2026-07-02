@echo off
REM GEDv1 — Passe fonctions UI, Lot A (ECM : ingérer → classer → analyser)
REM Prérequis : eval-full --no-ocr, serveur 8765

setlocal
cd /d "%~dp0"

echo === Passe Lot A — parcours ECM (eval_redx_expert) ===
echo.

php tools\eval-full.php --no-ocr
if errorlevel 1 (
  echo [ERREUR] eval-full requis pour fixtures personas/types
  exit /b 1
)

pushd tests\visual
set KDOCS_HOST=127.0.0.1
set KDOCS_PORT=8765
set KDOCS_BASE_PATH=/kdocs
call npx playwright test persona-parcours-ecm.spec.ts
set ERR=%errorlevel%
popd

if %ERR% neq 0 (
  echo.
  echo === Lot A : ROUGE — corriger avant lot B (voir docs/PASSE-FONCTIONS-UI.md) ===
  exit /b 1
)

echo.
echo === Lot A : VERT ===
exit /b 0
