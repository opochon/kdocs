@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0"

REM ===========================================================================
REM  claude-g.bat - GEDv1 (K-Docs). UNE commande, sans argument.
REM  Meme contrat que claude-s.bat sur K-TIME : etat, reservation entre sessions,
REM  tours enchaines jusqu a Ctrl+C, filet git avant chaque tour.
REM  NOTE batch : ce fichier DOIT rester en CRLF.
REM ===========================================================================

if "%KS_AGENT%"=="" set "KS_AGENT=GED-%COMPUTERNAME%-%RANDOM%"
set "FLAGS=--dangerously-skip-permissions"
set /a T=0

where claude >nul 2>&1
if errorlevel 1 goto :sans_claude

echo.
echo === Session %KS_AGENT% (GEDv1) ===
echo.
php tools\preflight.php
if errorlevel 1 goto :env_ko

echo.
node tools\claim.mjs gc
node tools\claim.mjs list
echo.
node tools\checklist.mjs --write

:tour
set /a T+=1
echo.
echo ===========================================================================
echo   TOUR %T%   -   Ctrl+C pour arreter
echo ===========================================================================
echo.
git add -u >nul 2>&1
git commit -q -m "wip(filet): avant tour %T% (%KS_AGENT%)" >nul 2>&1
claude %FLAGS% "/reprendre"
node tools\claim.mjs gc >nul 2>&1
goto :tour

:env_ko
echo.
echo [X] ENVIRONNEMENT NON EVALUABLE - voir le remede ci-dessus.
exit /b 3

:sans_claude
echo [X] Le CLI "claude" n est pas dans le PATH.
exit /b 2