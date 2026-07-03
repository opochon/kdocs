# GEDv1 — Delta REDX vs K-Docs



> Analyse comparative — 2026-06-17 (mise à jour sprint parité 90 % du 2026-07-03)



## Score positionnement GEDv1 vs REDX



| Indicateur | Valeur |

|------------|--------|

| **Parité hors WinBiz (socle ECM)** | **~96 %** (26/27 gaps ✅ test vert ; reste GAP-044 Tauri hors repo) |

| Parité globale estimée (WinBiz inclus) | ~80 % (cas fiduciaire) |

| Gaps ✅ comblés | 28 |

| Gaps 🟡 partiels | 5 (tous WinBiz 🔌) |

| Gaps ❌ absents | 5 (WinBiz ×4 + Tauri hors repo) |

| Gap nommés | **38** (P0–P4) · tests verts : 34 · à écrire : 9 (tous WinBiz) |

> Registre détaillé et source de vérité : `docs/PARITE-REDX-TESTS.md`.



## Matrice delta (résumé)



### P0 — Bloquants usage quotidien



| ID | Fonction | Module | Statut GEDv1 |

|----|----------|--------|--------------|

| GAP-001 | Aperçu DOCX réel dans modale | `templates/documents/index.php` | ✅ Corrigé (`86852ad`) |

| GAP-002 | Miniatures tous formats | `ThumbnailGenerator` | ✅ Corrigé ZIP OOXML + placeholder (`86852ad`) |

| GAP-003 | Contenu OCR indexé et visible | `DocumentProcessor`, `OCRService` | ✅ Corrigé sync content/ocr_text (`86852ad`) |

| GAP-004 | Badge validation cliquable | `templates/documents/index.php` | ✅ Corrigé (`86852ad`) |



### P1 — Intégration ERP WinBiz (plugin `winbiz-matching` + `winbiz-viewer`)

> **Stratégie 2026-06-17** : plugin GEDv1 = orchestration UI + matching métier ; accès données via **WinbizIntegrator** (`k-winbiz-bridge`). Périmètre lecture : **factures** (fourn. prioritaire), **BL**, **offres**, **stock**. Voir `docs/WINBIZ-MODULE.md`.

| ID | Fonction | Module | Statut GEDv1 |

|----|----------|--------|--------------|

| GAP-010 | `ConnectorInterface` + `WinBizBridgeClient` | `connectors/winbiz/` | 🟡 Interface ✅ — client HTTP bridge à créer |

| GAP-011 | Factures fournisseurs — lecture + matching | `winbiz-matching`, bridge `document` | 🟡 **P1** — `matchDocumentToWinBiz()`, `searchWinBizCandidates()` |

| GAP-012 | UI rapprochement facture ↔ BL | `MatchingService`, `apps/invoices/` | ✅ MVP (`585bbb5`) — à étendre offres/stock |

| GAP-013 | Liaison document GED ↔ date introduction WinBiz | `WinBizMatchingService` | 🟡 **P1** — écarts montant/lignes, persistance |

| GAP-014 | `registerInvoicesRoutes()` + hooks plugin | `PluginRegistry`, `apps/invoices/` | ✅ (`11fba6d`) — étendre hooks `document.classified` |

| GAP-015 | Recherche croisée offres (`DO_TYPE` 1) | `winbiz-matching` | ❌ **P1** — `matchToOffer()` |

| GAP-016 | Health check WinBiz | `GET /health` GED + bridge `/api/v1/health` | 🟡 GED ✅ — bridge à déployer |

| GAP-017 | Matching lignes ↔ stock / articles | `winbiz-matching` | ❌ **P1** — `matchLineToStock()`, `searchArticles` bridge |

| GAP-018 | Consultation factures / BL / offres depuis GED | `winbiz-viewer` | ❌ **P2** — `listDocuments()`, `getDocumentDetail()` |

| GAP-019 | Consultation stock WinBiz depuis GED | `winbiz-viewer` | ❌ **P2** — `searchStock()`, routes `/winbiz/stock` |

