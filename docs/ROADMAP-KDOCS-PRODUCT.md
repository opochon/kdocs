# K-Docs — Roadmap produit (shell + verticales REDX)

> Suivi phases **B → A → C → D → P2** — checkboxes mises à jour à chaque lot validé.  
> Spec : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`  
> Oracles : `docs/ORACLES-KDOCS-PRODUCT.md`

**Légende** : `[x]` fait · `[ ]` à faire · `[~]` en cours · `[🟡]` bloqué externe

---

## Phase B0 — Crédibilité (4–6 sem.)

Objectif : socle utilisable, docs produit, stubs masqués, morts retirés.

| ID | Tâche | Statut |
|----|-------|--------|
| B0.1 | Spec produit K-Docs vs REDX (`docs/superpowers/specs/…`) | [x] |
| B0.2 | Oracle produit shell/plugins (`docs/ORACLES-KDOCS-PRODUCT.md`) | [x] |
| B0.3 | Roadmap produit avec phases (`docs/ROADMAP-KDOCS-PRODUCT.md`) | [x] |
| B0.4 | Harness smoke étendu (spec, oracle, roadmap) | [x] |
| B0.5 | Documenter stubs UI masqués (`docs/DETTE-UI-ORPHELINS.md`) | [x] |
| B0.6 | Supprimer templates morts `index_old.php`, `show_old.php` | [x] |
| B0.7 | SESSION-STATUS aligné roadmap B0 | [x] |
| B0.8 | Séparer sidebar user (5 entrées) vs admin hub | [x] |
| B0.9 | Retirer post-traitement sync document dans `index.php` | [x] |
| B0.10 | Masquer Qdrant / recherche sémantique si infra absente | [x] |
| B0.11 | Supprimer `show_paperless.php` si grep = 0 | [x] |
| B0.12 | Geler appels directs `AIClassifierService` → `UnifiedClassifier` | [x] |

---

## Phase B1 — GED pro (6–8 sem.)

Objectif : ~60 % parité REDX — recherche unifiée, fiche doc épurée.

| ID | Tâche | Statut |
|----|-------|--------|
| B1.1 | Sidebar définitive : Bibliothèque, Recherche, À traiter, Importer, Admin | [x] |
| B1.2 | Hub admin tuiles `/admin` | [x] |
| B1.3 | Route `/search` unifiée (remplace `/chat` transitoire) | [x] |
| B1.4 | Refactor fiche document (`show.php`) — panneau métadonnées | [x] |
| B1.5 | Refactor liste documents — extraire JS modale | [x] |
| B1.6 | Design system minimal (Button, Card, Badge) | [x] |
| B1.7 | Compteurs single source of truth | [x] |
| B1.8 | Miniatures placeholder uniforme | [x] |
| B1.9 | Extraire routes `index.php` → `routes/web.php` + domaines | [x] |
| B1.10 | Bench ingestion upload→OCR→search | [x] |

---

## Phase A — Plugin Factures (8–10 sem.)

Objectif : ~75 % parité fiduciaire — WinBiz, validation circuit.

| ID | Tâche | Statut |
|----|-------|--------|
| A.1 | Activer `INVOICES_APP_ENABLED` après smoke plugin | [🟡] PluginRegistry OK ; activer en prod après validation manuelle |
| A.2 | `WinBizBridgeClient` HTTP → `k-winbiz-bridge` | [x] stub + health ; [🟡] bridge non déployé |
| A.3 | `WinBizMatchingService::matchDocumentToWinBiz()` | [x] stub ; [🟡] matching live requiert bridge |
| A.4 | UI rapprochement facture ↔ BL (extension offres/stock) | [🟡] bridge requis |
| A.5 | Persistance matches + écarts | [🟡] bridge requis |
| A.6 | Vues filtrées « Factures » dans Bibliothèque (plugin) | [🟡] activer INVOICES_APP_ENABLED |
| A.7 | Actions contextuelles fiche doc (rapprochement WinBiz) | [🟡] bridge requis |
| A.8 | Health check bridge dans `GET /health` | [x] |

---

## Phase C — Plugin SMQ (6–8 sem.)

Objectif : versions documentaires, quittances lecture (GAP-031/032).

| ID | Tâche | Statut |
|----|-------|--------|
| C.1 | Scaffold `apps/smq/` + PluginRegistry | [x] |
| C.2 | Versioning documentaire SMQ | [x] onglet Versions modale (liste/restore/diff/upload) |
| C.3 | Quittance lecture obligatoire | [x] quittance (record/status) + bannière lecture obligatoire dans la modale (gated SMQ) |
| C.4 | Vues filtrées qualité dans Bibliothèque | [x] vue « À quittancer » (`?smq=to_read`) + sidebar Qualité (SMQ) |

---

## Phase D — Plugin RH (6–8 sem.)

Objectif : dossiers collaborateurs, RBAC renforcé (GAP-033).

| ID | Tâche | Statut |
|----|-------|--------|
| D.1 | Scaffold `apps/rh/` + PluginRegistry | [x] |
| D.2 | Dossier employé lié documents | [🟡] à implémenter |
| D.3 | Permissions par périmètre RH | [🟡] à implémenter |
| D.4 | Vues filtrées RH dans Bibliothèque | [🟡] à implémenter |

---

## Phase P2 — Conformité CH (3–6 mois)

Objectif : WORM, rétention 10 ans, TSA — niveau REDX long terme (GAP-020–024).

| ID | Tâche | Statut |
|----|-------|--------|
| P2.1 | `LegalArchiveService` — archivage WORM | [x] scaffold |
| P2.2 | Politiques rétention GeBüV | [🟡] à implémenter |
| P2.3 | Horodatage TSA | [🟡] à implémenter |
| P2.4 | Audit trail conformité | [🟡] à implémenter |
| P2.5 | Export légal pour contrôle | [🟡] à implémenter |

---

## Jalons transverses (toutes phases)

| Jalon | Phase | Statut |
|-------|-------|--------|
| Ingest dual-mode CMD v3 | Pré-B0 | [x] |
| UnifiedClassifier + taxonomie HTMLEDITOR | Pré-B0 | [x] |
| P0 UI asset() + favicon + emojis dashboard | Pré-B0 | [x] |
| Parité REDX ~52 % | Baseline | [x] |
| Parité REDX ~60 % | B1 | [x] |
| Parité REDX ~75 % fiduciaire | A | [ ] |

---

## Historique lots

| Date | Lot | Commits |
|------|-----|---------|
| 2026-06-22 | B1 GED pro (10/10) + scaffold A/C/D/P2 | *(session en cours)* |
| 2026-06-22 | B0.8–B0.12 crédibilité chrome + workers | `5ad203b`, `9f976a9`, `21f80d8`, `84defb4`, `94bf36d` |
| 2026-06-22 | B0 docs + oracle + roadmap + harness | `d4fff49`, `cae5f19`, `089cf8c`, `e897044` |
| 2026-06-18 | Ingest dual-mode CMD v3 | `693b2dc`, `f2be266` |
| 2026-06-18 | UnifiedClassifier ingest | `347d125` |

---

*Dernière mise à jour : 2026-06-22 — lot B1 GED pro*
