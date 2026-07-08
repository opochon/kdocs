@echo off

REM GEDv1 — Passe fonctions UI, Lot A (ECM strict)

REM Prérequis : php tools/eval-full.php --no-ocr (fixtures personas)



setlocal

cd /d "%~dp0"



echo === Passe Lot A — parcours ECM strict (eval_redx_expert) ===

echo.



if "%SKIP_EVAL_FULL%"=="1" (

  echo [skip] eval-full SKIP_EVAL_FULL=1

) else (

  php tools\eval-full.php --no-ocr

  if errorlevel 1 (

    echo [ERREUR] eval-full requis pour fixtures personas/types

    exit /b 1

  )

)



pushd tests\visual

set KDOCS_HOST=127.0.0.1

set KDOCS_PORT=8765

set KDOCS_BASE_PATH=/kdocs

call npx playwright test persona-parcours-ecm.spec.ts

if errorlevel 1 (

  popd

  echo.

  echo === Lot A : ROUGE ===

  exit /b 1

)

popd



echo.

echo === Lot A : VERT ===

exit /b 0

