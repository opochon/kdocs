# K-Docs — Simplification produit vs REDX

> Spec produit approuvée — 2026-06-18  
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`  
> Statut : **approuvé** (brainstorming sections 1 + 2)

---

## Contexte

| Indicateur | Valeur |
|------------|--------|
| Parité REDX estimée | ~52 % |
| Score UI pro | 3,5 / 10 |
| Monolithe routes | `index.php` ~800 lignes |
| Ingest | dual-mode CMD v3 livré (`IngestEngineRouter`) |

**Objectif** : être **plus simple que REDX** (M-Files invisible) tout en couvrant les verticales métier par plugins activables — sans réécrire le moteur PHP existant.

---

## Section 1 — Architecture produit (approuvée)

### Principe : « M-Files invisible »

REDX expose objets, classes et propriétés M-Files. K-Docs expose **3 intentions utilisateur** dans le shell :

```
┌─────────────────────────────────────────────────────────┐
│  K-Docs — shell user (toujours visible)                 │
├─────────────┬─────────────┬─────────────────────────────┤
│  Bibliothèque │  Recherche  │  À traiter (inbox)        │
│  (tous docs)  │  (plein texte│  (workflow + validation) │
│               │  + filtres) │                           │
└─────────────┴─────────────┴─────────────────────────────┘
         │              │                    │
         └──────────────┴────────────────────┘
                        │
              Détail document (fiche unique)
              preview · métadonnées · historique · actions
