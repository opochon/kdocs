@echo off
setlocal
REM Demarre le sidecar ClearMyDocs v3 pour GED K-Docs (port 5101)

set "CMD_ROOT=F:\DATA\DEVELOPPEMENT\clearmydocs-v3"
if defined CLEARMYDOCS_PATH set "CMD_ROOT=%CLEARMYDOCS_PATH%"

if not exist "%CMD_ROOT%\pyproject.toml" (
  echo [ERREUR] ClearMyDocs introuvable : %CMD_ROOT%
  exit /b 1
)

cd /d "%CMD_ROOT%"
echo Installation editable clearmydocs...
python -m pip install -e ".[dev]" >nul 2>&1
if errorlevel 1 (
  echo [WARN] pip install a echoue — tentative de demarrage quand meme
)

set CLEARMYDOCS_SIDECAR_HOST=127.0.0.1
set CLEARMYDOCS_SIDECAR_PORT=5101
echo Sidecar GED : http://127.0.0.1:5101/health
python -m clearmydocs.api.ged_sidecar
