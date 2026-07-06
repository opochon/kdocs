# K-Docs (GEDv1) — prompt post-clear

> **Usage** : coller ce bloc tel quel dans une nouvelle session Cursor Agent après clear
> coordination. Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1` · remote : `github.com/opochon/kdocs`.

---

## Prompt à coller

```
Tu interviens sur K-Docs (GEDv1), dépôt PHP Slim 4 + MariaDB + Playwright.

Lecture obligatoire (ordre) :
1. SESSION-STATUS.md (racine) — état HEAD après push 2026-07-06
2. COORDINATION.md — aucun verrou actif ; poser un verrou avant de toucher une zone critique
3. docs/PASSE-FONCTIONS-UI.md — si chantier UI / Playwright
4. docs/WINBIZ-PLUGIN-REPOSITIONNE.md + K-TIME/docs/SPEC-GED-INTEGRATION.md — si chantier ERP

État livré (ne pas refaire) :
- Passe fonctions UI Lots A→E : registry 38/38 covered (run-passe-lot-{a..e}.bat)
- Sprint parité 90 % hors WinBiz : ~96 %, PHPUnit 460, 10 gaps comblés (2026-07-03)
- Plugin K-ERP Connect : apps/erpconnect/ + ErpConnectTest + erp-connect.spec.ts + run-erp-simulation.bat
- Coordination cleared 2026-07-06 ; main synchronisée origin

Chantier à prendre (choisir UN lot, annoncer avant de coder) :

**Option A — K-ERP Connect live (recommandé P1)**
- Activer ERPCONNECT_APP_ENABLED en dev
- Brancher KTimeClient sur k-time-web dev (SPEC-GED-INTEGRATION lots GED-1→4)
- Panneau /erpconnect/panel/{id} : proposal → submit → refresh statut validation
- Gate : ErpConnectTest vert + run-erp-simulation.bat + erp-connect.spec.ts
- Ne jamais écrire dans WinBiz depuis la GED (K-Time fait foi)

**Option B — Plugin WinBiz / parité gaps**
- Suivre docs/WINBIZ-PLUGIN-REPOSITIONNE.md (addendum 2026-07-03 : flux via K-Time)
- Instrument-first : test PHPUnit ou Playwright avant implémentation
- Mettre à jour docs/PARITE-REDX-TESTS.md + DELTA-REDX.md si gap comblé

**Option C — Fiabilisation harness live-IA**
- Specs rouges en batterie : pipeline-ui.spec.ts, persona-parcours-ecm.spec.ts
- Cause documentée : php -S mono-processus + Infomaniak lent (session 2026-06-30)
- Objectif : tag @live / serveur multi-workers OU skip documenté en run-harness.bat

Discipline :
- Édition chirurgicale ; pas de reset --hard
- Un lot = tests verts avant commit ; commit uniquement si je le demande
- Fin de session : mettre à jour SESSION-STATUS.md + libérer verrou COORDINATION.md
- Gates rapides : test.bat check · run-passe-lot-*.bat (pas run-harness.bat sauf fin de lot)

Commence par lire SESSION-STATUS.md, confirmer l'option choisie (A/B/C ou autre), poser le verrou COORDINATION si tu touches config/migrations/app/Core/, puis exécute.
```

---

## Gates de contrôle rapide

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1

REM Socle (court)
test.bat check

REM Passe UI isolée (sans eval-full long)
set SKIP_EVAL_FULL=1
run-passe-lot-e.bat

REM K-ERP Connect (hermétique + simulation)
vendor\bin\phpunit tests\Feature\ErpConnectTest.php
run-erp-simulation.bat

REM Harness complet (long ; 2 specs live-IA peuvent rouger)
run-harness.bat
```

---

*Généré : 2026-07-06 — handoff post-clear*
