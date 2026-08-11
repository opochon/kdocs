@echo off
REM Lancer un lot sur Codex depuis ce depot. Codex lit AGENTS.md, comme Claude.
REM Ou il vaut son cout : le JUGEMENT ? ecrire la sonde quand un autre ecrit le
REM code, relire un diff en contexte neuf, servir de second auditeur.
REM   codex-lot.bat "consigne"
REM   codex-lot.bat --fichier consigne.md
setlocal & cd /d "%~dp0"
node "F:\DATA\DEVELOPPEMENT\EcosystemK\gouvernance/tools/codex.mjs" %*
