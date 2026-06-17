# GEDv1 — Delta REDX vs K-Docs



> Analyse comparative — 2026-06-17 (mise à jour post-chantier lots 0–6)



## Score positionnement GEDv1 vs REDX



| Indicateur | Valeur |

|------------|--------|

| Parité fonctionnelle estimée | **~52 %** (cas fiduciaire, +4 pts post-chantier) |

| Fonctions ✅ présentes | 15 |

| Fonctions 🟡 partielles | 16 |

| Fonctions ❌ absentes | 7 |

| Gap nommés à implémenter | **38** (P0–P4) |



## Matrice delta (résumé)



### P0 — Bloquants usage quotidien



| ID | Fonction | Module | Statut GEDv1 |

|----|----------|--------|--------------|

| GAP-001 | Aperçu DOCX réel dans modale | `templates/documents/index.php` | ✅ Corrigé (`86852ad`) |

| GAP-002 | Miniatures tous formats | `ThumbnailGenerator` | ✅ Corrigé ZIP OOXML + placeholder (`86852ad`) |

| GAP-003 | Contenu OCR indexé et visible | `DocumentProcessor`, `OCRService` | ✅ Corrigé sync content/ocr_text (`86852ad`) |

| GAP-004 | Badge validation cliquable | `templates/documents/index.php` | ✅ Corrigé (`86852ad`) |



### P1 — Intégration ERP WinBiz (vision REDX Factures)

> **Stratégie 2026-06-17** : les gaps ODBC/ERP restants sont traités via le **module externe WinbizIntegrator** (`F:\DATA\DEVELOPPEMENT\WinbizIntegrator\k-winbiz-bridge\`), pas par duplication dans le core GEDv1. Voir `docs/WINBIZ-MODULE.md`.

| ID | Fonction | Module | Statut GEDv1 |

|----|----------|--------|--------------|

| GAP-010 | `ConnectorInterface` + `isConnected()` | `connectors/winbiz/` | ✅ (`11fba6d`) — bridge HTTP à brancher |

| GAP-011 | `getFacturesFournisseur()` | WinbizIntegrator bridge | 🟡 **À traiter via module** (`data_layer.py`) |

| GAP-012 | UI rapprochement facture ↔ BL | `MatchingService`, `apps/invoices/` | ✅ MVP (`585bbb5`) |

| GAP-013 | `InvoicesController::showMatchingUI()` | `apps/invoices/` | 🟡 Stub — activer après bridge |

| GAP-014 | `registerInvoicesRoutes()` | `index.php` + `PluginRegistry` | ✅ (`11fba6d`) |

| GAP-015 | Workflow seed facture fournisseur | workflows + bridge | ❌ **À traiter via module** |

| GAP-016 | Health check WinBiz | `GET /health` GED + bridge `/api/v1/health` | 🟡 GED ✅ — bridge à déployer |



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

| GAP-035 | PluginRegistry formel | ✅ (`11fba6d`) |



### P4 — Infrastructure



| ID | Fonction | Statut |

|----|----------|--------|

| GAP-040 | ACL document fine | 🟡 |

| GAP-041 | Multi-mandant | ❌ |

| GAP-042 | Portail client | ❌ |

| GAP-043 | E-signature | ❌ |

| GAP-044 | App desktop Tauri | ❌ (roadmap) |

| GAP-045 | Antivirus upload ClamAV | ❌ |



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



1. **Liaison WinBiz P1** — bridge `WinbizIntegrator/k-winbiz-bridge` + `WinBizBridgeClient` GEDv1 (`docs/WINBIZ-MODULE.md`)

2. Lot archivage légal Olico (GAP-020+)

3. `getFacturesFournisseur()` + workflow seed facture (via module, pas core PHP ODBC)



---

*Dernière mise à jour : 2026-06-17 — chantier lots 0–6*

