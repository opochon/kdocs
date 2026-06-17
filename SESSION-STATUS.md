# SESSION-STATUS — GEDv1 (K-Docs)



> Source de vérité état projet — migration initiale.

> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1`



## État au 2026-06-17 (chantier lots 0–6)



### Lots complétés



| Lot | Description | Commit |

|-----|-------------|--------|

| 0 | Docs panorama + harness migration | `b999d56` |

| 1 | Stabilisation P0 (miniatures, OCR, badge validation) | `86852ad` |

| 2 | Infra dev (.env.example, env(), doc WAMP) | `a59aeee` |

| 3 | Plugin system + WinBiz + app invoices | `11fba6d` |

| 4 | Matching facture ↔ BL | `585bbb5` |

| 5 | Tests unit + harness étendu (37 checks) | `45006cb` |

| 6 | Documentation finale | *(ce commit)* |



### Fait cette session



- [x] Copie `C:\wamp64\www\kdocs` → `F:\DATA\DEVELOPPEMENT\GEDv1`

- [x] Documentation oracles, architecture, panorama REDX, delta

- [x] Harness `tests/migration_smoke_test.php` (37/37 offline)

- [x] Corrections P0 UI/OCR

- [x] `ConnectorInterface`, `PluginRegistry`, stubs `apps/invoices/`

- [x] `MatchingService::matchInvoiceToBL()` + UI rapprochement

- [x] Tests PHPUnit (matching, WinBiz, env)

- [x] Health check WinBiz dans `GET /health`



### Harness tests



```cmd

cd F:\DATA\DEVELOPPEMENT\GEDv1

php tests\migration_smoke_test.php    REM 37/37 offline

run-tests.bat                         REM migration + PHPUnit unit

```



**Dernier run** : 37 passés, 0 échoués (migration_smoke_test)



### Score parité REDX (estimé post-chantier)



| Indicateur | Avant | Après |

|------------|-------|-------|

| Parité fonctionnelle fiduciaire | ~48 % | **~52 %** |

| Gaps P0 bloquants | 4 partiels | **0 bloquants** (corrigés code) |

| Gaps P1 WinBiz/invoices | 6 partiels/absents | **3 partiels** (ODBC terrain restant) |



### Bloqueurs restants



| Bloqueur | Impact | Action |

|----------|--------|--------|

| WinBiz ODBC 32-bit non validé terrain | Pas d'intégration ERP réelle | Test poste avec driver FoxPro |

| Archivage légal Olico absent | Parité REDX impossible | Lot futur — `LegalArchiveService` |

| `composer.lock` désync | `composer install` échoue | `composer update` quand Composer dispo |

| App invoices désactivée par défaut | Routes inactives | `INVOICES_APP_ENABLED=true` dans `.env` |



### Prochaine fonction à traiter — Liaison WinBiz (module distinct)

**Priorité P1** — intégration ERP via projet externe, pas duplication dans le core.

| Élément | Détail |
|---------|--------|
| Module externe | `F:\DATA\DEVELOPPEMENT\WinbizIntegrator` (`k-winbiz-bridge/`) |
| Stubs GEDv1 | `connectors/winbiz/`, `apps/invoices/` |
| Doc | `docs/WINBIZ-MODULE.md`, `docs/PLUGIN-SYSTEM.md` |

**Sous-tâches :**

1. Finaliser `ConnectorInterface` côté GEDv1 (contrat stable)
2. Créer `WinBizBridgeClient` — client HTTP vers `k-winbiz-bridge` (port 5100)
3. Brancher `PluginRegistry` / `apps/invoices/` sur le bridge (pas ODBC direct en prod)
4. Activer app invoices : `INVOICES_APP_ENABLED=true` après health check bridge
5. Valider matching facture ↔ BL avec données terrain WinBiz

### Autres prochaines étapes

1. Couche archivage légal Olico (GAP-020+)
2. Extraire routes `index.php` → modules
3. OnlyOffice diagnostic terrain si édition requise



## Liens



- Panorama : `docs/PANORAMA-GED-REDX.md`

- Delta : `docs/DELTA-REDX.md`

- Corrections P0 : `docs/CORRECTIONS_PRIORITAIRES.md`

- Plugin system : `docs/PLUGIN-SYSTEM.md`



---

*Dernière mise à jour : 2026-06-17 — chantier lots 0–6*

