@echo off
REM ============================================================================
REM K-DOCS - SCRIPT DE NETTOYAGE ET RÉORGANISATION
REM Exécuter depuis C:\wamp64\www\kdocs
REM ============================================================================

echo.
echo ╔══════════════════════════════════════════════════════════════╗
echo ║         K-DOCS - NETTOYAGE ET RÉORGANISATION                 ║
echo ╚══════════════════════════════════════════════════════════════╝
echo.

cd /d C:\wamp64\www\kdocs

REM ============================================================================
REM 1. CRÉATION STRUCTURE CIBLE
REM ============================================================================

echo [1/6] Création structure cible...

REM docs/
mkdir docs\architecture 2>nul
mkdir docs\api 2>nul
mkdir docs\install 2>nul
mkdir docs\archive 2>nul
mkdir docs\archive\prompts 2>nul
mkdir docs\archive\audits 2>nul
mkdir docs\archive\rapports 2>nul
mkdir docs\archive\statuts 2>nul

REM tests/
mkdir tests\smoke 2>nul
mkdir tests\unit 2>nul
mkdir tests\integration 2>nul
mkdir tests\results 2>nul

REM scripts/
mkdir scripts\maintenance 2>nul
mkdir scripts\migration 2>nul

REM proofofconcept/
mkdir proofofconcept 2>nul
mkdir proofofconcept\samples 2>nul

echo    OK

REM ============================================================================
REM 2. DÉPLACEMENT DOCS - PROMPTS CURSOR (archive)
REM ============================================================================

echo [2/6] Archivage prompts Cursor...

move docs\CURSOR_*.md docs\archive\prompts\ 2>nul
move docs\PROMPT_*.md docs\archive\prompts\ 2>nul

echo    OK

REM ============================================================================
REM 3. DÉPLACEMENT DOCS - AUDITS (archive)
REM ============================================================================

echo [3/6] Archivage audits...

move docs\AUDIT_*.md docs\archive\audits\ 2>nul

echo    OK

REM ============================================================================
REM 4. DÉPLACEMENT DOCS - RAPPORTS ET STATUTS (archive)
REM ============================================================================

echo [4/6] Archivage rapports et statuts...

move docs\RAPPORT_*.md docs\archive\rapports\ 2>nul
move docs\STATUT_*.md docs\archive\statuts\ 2>nul
move docs\RESUME_*.md docs\archive\rapports\ 2>nul
move docs\DEBUG_*.md docs\archive\rapports\ 2>nul

echo    OK

REM ============================================================================
REM 5. RÉORGANISATION DOCS UTILES
REM ============================================================================

echo [5/6] Réorganisation docs utiles...

REM Installation
move docs\INSTALLATION.md docs\install\ 2>nul
move docs\INSTALLATION_COMPLETE.md docs\install\ 2>nul
move docs\INSTALL_GHOSTSCRIPT.md docs\install\ 2>nul
move docs\INSTALL_IMAGEMAGICK.md docs\install\ 2>nul
move docs\SETUP_CLAUDE_API.md docs\install\ 2>nul

REM API
move docs\API.md docs\api\ 2>nul
move docs\WEBHOOKS_INTERET.md docs\api\ 2>nul

REM Architecture
move docs\AI_FALLBACK_ARCHITECTURE.md docs\architecture\ 2>nul
move docs\QUEUE_SYSTEM.md docs\architecture\ 2>nul
move docs\KDOCS_STRUCTURE_APPS.md docs\architecture\ 2>nul
move docs\KDRIVE_INTEGRATION.md docs\architecture\ 2>nul
move docs\CONSUME_FOLDER_FLOW.md docs\architecture\ 2>nul
move docs\REFONTE_INDEXATION.md docs\architecture\ 2>nul
move docs\OPTIMISATION_DECOUPLAGE_ARBORESCENCE.md docs\architecture\ 2>nul

echo    OK

REM ============================================================================
REM 6. NETTOYAGE RACINE ET TESTS
REM ============================================================================

echo [6/6] Nettoyage racine et tests...

REM Racine
move regen_thumbnails.php scripts\maintenance\ 2>nul
move ROADMAP.md docs\ 2>nul
del cookies.txt 2>nul
del CLAUDE_CODE_PROMPT.md 2>nul

REM Tests - déplacer smoke tests
move tests\smoke_test.php tests\smoke\ 2>nul
move tests\smoke_test.py tests\smoke\ 2>nul
move tests\smoke_test_stabilisation.php tests\smoke\ 2>nul
move tests\stabilisation_tests.php tests\smoke\ 2>nul
move tests\test_fulltext_search.php tests\integration\ 2>nul
move tests\run_smoke_test.bat tests\smoke\ 2>nul
move tests\run_smoke_test.sh tests\smoke\ 2>nul
move tests\README_SMOKE_TEST.md tests\smoke\ 2>nul

REM Tests - déplacer résultats
move tests\smoke_test_results tests\results\smoke_test_results 2>nul
move tests\smoke_test_results_v2 tests\results\smoke_test_results_v2 2>nul
move tests\test_results tests\results\test_results 2>nul
move tests\audit_results tests\results\audit_results 2>nul

REM Supprimer vieux dossier install (doublon de installer/)
if exist install\NUL (
    echo    Suppression ancien dossier install/...
    rmdir /s /q install 2>nul
)

echo    OK

REM ============================================================================
REM RÉSUMÉ
REM ============================================================================

echo.
echo ╔══════════════════════════════════════════════════════════════╗
echo ║                    NETTOYAGE TERMINÉ                         ║
echo ╚══════════════════════════════════════════════════════════════╝
echo.
echo Structure créée:
echo   docs\architecture\    - Specs techniques
echo   docs\api\             - Documentation API
echo   docs\install\         - Guides installation
echo   docs\archive\         - Anciens fichiers (prompts, audits, rapports)
echo   tests\smoke\          - Tests rapides
echo   tests\unit\           - Tests unitaires
echo   tests\integration\    - Tests E2E
echo   tests\results\        - Résultats (gitignore)
echo   scripts\maintenance\  - Scripts maintenance
echo   scripts\migration\    - Scripts DB
echo   proofofconcept\       - POC isolé
echo.
echo Prochaine étape: git add -A ^&^& git commit -m "chore: reorganize project structure"
echo.
