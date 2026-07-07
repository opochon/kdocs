# K-Docs (GEDv1) — SESSION STATE

> Mémoire inter-sessions (pilotage). Source de vérité longue : `SESSION-STATUS.md` (racine).

## Dernière session

| Champ | Valeur |
|-------|--------|
| Date | 2026-07-07 |
| Sujet | P1 K-ERP Connect live (Option A post-clear) |
| Gate | `run-erp-simulation.bat` **VERTE** · `ErpConnectTest` **18/18** |

## État actuel

### K-ERP Connect — opérationnel en dev
- Plugin `apps/erpconnect/` branché sur K-Time `/api/ged/*` (port **8091**)
- `.env` dev : `ERPCONNECT_APP_ENABLED=true`, clé `ged-dev-key-2026`
- UI : bouton **K-ERP Connect** dans modale fiche document → `/erpconnect/panel/{id}`
- Table `erp_links` migrée · seed simulation `tools/erp-sim-seed.php` (idempotent)

### Gates
| Gate | Résultat |
|------|----------|
| ErpConnectTest | 18/18 |
| erp-connect.spec.ts | 2/2 (~23 s) |
| test.bat check | smoke HTTP rouge si serveur GED arrêté (env.) |

## Prochain chantier (libre)

1. **P2 — Plugin WinBiz** — gaps GAP-010..019 (`WINBIZ-PLUGIN-REPOSITIONNE.md`)
2. **P3 — Harness live-IA** — `pipeline-ui` + `persona-parcours-ecm`
3. **Polish ERP** — badge bon pour accord sur fiche doc sans panneau séparé

**Prompt reprise** : `docs/pilotage/PROMPT_POST_CLEAR.md` (mettre Option B en tête)

## Commandes

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
run-erp-simulation.bat
vendor\bin\phpunit tests\Feature\ErpConnectTest.php
```

---

*Dernière mise à jour : 2026-07-07*
