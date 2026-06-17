# GEDv1 — Delta REDX vs K-Docs

> Analyse comparative — 2026-06-17 (mise à jour panorama GED)

## Clarification REDX

**REDX n'est pas un dépôt logiciel local.** C'est l'offre GED/ECM de l'intégrateur suisse **[RedX](https://www.red-x.net/fr/ged-ecm/)** (red-x.net), construite principalement sur **M-Files** avec des configurations métier :

| Configuration | Domaine |
|---------------|---------|
| REDX Factures | Dématérialisation et validation factures fournisseurs |
| REDX Contrat | Cycle de vie contrats, échéances |
| REDX SMQ | Documentation qualité ISO, quittances de lecture |
| REDX RH | Dossier digital collaborateur |

RedX revend aussi **Xerox DocuShare Go** ([fiche](https://www.red-x.net/fr/sheets/solutions-fr/xerox-docushare-go-fr/)) — produit ECM Xerox distinct de M-Files.

> La recherche initiale (glob `*REDX*` sur `F:\DATA\DEVELOPPEMENT\`) n'a trouvé aucun dépôt local — comportement attendu : REDX est une solution commerciale intégrée, pas du code source Karbonic.

**Document de référence complet** : `docs/PANORAMA-GED-REDX.md`

## Score positionnement GEDv1 vs REDX

| Indicateur | Valeur |
|------------|--------|
| Parité fonctionnelle estimée | **~48 %** (cas fiduciaire) |
| Fonctions ✅ présentes | 12 |
| Fonctions 🟡 partielles | 17 |
| Fonctions ❌ absentes | 9 |
| Gap nommés à implémenter | **38** (P0–P4) |

## Matrice delta (résumé)

Voir matrice complète section 6 de `docs/PANORAMA-GED-REDX.md`.

### P0 — Bloquants usage quotidien

| ID | Fonction | Module | Statut GEDv1 |
|----|----------|--------|--------------|
| GAP-001 | Aperçu DOCX réel dans modale | `templates/documents/index.php` | 🟡 P0 |
| GAP-002 | Miniatures tous formats | `ThumbnailGenerator` | 🟡 P0 |
| GAP-003 | Contenu OCR indexé et visible | `DocumentProcessor`, `OCRService` | 🟡 P0 |
| GAP-004 | Badge validation cliquable | `templates/documents/index.php` | 🟡 P0 |

### P1 — Intégration ERP WinBiz (vision REDX Factures)

| ID | Fonction | Module | Statut GEDv1 |
|----|----------|--------|--------------|
| GAP-010 | `ConnectorInterface` + `isConnected()` | `connectors/winbiz/` | 🟡 |
| GAP-011 | `getFacturesFournisseur()` | `WinBizConnector` | ❌ |
| GAP-012 | UI rapprochement facture ↔ BL | `MatchingService`, `apps/invoices/` | 🟡 |
| GAP-013 | `InvoicesController::showMatchingUI()` | `apps/invoices/` (stub) | ❌ |
| GAP-014 | `registerInvoicesRoutes()` | `index.php` | ❌ |
| GAP-015 | Workflow seed facture fournisseur | workflows | ❌ |
| GAP-016 | Health check WinBiz | `GET /health` | ❌ |

### P2 — Conformité archivage Suisse

| ID | Fonction | Statut |
|----|----------|--------|
| GAP-020 | Scellement WORM / archivage légal Olico | ❌ |
| GAP-021 | Politiques rétention (10 ans compta) | ❌ |
| GAP-022 | Export piste révision | 🟡 |
| GAP-023 | Horodatage qualifié (TSA) | ❌ |
| GAP-024 | Document légal non modifiable | ❌ |

### P3 — Modules métier REDX

| ID | Fonction | Statut |
|----|----------|--------|
| GAP-030 | Module contrats + échéances | ❌ |
| GAP-031 | Module SMQ ISO | ❌ |
| GAP-032 | Quittance de lecture | ❌ |
| GAP-033 | Dossier RH digital | ❌ |
| GAP-034 | App mail IMAP | 🟡 (stub) |
| GAP-035 | PluginRegistry formel | ❌ |

### P4 — Infrastructure

| ID | Fonction | Statut |
|----|----------|--------|
| GAP-040 | ACL document fine | 🟡 |
| GAP-041 | Multi-mandant | ❌ |
| GAP-042 | Portail client | ❌ |
| GAP-043 | E-signature | ❌ |
| GAP-044 | App desktop Tauri | ❌ (roadmap) |
| GAP-045 | Antivirus upload ClamAV | ❌ |

## Fonctions déjà en parité REDX

- CRUD documents + arborescence dossiers
- OCR Tesseract + extraction PDF/DOCX
- Classification IA (Claude/Ollama) + règles attribution
- Recherche fulltext + sémantique (Qdrant optionnel)
- Workflows visuels + validation modulaire
- API REST extensive (~40 contrôleurs API)
- OnlyOffice édition
- Import MSG/email, consume folder, corbeille
- Audit logs, snapshots, versions document
- Webhooks, notifications, chat, notes utilisateur

## Prochaine action

1. **Lot 1** : corriger P0 + tests verts (voir `docs/CORRECTIONS_PRIORITAIRES.md`)
2. **Lot 2** : valider WinBiz ODBC + brancher `apps/invoices/`
3. **Lot 3** : couche archivage légal Olico

---
*Dernière mise à jour : 2026-06-17 — REDX = RedX/M-Files (intégrateur suisse), pas projet local*
