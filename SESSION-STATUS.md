# SESSION-STATUS — GEDv1 (K-Docs)

> Source de vérité état projet — migration initiale + roadmap produit B0→B1.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`

## État au 2026-06-22 (chantier B1 — GED pro K-Docs vs REDX)

### Roadmap produit

| Phase | Avancement | Document |
|-------|------------|----------|
| **B0** Crédibilité | **12/12** | `docs/ROADMAP-KDOCS-PRODUCT.md` |
| **B1** GED pro | **10/10** | idem |
| A Factures | 3/8 (stubs + health ; reste 🟡 bridge) | idem |
| C SMQ | 1/4 (scaffold) | idem |
| D RH | 1/4 (scaffold) | idem |
| P2 Conformité CH | 1/5 (scaffold LegalArchiveService) | idem |

**B1 complété cette session** : sidebar `/search`, hub admin tuiles SVG, recherche unifiée, show.php actions branchées, inbox « À traiter », JS modale extrait, design system minimal, compteurs `shellSidebarStats`, placeholder miniatures, `routes/web.php`, bench ingest.

**Prochain lot** : Phase A — activer plugin factures + matching live quand `k-winbiz-bridge` déployé.

### Harness tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php    REM 125/125 offline (post-B1)
php tools\bench-ingest.php            REM structure
php tools\bench-ingest.php --live     REM BDD requise
```

**Dernier run** : **125 passés, 0 échoués** (migration_smoke_test) · **15/15** PHPUnit ingest/classifiers (2026-06-22)

### Commits session 2026-06-22 (B1 + scaffold)

| Lot | Commit | Message |
|-----|--------|---------|
| *(à remplir après push)* | | |

### Commits session 2026-06-22 (B0)

| Lot | Commit | Message |
|-----|--------|---------|
| B0.1 | `d4fff49` | `docs(ged): spec produit K-Docs vs REDX et roadmap B0` |
| B0.8 | `5ad203b` | `feat(ged): séparer sidebar user 5 entrées et hub admin B0.8` |
| B0.12 | `94bf36d` | `docs(ged): geler cascade directe AIClassifierService B0.12` |

### Push GitHub

| Élément | Détail |
|---------|--------|
| Remote | `https://github.com/opochon/kdocs.git` |
| Branch | `main` |

---

## Liens

- Spec produit : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`
- Roadmap produit : `docs/ROADMAP-KDOCS-PRODUCT.md`
- Oracle produit : `docs/ORACLES-KDOCS-PRODUCT.md`

---

*Dernière mise à jour : 2026-06-22 — lot B1 GED pro (10/10)*
