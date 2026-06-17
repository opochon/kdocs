# WinBiz — module distinct (GEDv1 ↔ WinbizIntegrator)

> État au 2026-06-17 — WinBiz n'est **pas** du code monolithique dans le core GEDv1.

## Principe

| Composant | Rôle | Emplacement |
|-----------|------|-------------|
| **GEDv1 (K-Docs)** | GED, workflows, app invoices, interface plugin | `F:\DATA\DEVELOPPEMENT\GEDv1` |
| **WinbizIntegrator** | Connecteur WinBiz complet (ODBC/OLEDB, matching, sync, écriture) | `F:\DATA\DEVELOPPEMENT\WinbizIntegrator` |
| **Pont cible** | API REST JSON entre GED 64-bit et service WinBiz 32-bit | `WinbizIntegrator/k-winbiz-bridge/` |

**Ne pas dupliquer** le code de `k-winbiz-bridge` dans GEDv1. L'intégration se fait par **contrat d'interface** (`ConnectorInterface` côté PHP, client HTTP vers le bridge Python).

## État actuel dans GEDv1

### Stubs et connecteur léger

| Chemin | Contenu | Maturité |
|--------|---------|----------|
| `connectors/winbiz/WinBizConnector.php` | Connexion ODBC directe (~240 lignes) | Lecture — à valider terrain 32-bit |
| `connectors/winbiz/config.php` | DSN, tables FoxPro, field_mapping | Config locale |
| `apps/invoices/` | Routes, `MatchingController`, UI rapprochement | Stubs / MVP |
| `app/Services/MatchingService.php` | Rapprochement facture ↔ BL | MVP |
| `app/Core/PluginRegistry.php` | Enregistrement plugins | Fonctionnel |

### Ce qui manque (P1)

1. **`ConnectorInterface` formalisé** — implémenté côté stub ; bridge HTTP vers WinbizIntegrator à brancher.
2. **`getFacturesFournisseur()`** — absent du connecteur PHP ; disponible côté bridge (`data_layer`, `reconciliation`).
3. **App invoices inactive** — `INVOICES_APP_ENABLED=false` par défaut dans `.env`.
4. **Écriture FoxPro** — interdite dans le core ; déléguée à `k-winbiz-bridge/service/write_layer.py`.

## WinbizIntegrator — structure

```
WinbizIntegrator/
├── k-winbiz-bridge/           # Microservice principal
│   ├── service/
│   │   ├── oledb_bridge.py    # Couche OLE DB VFP (32-bit)
│   │   ├── data_layer.py      # Lecture tables
│   │   ├── write_layer.py     # Écriture sécurisée (backup, locks)
│   │   ├── client_matcher.py  # Matching clients
│   │   ├── reconciliation.py  # Rapprochement
│   │   └── invoice_*.py       # Préparation / génération factures
│   ├── reverse/
│   │   ├── scan_database.py   # Reverse engineering schéma
│   │   └── schema.json        # Oracle field_mapping GED
│   ├── docs/                  # SCHEMA.md, TABLES.md, RELATIONS.md
│   ├── config/config.json     # data_path, read_only, port (défaut 5100)
│   └── install/               # Scripts Windows 32-bit
└── data/                      # Données WinBiz locales (hors git)
```

### API REST cible (bridge)

```
GET  /api/v1/health
GET  /api/v1/tables/{table}/records
POST /api/v1/tables/{table}/records   # si read_only=false
```

Voir `WinbizIntegrator/k-winbiz-bridge/README.md` et `REGLES_IMMUABLES.md` (backup avant écriture, mode lecture seule par défaut, locks CDX).

## Vision d'intégration

```
┌─────────────────────┐     ConnectorInterface      ┌──────────────────────────┐
│  GEDv1              │  (PHP — apps/invoices/)     │  WinbizIntegrator        │
│  PluginRegistry     │ ──────────────────────────> │  k-winbiz-bridge :5100   │
│  MatchingService    │     HTTP JSON /health       │  OLE DB 32-bit → .dbf    │
└─────────────────────┘                             └──────────────────────────┘
```

### Étapes recommandées

1. **Définir `WinBizBridgeClient`** dans GEDv1 — implémente `ConnectorInterface`, appelle le bridge REST (pas ODBC direct en prod).
2. **Conserver `WinBizConnector` ODBC** comme fallback dev / diagnostic uniquement.
3. **Réutiliser `reverse/schema.json`** pour valider `connectors/winbiz/config.php` field_mapping.
4. **Activer `apps/invoices/`** quand le bridge répond sur `GET /health`.
5. **Hooks plugin** : `invoice.validated`, `document.classified` → sync bridge (cf. `docs/PLUGIN-SYSTEM.md`).

## Références croisées

| Document | Contenu |
|----------|---------|
| `docs/PLUGIN-SYSTEM.md` | Architecture plugins et connecteurs GEDv1 |
| `docs/DELTA-REDX.md` | Gaps P1 WinBiz (traités via module externe) |
| `SESSION-STATUS.md` | Prochaine fonction : liaison WinBiz P1 |
| `WinbizIntegrator/k-winbiz-bridge/README.md` | Installation et API bridge |

---
*Dernière mise à jour : 2026-06-17*
