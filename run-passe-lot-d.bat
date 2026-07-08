@echo off

REM GEDv1 — Passe fonctions UI, Lot D (recherche + tâches + consume F-SEARCH-02/03, F-TASK-01/02, F-IMP-02)



setlocal

cd /d "%~dp0"



echo === Passe Lot D — recherche et taches ===

echo.



pushd tests\visual

set KDOCS_HOST=127.0.0.1

set KDOCS_PORT=8765

set KDOCS_BASE_PATH=/kdocs

call npx playwright test search-tasks.spec.ts

if errorlevel 1 (

  popd

  echo.

  echo === Lot D : ROUGE ===

  exit /b 1

)

popd



echo.

echo === Lot D : VERT ===

exit /b 0

