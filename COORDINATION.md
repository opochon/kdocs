# K-DOCS — COORDINATION

> **Fichier partagé entre toutes les instances IA**

---

## 🔒 VERROUS ACTIFS

_Aucun verrou actif. (Session P1 K-ERP Connect clôturée 2026-07-07.)_

| Fichier/Zone | Verrouillé par | Depuis | Tâche |
|--------------|----------------|--------|-------|
| — | — | — | — |

---

## 📋 TÂCHES EN COURS

| # | Tâche | Assigné à | Status |
|---|-------|-----------|--------|
| 1 | Test flux complet E2E consume → validation | Agent-1 | ✅ clôturé (stale 2026-02-04) |
| 2 | Sidebar + étiquettes + correspondants enrichis | Agent-2 | ✅ clôturé (stale 2026-02-04) |
| 3 | Fix fallback Ollama (crédit Claude épuisé) | Agent-3 | ✅ terminé |
| 5 | Corrections document 52 (boutons V/X, validation) | Agent-3 | ✅ terminé |
| 6 | Correction date suggestion IA + texte bouton | Agent-3 | ✅ terminé |
| 7 | Fix erreur OCR 'Data too long' + matching correspondant | Agent-3 | ✅ terminé |
| 8 | Fix OCR avant analyse IA dans classifyWithAI() | Agent-3 | ✅ terminé |
| 4 | Fix OnlyOffice éditeur | Agent-4 | ✅ terminé |

---

## 📝 JOURNAL DE SESSION

```
[2026-02-04 15:45] Agent-1 : Démarrage - Fix compteur documents
[2026-02-04 15:50] Agent-2 : Démarrage - Refonte sidebar + styles
[2026-02-04 16:00] Agent-3 : Démarrage - Fix classification IA
[2026-02-04 16:30] Agent-1 : FIX - Compteur corrigé
[2026-02-04 16:50] Agent-1 : Nouvelle tâche - Test flux E2E
[2026-02-04 16:50] Agent-3 : DIAGNOSTIC - Crédit Claude épuisé → fallback Ollama
[2026-02-04 17:00] Agent-2 : UPDATE - Ajout tâches étiquettes + correspondants enrichis
[2026-02-04 17:05] Agent-4 : Démarrage - Fix OnlyOffice éditeur
[2026-02-04 19:00] Agent-3 : FIX TERMINÉ - Fallback Ollama automatique implémenté
[2026-02-04 19:30] Agent-3 : ANALYSE - Problèmes document 52 : boutons V/X, toggle validation
[2026-02-04 20:00] Agent-3 : FIX TERMINÉ - Corrections document 52 : boutons V/X, toggle validation, debug IA
[2026-02-04 20:15] Agent-3 : FIX TERMINÉ - Correction date suggestion IA + texte bouton 'Suggestion : analyser'
[2026-02-04 20:30] Agent-3 : FIX TERMINÉ - Troncature contenu OCR + matching correspondant amélioré
[2026-02-04 21:00] Agent-3 : FIX TERMINÉ - Troncature OCR complétée dans tous les endroits (10/10) : DocumentsApiController (3), MSGImportService (1), process_pending.php (1)
[2026-02-04 21:15] Agent-3 : FIX TERMINÉ - OCR avant analyse IA dans classifyWithAI() : vérifie et fait OCR si contenu vide/insuffisant avant classification
[2026-02-04 21:45] Agent-3 : FIX TERMINÉ - Amélioration gestion OCR : timeout 30s, traces d'erreurs, logs détaillés, gestion robuste pour éviter blocage aperçu
[2026-02-04 19:30] Agent-4 : FIX TERMINÉ - OnlyOffice fonctionnel (Docker Desktop démarré, container kdocs-onlyoffice actif sur port 8080)
[2026-07-06] Handoff : clear verrous stale (2026-02-04) · push 6 commits locaux · SESSION-STATUS + PROMPT_POST_CLEAR · aucun agent actif
[2026-07-07] Agent Cursor : P1 K-ERP Connect live — simulation 2/2 vert, plugin activé dev, bouton UI fiche doc
```

---

## 🐛 BUGS IDENTIFIÉS

| # | Bug | Agent | Status |
|---|-----|-------|--------|
| 1 | Compteur 21 vs 36 incohérent | Agent-1 | ✅ Fixé |
| 2 | Fallback Ollama silencieux | Agent-3 | ✅ Fixé |
| 5 | Boutons V/X document modal | Agent-3 | ✅ Fixé |
| 3 | OnlyOffice non fonctionnel | Agent-4 | ✅ Fixé |
| 4 | Encodage UTF-8 (Ã©) | - | 📋 À assigner |

---

## 📊 RÉPARTITION AGENTS

```
Agent-1 : Backend / Tests / DB
Agent-2 : Frontend / UI / Templates
Agent-3 : IA / Classification / Ollama
Agent-4 : Intégrations / OnlyOffice
```

---

## ⚠️ ZONES CRITIQUES (ne pas toucher sans coordination)

- `config/config.php`
- `database/migrations/`
- `app/Core/`
- `BEFORE_YOU_START.md`

---

---

## ✅ TÂCHES TERMINÉES

| # | Tâche | Assigné à | Date | Commit |
|---|-------|-----------|------|--------|
| 3 | Fix fallback Ollama | Agent-3 | 2026-02-04 19:00 | `0173582` |
| 5 | Corrections document 52 | Agent-3 | 2026-02-04 20:00 | `3ee32a9` |
| 4 | Fix OnlyOffice éditeur | Agent-4 | 2026-02-04 19:30 | N/A (infra) |

---

## 🚀 PROCHAIN CHANTIER (libre)

| Priorité | Chantier | Doc | Gate |
|----------|----------|-----|------|
| **P1** | ~~K-ERP Connect contre K-Time réel~~ **✅ livré 2026-07-07** | `run-erp-simulation.bat` · `ErpConnectTest` | **2/2 PW + 18/18 unit** |
| **P2** | Plugin WinBiz (parité gaps) | `docs/WINBIZ-PLUGIN-REPOSITIONNE.md` | gaps GAP-010..019 |
| **P3** | Harness live-IA | `pipeline-ui.spec.ts` · `persona-parcours-ecm.spec.ts` | `run-harness.bat` (2 specs env.) |

Prompt de reprise : **`docs/pilotage/PROMPT_POST_CLEAR.md`**

---

*Dernière mise à jour : 2026-07-07 — P1 K-ERP Connect live livré*
