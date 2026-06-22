# SESSION-STATUS — GEDv1 (K-Docs)

> Source de vérité état projet — migration initiale + roadmap produit B0.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`

## État au 2026-06-22 (chantier B0 — crédibilité produit K-Docs vs REDX)

### Roadmap produit

| Phase | Avancement | Document |
|-------|------------|----------|
| **B0** Crédibilité | **7/12** | `docs/ROADMAP-KDOCS-PRODUCT.md` |
| B1 GED pro | 0/10 | idem |
| A Factures | 0/8 | idem |
| C SMQ | 0/4 | idem |
| D RH | 0/4 | idem |
| P2 Conformité CH | 0/5 | idem |

**B0 complété cette session** : B0.1–B0.7 (spec, oracle, roadmap, harness, DETTE-UI, morts retirés, SESSION-STATUS).

**Prochain lot B0** : B0.8 sidebar 5 entrées + hub admin.

### Commits session 2026-06-22 (B0)

| Lot | Commit | Message |
|-----|--------|---------|
| B0.1 | `d4fff49` | `docs(ged): spec produit K-Docs vs REDX et roadmap B0` |
| B0.2 | `cae5f19` | `docs(ged): dette UI stubs invoices et mail masques` |
| B0.3 | `089cf8c` | `chore(ged): retirer templates documents morts` |
| B0.4 | *(ce commit)* | `chore(ged): oracle produit et harness roadmap B0` |

### Commits session 2026-06-18 (dual-mode)

| Lot | Commit |
|-----|--------|
| IA-8 | `f2be266` — `feat(cmd): sidecar v3 extract analyze ingest pour GED` (clearmydocs-v3) |
| IA-9/10 | `693b2dc` — `feat(ged): moteur ingest dual-mode ClearMyDocs v3` (+ doc INGEST-DUAL-MODE, admin, harness) |
| status | `0f17888` — `docs(ged): hashes commits ingest dual-mode dans SESSION-STATUS` |

### Harness tests

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
php tests\migration_smoke_test.php    REM 84/84 offline (post-B0)
vendor\bin\phpunit tests/Unit/Services/Ingest/ ...  REM 15/15 ingest+classifiers (lot ciblé)
```

**Dernier run** : **84 passés, 0 échoués** (migration_smoke_test) · **15/15** PHPUnit ingest/classifiers (2026-06-22)

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
| Helper `asset()` + route `/public/*` | Sidebar 5 entrées user (B0.8) |
| Favicon SVG, pages 404/500 pro | Hub `/admin` séparé (B1.2) |
| Masquer bannière sécurité hors `APP_DEBUG` | Refactor `documents/index.php` (B1.5) |
| Filtrer docs `test_*` dashboard + sidebar | Design system composants (B1.6) |
| Compteur « En attente » aligné sidebar | Retirer sync ingest `index.php` (B0.9) |
| Dashboard icônes SVG | `show_paperless.php` à retirer (B0.11) |

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

*Dernière mise à jour : 2026-06-22 — lot B0 crédibilité produit*