| GAP-01A | Endpoints bridge dédiés documents/search | `k-winbiz-bridge` API | 🟡 Flask explorer existe — REST `/api/v1/documents` à ajouter |



### P2 — Conformité archivage Suisse



| ID | Fonction | Statut |

|----|----------|--------|

| GAP-020 | Scellement WORM / archivage légal Olico | ✅ (2026-07-02) |

| GAP-021 | Politiques rétention (10 ans compta) | ✅ (2026-07-02) |

| GAP-022 | Export piste révision | ✅ (2026-07-03 — `GET /admin/audit/export` JSON timeline) |

| GAP-023 | Horodatage qualifié (TSA) | ✅ (2026-07-03 — RFC 3161, `TSA_URL`, mock testé) |

| GAP-024 | Document légal non modifiable | ✅ (2026-07-02) |



### P3 — Modules métier REDX



| ID | Fonction | Statut |

|----|----------|--------|

| GAP-030 | Module contrats + échéances | ✅ (2026-07-03 — `apps/contracts/`, due_date + upcoming) |

| GAP-031 | Module SMQ ISO | ✅ (2026-07-02) |

| GAP-032 | Quittance de lecture | ✅ (2026-07-02) |

| GAP-033 | Dossier RH digital | ✅ (2026-07-03 — `apps/rh/`, dossiers par catégorie) |

| GAP-034 | App mail IMAP | ✅ (2026-07-03 — `MailSyncService` + dédup, mock IMAP) |

| GAP-035 | PluginRegistry formel | ✅ (`11fba6d`) |



### P4 — Infrastructure



| ID | Fonction | Statut |

|----|----------|--------|

| GAP-040 | ACL document fine | ✅ (2026-07-03 — héritage dossier, admin bypass) |

| GAP-041 | Multi-mandant | ✅ (2026-07-03 — isolation tenant_id, gated env) |

| GAP-042 | Portail client | ✅ (2026-07-03 — `/portal/{client}` lecture seule) |

| GAP-043 | E-signature | ✅ (2026-07-03 — HMAC + audit, idempotent) |

| GAP-044 | App desktop Tauri | ❌ (roadmap, hors repo) |

| GAP-045 | Antivirus upload ClamAV | ✅ (2026-07-03 — INSTREAM clamd, hook upload fail-open) |



## Commits chantier (2026-06-17)



| Hash | Message |

|------|---------|

| `b999d56` | docs(ged): panorama migration et harness smoke tests |

| `86852ad` | fix(ged): corrections P0 miniatures OCR et badge validation |

| `a59aeee` | feat(ged): infra dev env helper et doc WAMP |

| `11fba6d` | feat(ged): plugin system WinBiz et app invoices |

| `585bbb5` | feat(ged): rapprochement facture BL WinBiz |

| `45006cb` | test(ged): tests unit P0 WinBiz et harness etendu |



## Prochaine action

> Plan de tests par gap (oracle + mécanisme) : `docs/PARITE-REDX-TESTS.md`.
> Guide consolidé : `docs/GUIDE-COMPLET-GED.md`.

1. **Plugin WinBiz P1 (`winbiz-matching`)** — `matchDocumentToWinBiz()` : factures fourn., BL, offres, stock (`docs/WINBIZ-MODULE.md`) — seul chantier fonctionnel restant

2. **Plugin WinBiz P2 (`winbiz-viewer`)** — consultation lecture documents WinBiz depuis GED (séparée du matching)

3. **Bridge** — `WinBizBridgeClient` + endpoints REST documents/search côté `k-winbiz-bridge`

4. **Approfondissement MVP** (optionnel) — UI contrats/RH/portail au-delà des oracles, validation TSA cryptographique complète, GAP-044 Tauri

---

*Dernière mise à jour : 2026-07-03 — sprint parité 90 % hors WinBiz : 10 gaps comblés
(GAP-022/023/030/033/034/040/041/042/043/045), tous épinglés par tests verts —
suite PHPUnit 460 tests OK. Parité hors WinBiz ~96 %, globale ~80 %.*

