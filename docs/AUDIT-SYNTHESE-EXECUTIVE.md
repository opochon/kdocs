# GEDv1 (K-Docs) — Synthèse exécutive audit

> Date : 2026-06-18 · Décisionnaire : direction produit / pilotage Stoco  
> Périmètre : code, UI/UX, ingestion, IA — comparaison HTMLEDITOR · Flowy (non trouvé)

---

## Verdict en une phrase

**K-Docs est fonctionnellement riche (~52 % parité REDX) mais présenté et industrialisé comme un prototype** : l'interface paraît brouillonne (score pro **3,5/10**), l'ingestion repose encore sur du traitement HTTP synchrone, et l'IA n'est pas alignée sur la taxonomie HTMLEDITOR — alors que Flowy n'est pas accessible localement pour compléter la vision Infomaniak.

---

## Scores consolidés

| Domaine | Score | Tendance |
|---------|-------|----------|
| **UI/UX pro** | **3,5 / 10** | Brouillon — Tailwind brut, emojis, compteurs incohérents |
| **Ingestion** | **5 / 10** | Pipeline complet documenté ; exécution hybrid non prod-ready |
| **Code / architecture** | **5,5 / 10** | Slim solide ; monolithe routes + apps stub |
| **IA / classificateur** | **6 / 10** | Cascade mature ; isolement vs HTMLEDITOR |
| **Parité REDX / M-Files** | **~52 %** | Voir `docs/DELTA-REDX.md` |

---

## Top 5 gaps critiques

| # | Gap | Impact business |
|---|-----|-----------------|
| 1 | **UI non professionnelle** — sidebar admin/user mélangée, miniatures vides, données test visibles | Crédibilité client/fiduciaire nulle |
| 2 | **Ingestion sync HTTP** — OCR/classification en fin de requête | Timeouts, fiabilité volume |
| 3 | **IA déconnectée de HTMLEDITOR** — pas de sync variables/sections/tags | Double saisie, classification hors vocabulaire Stoco |
| 4 | **Apps stub (invoices/mail) 404** — dette + fausses promesses UI | Confusion produit |
| 5 | **Conformité archivage CH absente** — WORM, rétention, TSA | Bloquant REDX long terme |

---

## Vision IA — HTMLEDITOR / Flowy (3 bullets)

- **HTMLEDITOR** : reprendre `_variables.json` (sets projet/langue/doc), taxonomie sections/blocs (`category`, `tags`, `externalIds`) et conventions Word — en référentiel GED et contexte prompts classification.
- **Flowy** : projet **non localisé** sur `F:\DATA` — seule brique Infomaniak présente = config kDrive ; spec Flowy requise avant connecteur ; hypothèse = stockage/recherche cloud complémentaire.
- **Cible** : plugin **`UnifiedClassifier`** avec adapters GED natif + HTMLEDITOR (+ Flowy quand disponible) ; GED reste source documents entrants ; HTMLEDITOR source taxonomie métier documentation.

---

## Top 10 actions prioritaires

| # | Action | Effort | Priorité |
|---|--------|--------|----------|
| 1 | Refonte chrome user/admin + design system sans emoji | **L** | P0 |
| 2 | Migrer traitement documents vers workers queue (supprimer sync `index.php`) | **M** | P0 |
| 3 | Sync taxonomie HTMLEDITOR → tags/champs classification GED | **M** | P0 |
| 4 | Fix miniatures + compteurs documents (source unique vérité) | **S** | P0 |
| 5 | Brancher ou retirer apps invoices/mail (404 smoke) | **S** | P0 |
| 6 | Extraire routes `index.php` en fichiers domaine | **M** | P1 |
| 7 | Tests bench ingestion (upload→OCR→search latences) | **M** | P1 |
| 8 | Activer Qdrant ou retirer UI sémantique cassée | **S** | P1 |
| 9 | Obtenir spec Flowy / API Infomaniak classification | **S** | P1 |
| 10 | Roadmap conformité archivage Suisse (WORM, rétention) | **L** | P2 |

**Effort** : S = 1–2 semaines · M = 3–6 semaines · L = 2–4 mois

---

## Décisions demandées

| Décision | Option A | Option B |
|----------|----------|----------|
| Refonte UI | Incremental (P0 chrome seulement) | Refonte complète design system Stoco/HTMLEDITOR |
| Flowy | Attendre spec, avancer HTMLEDITOR sync | Mission recherche accès Flowy en parallèle |
| Invoices | Brancher plugin MVP existant | Retirer menus jusqu'à prêt |
| IA cloud | Claude cascade primary | Ollama primary, Claude fallback |

---

## Livrables audit (chemins)

| Document | Chemin |
|----------|--------|
| Code & qualité | `F:\DATA\DEVELOPPEMENT\GEDv1\docs\AUDIT-CODE-QUALITE.md` |
| UI/UX | `F:\DATA\DEVELOPPEMENT\GEDv1\docs\AUDIT-UI-UX.md` |
| Ingestion | `F:\DATA\DEVELOPPEMENT\GEDv1\docs\AUDIT-INGESTION.md` |
| IA / classificateur | `F:\DATA\DEVELOPPEMENT\GEDv1\docs\AUDIT-IA-CLASSIFICATEUR.md` |
| Synthèse exécutive | `F:\DATA\DEVELOPPEMENT\GEDv1\docs\AUDIT-SYNTHESE-EXECUTIVE.md` |

---

## Prochain pas recommandé (lot unique)

**Lot « Crédibilité 30 jours »** : P0 UI chrome (items 1, 4) + workers ingestion (item 2) + sync taxonomie HTMLEDITOR minimal (item 3) — un commit par sous-lot, smoke 62 pages vert, validation humaine entre lots.

---

*Audit réalisé sans commit git (contrainte mission). Smoke : 64/64 OK core — `docs/SMOKE-FULL-REPORT.md`.*
