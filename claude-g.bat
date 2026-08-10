@echo off
REM ============================================================================
REM  GED / K-Docs ? lanceur. AUCUN PARAMETRE.
REM  La demande du tour se DERIVE de l'etat (regle de priorite), elle ne se
REM  saisit pas : un parametre a retenir est une dependance a la memoire.
REM  Ne plus modifier ce fichier ? logique dans EcosystemK/gouvernance/tools/.
REM ============================================================================
setlocal
cd /d "%~dp0"
if "%KS_AGENT%"=="" set "KS_AGENT=GED-%COMPUTERNAME%-%RANDOM%"
set "TOUR=F:\DATA\DEVELOPPEMENT\EcosystemK\gouvernance\tools\tour.mjs"
set /a T=0

where claude >nul 2>&1 || (echo [X] CLI "claude" absent du PATH. & exit /b 2)

:tour
set /a T+=1
node "%TOUR%" ged --numero %T%
if errorlevel 1 (echo. & echo [X] Tour %T% non lance. Corriger, puis relancer. & exit /b 1)
claude --dangerously-skip-permissions "/reprendre"
node "%TOUR%" ged --numero %T% --fin
goto :tour
