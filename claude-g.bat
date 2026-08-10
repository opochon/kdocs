@echo off
REM ============================================================================
REM  GED / K-Docs ? coquille de session. NE PLUS MODIFIER CE FICHIER.
REM  Toute la logique vit dans EcosystemK/gouvernance/tools/tour.mjs et apps.json.
REM  Raison : cmd.exe relit le .bat a chaque goto ? le modifier pendant qu il
REM  tourne casse la boucle en cours.
REM
REM  Usage :  claude-g.bat [secteur] [demande]
REM  Ex.   :  claude-g.bat devis D-KT-01
REM ============================================================================
setlocal
cd /d "%~dp0"
if "%KS_AGENT%"=="" set "KS_AGENT=GED-%COMPUTERNAME%-%RANDOM%"
if not "%~1"=="" set "KS_SECTEUR=%~1"
if not "%~2"=="" set "KS_DEMANDE=%~2"
set "TOUR=F:\DATA\DEVELOPPEMENT\EcosystemK\gouvernance\tools\tour.mjs"
set /a T=0

where claude >nul 2>&1 || (echo [X] CLI "claude" absent du PATH. & exit /b 2)

:tour
set /a T+=1
node "%TOUR%" ged --numero %T%
if errorlevel 1 (
  echo.
  echo [X] Tour %T% non lance. Corriger, puis relancer.
  exit /b 1
)
claude --dangerously-skip-permissions "/reprendre"
goto :tour
