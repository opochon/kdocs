# K-DOCS — COORDINATION

> **Fichier partagé entre toutes les instances IA**

---

## 🔒 VERROUS ACTIFS

| Fichier/Zone | Verrouillé par | Depuis | Tâche |
|--------------|----------------|--------|-------|
| templates/partials/ | Agent-2 | 15:50 | Refonte sidebar |
| templates/layouts/ | Agent-2 | 15:50 | Refonte sidebar |
| public/css/theme.css | Agent-2 | 15:50 | Uniformisation styles |
| public/js/sidebar.js | Agent-2 | 15:50 | Logique resize/collapse |

---

## 📋 TÂCHES EN COURS

| # | Tâche | Assigné à | Status | Note |
|---|-------|-----------|--------|------|
| 1 | Test flux complet consume → validation | Agent-1 | 🔄 en cours | Test E2E |
| 2 | Refonte sidebar + styles IBM | Agent-2 | 🔄 en cours | - |
| 3 | Fix fallback Ollama (crédit Claude épuisé) | Agent-3 | ✅ terminé | Fallback automatique implémenté |
| 4 | Analyse problèmes document 52 (Rapport_Hydrogene) | Agent-3 | 🔄 en cours | Boutons V/X, validation toggle, métadonnées |

---

## 📝 JOURNAL DE SESSION

```
[2026-02-04 15:45] Agent-1 : Démarrage - Fix compteur documents
[2026-02-04 15:50] Agent-2 : Démarrage - Refonte sidebar + styles
[2026-02-04 16:00] Agent-3 : Démarrage - Fix classification IA
[2026-02-04 16:30] Agent-1 : FIX - Compteur corrigé (FolderTreeHelper.php)
[2026-02-04 16:35] Agent-3 : Modifications code (non fonctionnelles)
[2026-02-04 16:50] Agent-1 : Nouvelle tâche - Test flux complet consume
[2026-02-04 16:50] Agent-3 : DIAGNOSTIC - Crédit Claude épuisé, fallback Ollama silencieux
[2026-02-04 16:50] Agent-3 : Nouvelle tâche - Fix fallback Ollama + messages explicites
[2026-02-04 19:00] Agent-3 : FIX TERMINÉ - Fallback Ollama automatique implémenté
[2026-02-04 19:00] Agent-3 : Modifications: ClaudeService, AIProviderService, AIClassifierService, DocumentsApiController
[2026-02-04 19:00] Agent-3 : Détection HTTP 402/429, logs explicites, messages utilisateur améliorés
[2026-02-04 19:30] Agent-3 : ANALYSE - Problèmes document 52 : boutons V/X, toggle validation, métadonnées manquantes
```

---

## 🐛 BUGS IDENTIFIÉS

### Bug #1 : Fallback IA silencieux (Agent-3) ✅ RÉSOLU
- **Symptôme:** "Impossible de classifier" sans détail
- **Cause:** Crédit Claude API épuisé, pas de fallback vers Ollama
- **Fix appliqué:**
  1. ✅ Détection erreur 402/429 Claude (ClaudeService)
  2. ✅ Logger "Claude API: crédit épuisé - Switching to Ollama" (logs explicites)
  3. ✅ Fallback automatique Ollama (AIProviderService cascade)
  4. ✅ Message utilisateur explicite "Classification effectuée via Ollama (Claude indisponible)"
  5. ✅ Métadonnées _provider dans réponse API

### Bug #2 : Encodage UTF-8
- **Symptôme:** "Ã©valuation" au lieu de "évaluation"
- **Cause:** Probable extraction DOCX ou charset DB
- **À investiguer**

---

## 🧪 TEST EN COURS (Agent-1)

**Flux complet à tester :**
```
1. Déposer fichier dans dossier consume
2. Détection automatique
3. Extraction texte/OCR
4. Classification IA (Ollama si Claude KO)
5. Suggestions (type, correspondant, tags, date)
6. Validation manuelle
7. Vérifier indexation + vectorisation
```

---

---

## ✅ TÂCHES TERMINÉES

| # | Tâche | Assigné à | Date | Commit |
|---|-------|-----------|------|--------|
| 3 | Fix fallback Ollama (crédit Claude épuisé) | Agent-3 | 2026-02-04 19:00 | `fix(classification): Fallback Ollama automatique` |

**Détails du fix:**
- ClaudeService: Erreurs structurées avec détection HTTP 402/429
- AIProviderService: Cascade Claude → Ollama avec logs explicites
- AIClassifierService: Utilise uniquement AIProviderService
- DocumentsApiController: Messages utilisateur + métadonnées _provider

---

*Dernière mise à jour : 2026-02-04 19:00*
