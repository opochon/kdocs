# K-DOCS — COORDINATION

> **Fichier partagé entre toutes les instances IA**

---

## 🔒 VERROUS ACTIFS

| Fichier/Zone | Verrouillé par | Depuis | Tâche |
|--------------|----------------|--------|-------|
| app/Helpers/FolderTreeHelper.php | Agent-1 | 15:45 | Test E2E (lecture) |
| templates/partials/ | Agent-2 | 15:50 | Sidebar + étiquettes |
| templates/layouts/ | Agent-2 | 15:50 | Sidebar |
| public/css/theme.css | Agent-2 | 15:50 | Styles IBM |
| public/js/sidebar.js | Agent-2 | 15:50 | Toggle/resize |
| app/Models/Correspondent.php | Agent-2 | 17:00 | Correspondants enrichis |
| app/Services/AIProviderService.php | Agent-3 | 16:50 | Fallback Ollama |
| app/Services/AIClassifierService.php | Agent-3 | 16:50 | Fallback Ollama |
| app/Services/OnlyOfficeService.php | Agent-4 | 17:05 | Fix OnlyOffice |
| app/Controllers/Api/OnlyOfficeApiController.php | Agent-4 | 17:05 | Fix OnlyOffice |

---

## 📋 TÂCHES EN COURS

| # | Tâche | Assigné à | Status |
|---|-------|-----------|--------|
| 1 | Test flux complet E2E consume → validation | Agent-1 | 🔄 en cours |
| 2 | Sidebar + étiquettes + correspondants enrichis | Agent-2 | 🔄 en cours |
| 3 | Fix fallback Ollama (crédit Claude épuisé) | Agent-3 | 🔄 en cours |
| 4 | Fix OnlyOffice éditeur | Agent-4 | 🔄 en cours |

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
```

---

## 🐛 BUGS IDENTIFIÉS

| # | Bug | Agent | Status |
|---|-----|-------|--------|
| 1 | Compteur 21 vs 36 incohérent | Agent-1 | ✅ Fixé |
| 2 | Fallback Ollama silencieux | Agent-3 | 🔄 En cours |
| 3 | OnlyOffice non fonctionnel | Agent-4 | 🔄 En cours |
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

*Dernière mise à jour : 2026-02-04 17:05*
