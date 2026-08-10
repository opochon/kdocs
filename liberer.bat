@echo off
REM liberer.bat — la derniere porte avant un utilisateur reel.
REM Aucune version ne part sans socle vert. Une demo ratee coute le client.
setlocal
cd /d "%~dp0"
node "F:/DATA/DEVELOPPEMENT/EcosystemK/gouvernance/tools/recette.mjs" socle --release
if errorlevel 1 (
  echo.
  echo [X] LIBERATION REFUSEE — socle non vert.
  exit /b 1
)
echo.
echo [OK] Socle vert. Version liberable.
endlocal
