@echo off
REM GEDv1 — Harness complet (gate finalisation)
REM Logique dans tools\run-harness.mjs (suites nommees, rapport machine
REM tests\reports\harness-latest.json). Ce .bat n'est qu'un lanceur.
REM Usage: run-harness.bat
REM Exit 1 si une suite echoue.
REM
REM Piege historique de ce depot (lot socle.specs-harness) : avant le
REM 2026-08-06 les specs Playwright etaient listees EN DUR ici — une spec
REM oubliee de cette liste ne tournait jamais, en silence. Depuis le lot
REM socle.specs-registre, ce .bat ne liste plus rien : la liste vivante est
REM governance/specs.json (verifiee par tools/run-harness.mjs --check-specs,
REM qui echoue bruyamment si une *.spec.ts sur disque n'y figure pas). Toute
REM nouvelle spec doit etre ajoutee a governance/specs.json, pas ici.
REM Ajout de ce lot : tests/visual/specs/persona-dead-links.spec.ts,
REM enregistree active dans governance/specs.json.

setlocal
cd /d "%~dp0"

node tools\run-harness.mjs
exit /b %errorlevel%