```

Les verticales REDX (Factures, SMQ, RH) **n'ajoutent pas** de navigation parallèle : elles ajoutent des **vues filtrées** et des **actions contextuelles** sur la même fiche document.

### Priorité rollout (ordre E, socle B d'abord)

| Priorité | Code | Contenu |
|----------|------|---------|
| 1 | **B** | Shell GED généraliste — chrome, ingest workers, recherche, inbox |
| 2 | **A** | Plugin Factures (WinBiz, validation circuit) |
| 3 | **C** | Plugin SMQ (versions, quittances) |
| 4 | **D** | Plugin RH (dossiers collaborateurs) |
| 5 | **P2** | Conformité CH (WORM, rétention 10 ans, TSA) |

### Couches logicielles

| Couche | Responsabilité | Code existant à garder |
|--------|----------------|------------------------|
| **Shell** | Navigation, liste, fiche, upload, consume | `DocumentProcessor`, `SearchService`, `WorkflowEngine` |
| **IA ingest** | OCR, classify, split | `IngestEngineRouter`, `UnifiedClassifier`, sidecar CMD |
| **Plugin Factures** | Matching WinBiz, validation circuit | `apps/invoices/` (sortir du stub) |
| **Plugin SMQ** | Versions, quittance lecture | À créer — GAP-031/032 |
| **Plugin RH** | Dossier collaborateur, RBAC renforcé | À créer — GAP-033 |
| **Conformité** | Olico/GeBüV | À créer — GAP-020–024 |

### Shell + plugins

- **Shell** = routes et chrome user sous `/`, `/documents`, `/chat`, `/mes-taches`, `/documents/upload`.
- **Plugins** = `apps/{name}/` chargés par `PluginRegistry` si `config.php` → `app.enabled = true`.
- **Connecteurs** = `connectors/{name}/` (WinBiz, futurs ERP) — jamais dans le core.
- **Admin hub** = tout sous `/admin/*` — **hors sidebar user**.

### Workers-only ingest

- Upload HTTP = persistance fichier + enqueue job uniquement.
- OCR, classification, split PDF, thumbnails = **workers** (`queue_worker.php`, `ClassifyDocumentJob`).
- **Interdit** : post-traitement document synchrone en fin de requête dans `index.php`.
- Moteur dual-mode : `INGEST_ENGINE` (`ged_native` | `clearmydocs`) via `IngestEngineRouter`.

### Simplification — jeter ou geler

| Élément | Décision |
|---------|----------|
| `index_old.php`, `show_old.php`, `show_paperless.php` | Supprimer si grep = 0 référence |
| `AIClassifierService` cascade directe | Geler — tout passe par `UnifiedClassifier` |
| Qdrant UI si non déployé | Masquer menu jusqu'à infra prête |
| Apps mail/invoices stubs | **Masquer** UI — pas de menu fantôme (`docs/DETTE-UI-ORPHELINS.md`) |
| Post-traitement sync `index.php` | Retirer progressivement — workers uniquement |
| Admin mélangé dans sidebar user | Hub `/admin` séparé (phase B0/B1) |

---

## Section 2 — UI épurée (outline approuvé)

### Sidebar user : 5 entrées max

| # | Libellé | Route cible | Rôle |
|---|---------|-------------|------|
| 1 | **Bibliothèque** | `/documents` | Tous les documents — grille + filtres latéraux |
| 2 | **Recherche** | `/chat` (transitoire) → `/search` (B1) | Plein texte + filtres sémantiques |
| 3 | **À traiter** | `/mes-taches` + badge pending | Inbox workflow + validation humaine |
| 4 | **Importer** | `/documents/upload` | Upload manuel + lien consume folder |
| 5 | *(séparateur)* | — | — |

**Admin** : lien discret en bas de sidebar ou header → `/admin` (hub tuiles). Tout le référentiel (tags, types, workflows, utilisateurs…) vit **uniquement** dans l'admin — plus dans la navigation user.

### Fiche document (écran unique)

- Preview (PDF/image/Office via OnlyOffice ou iframe).
- Panneau métadonnées : type, correspondant, tags, champs classification.
- Historique / audit classification.
- Actions contextuelles selon plugins actifs (ex. « Rapprocher WinBiz » si plugin Factures actif).

### Règles chrome

| Règle | Détail |
|-------|--------|
| Pas d'emoji UI | SVG icons uniquement (aligné lot P0 `24b2c93`) |
| Compteurs cohérents | Une source SQL par compteur — sidebar = dashboard |
| Tokens sémantiques | Viser alignement futur HTMLEDITOR (`--chrome-*`) |
| Document sur papier | Preview fond blanc stable — pas de thème sombre sur contenu |
| Stubs invisibles | Plugin désactivé = zéro entrée menu |

### Wireframe textuel layout

```
┌──────────┬────────────────────────────────────────────┐
│ K-Docs   │  Header : titre page · notif · user menu     │
│──────────│──────────────────────────────────────────────│
│ Biblioth.│                                              │
│ Recherche│              Zone contenu                    │
│ À traiter│         (liste / fiche / formulaire)         │
│ Importer │                                              │
│          │                                              │
│ ──────── │                                              │
│ Admin ⚙  │                                              │
└──────────┴────────────────────────────────────────────┘
```

---

## Phases livrables (référence roadmap)

Voir **`docs/ROADMAP-KDOCS-PRODUCT.md`** pour le détail checkbox par checkbox.

| Phase | Durée indicative | Parité REDX visée |
|-------|------------------|-------------------|
| B0 — Crédibilité | 4–6 sem. | Socle utilisable |
| B1 — GED pro | 6–8 sem. | ~60 % |
| A — Factures | 8–10 sem. | ~75 % fiduciaire |
| C — SMQ | 6–8 sem. | +10 % |
| D — RH | 6–8 sem. | +5 % |
| P2 — Conformité | 3–6 mois | Niveau REDX long terme |

---

## Documents liés

| Document | Rôle |
|----------|------|
| `docs/ORACLES-KDOCS-PRODUCT.md` | Invariants produit shell/plugins |
| `docs/ORACLES.md` | Invariants métier et API |
| `docs/ROADMAP-KDOCS-PRODUCT.md` | Suivi phases B0→P2 |
| `docs/DELTA-REDX.md` | Gaps fonctionnels REDX |
| `docs/DETTE-UI-ORPHELINS.md` | Menus/routes masqués en attente |
| `docs/AUDIT-SYNTHESE-EXECUTIVE.md` | Contexte audit juin 2026 |

---

*Approuvé : 2026-06-18 — priorité B, rollout E (A→C→D), conformité P2.*
