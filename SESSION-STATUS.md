# SESSION-STATUS — GEDv1 (K-Docs)

> Source de vérité état projet — migration initiale + roadmap produit B0.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`

## État au 2026-06-22 (chantier B0 — crédibilité produit K-Docs vs REDX)

### Roadmap produit

| Phase | Avancement | Document |
|-------|------------|----------|
| **B0** Crédibilité | **12/12** | `docs/ROADMAP-KDOCS-PRODUCT.md` |
| B1 GED pro | 0/10 | idem |
| A Factures | 0/8 | idem |
| C SMQ | 0/4 | idem |
| D RH | 0/4 | idem |
| P2 Conformité CH | 0/5 | idem |

**B0 complété cette session** : B0.1–B0.12 (spec, oracle, roadmap, harness, DETTE-UI, morts retirés, sidebar 5 entrées, workers-only, Qdrant UI, gel AIClassifier).

**Prochain lot** : B1 — GED pro (hub admin tuiles, `/search`, refactor fiche doc).

### Commits session 2026-06-22 (B0)

| Lot | Commit | Message |
|-----|--------|---------|
| B0.1 | `d4fff49` | `docs(ged): spec produit K-Docs vs REDX et roadmap B0` |
| B0.2 | `cae5f19` | `docs(ged): dette UI stubs invoices et mail masques` |
| B0.3 | `089cf8c` | `chore(ged): retirer templates documents morts` |
| B0.7 | `5b8631b` | `docs(ged): SESSION-STATUS aligné roadmap B0` |
| B0.8 | `5ad203b` | `feat(ged): séparer sidebar user 5 entrées et hub admin B0.8` |
| B0.9 | `9f976a9` | `fix(ged): retirer traitement sync document de index.php B0.9` |
| B0.10 | `21f80d8` | `fix(ged): masquer UI Qdrant si infra absente B0.10` |
| B0.11 | `84defb4` | `chore(ged): supprimer show_paperless.php orphelin B0.11` |
| B0.12 | `94bf36d` | `docs(ged): geler cascade directe AIClassifierService B0.12` |

### Commits session 2026-06-18 (dual-mode)

| Lot | Commit |
|-----|--------|
| IA-8 | `f2be266` — `feat(cmd): sidecar v3 extract analyze ingest pour GED` (clearmydocs-v3) |
| IA-9/10 | `693b2dc` — `feat(ged): moteur ingest dual-mode ClearMyDocs v3` (+ doc INGEST-DUAL-MODE, admin, harness) |
| status | `0f17888` — `docs(ged): hashes commits ingest dual-mode dans SESSION-STATUS` |

### Harness tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php    REM 101/101 offline (post-B0.12)
vendor\bin\phpunit tests/Unit/Services/Ingest/ ...  REM 15/15 ingest+classifiers (lot ciblé)
```

**Dernier run** : **101 passés, 0 échoués** (migration_smoke_test) · **15/15** PHPUnit ingest/classifiers (2026-06-22)

### Docs produit B0 (nouveaux)

| Document | Rôle |
|----------|------|
| `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md` | Spec architecture shell + UI 5 entrées |
| `docs/ORACLES-KDOCS-PRODUCT.md` | Invariants shell, plugins, workers-only |
| `docs/ROADMAP-KDOCS-PRODUCT.md` | Phases B0, B1, A, C, D, P2 |
| `docs/DETTE-UI-ORPHELINS.md` | Stubs invoices/mail masqués |

### Ingest dual-mode CMD v3 — livré

| Composant | Fichier |
|-----------|---------|
| Sidecar `/health` `/extract` `/analyze` `/ingest` | `clearmydocs-v3/src/clearmydocs/api/ged_sidecar.py` |
| Router + probe + mapper | `app/Services/Ingest/*` |
| Branch DocumentProcessor | `DocumentProcessor::process()` §1 dual-mode |
| Client multi-endpoint | `ClearMyDocsSidecarClient.php` |
| Admin diagnostic CMD | `templates/admin/diagnostic.php` |
| Doc opérationnelle | `docs/INGEST-DUAL-MODE.md`, `tools/start-cmd-sidecar.bat` |

### P0 UI — fait / reste

| Fait | Reste (roadmap B0/B1) |
|------|------------------------|
| Helper `asset()` + route `/public/*` | Hub admin tuiles enrichi (B1.2) |
| Favicon SVG, pages 404/500 pro | Refactor `documents/index.php` (B1.5) |
| Masquer bannière sécurité hors `APP_DEBUG` | Design system composants (B1.6) |
| Filtrer docs `test_*` dashboard + sidebar | Route `/search` unifiée (B1.3) |
| Compteur « En attente » aligné sidebar | — |
| Dashboard icônes SVG | — |
| Sidebar 5 entrées user + hub admin (B0.8) | — |
| Ingest workers-only, sans sync index.php (B0.9) | — |
| UI Qdrant masquée si infra absente (B0.10) | — |

### Push GitHub

| Élément | Détail |
|---------|--------|
| Remote | `https://github.com/opochon/kdocs.git` |
| Branch | `main` |

---

## État au 2026-06-18 (chantier GEDv1 — ingest dual-mode CMD v3)

*(historique inchangé — voir commits `693b2dc`, `347d125`, etc.)*

---

## Liens

- Spec produit : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`
- Roadmap produit : `docs/ROADMAP-KDOCS-PRODUCT.md`
- Oracle produit : `docs/ORACLES-KDOCS-PRODUCT.md`
- Panorama : `docs/PANORAMA-GED-REDX.md`
- Delta : `docs/DELTA-REDX.md`
- Plugin system : `docs/PLUGIN-SYSTEM.md`

---

*Dernière mise à jour : 2026-06-22 — lot B0 crédibilité produit (12/12)*
