@echo off
REM Recette manuelle — les gestes qu Olivier fait a la main.
REM Passer un geste vaut SIGNATURE : pas d etape d adoption separee.
REM   recette.bat            etat
REM   recette.bat ok 3       le geste 3 passe  -> ferme la demande
REM   recette.bat ko 3 "..."  le geste 3 echoue -> devient un defaut
setlocal & cd /d "%~dp0"
node "F:\DATA\DEVELOPPEMENT\EcosystemK\gouvernance/tools/recette-manuelle.mjs" %*
