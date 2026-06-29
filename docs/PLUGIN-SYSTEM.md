# GEDv1 — Système plugin et connecteurs

> **Architecture cible (2026-06-29)** : GED autonome + connecteurs optionnels activés par chemins/flags.
> Spec canonique : **`docs/CONNECTEURS-PLUGINS.md`**.
> WinBiz contrôle facture : **`docs/WINBIZ-PLUGIN-REPOSITIONNE.md`** · terrain WinBiz : **`docs/WINBIZ-MODULE.md`**.

---

## Résumé

| Mécanisme | Maturité | Emplacement |
|-----------|----------|-------------|
| Ingest natif (socle) | ✅ Production | `GedNativeIngestEngine`, `OCRService`, workers |
| Connecteur ingest CMD v3 | ✅ `IngestEngineRouter` | Sidecar `clearmydocs-v3` |
| Connecteur ingest CMD v4 | 🟡 À brancher | `clearmydocs-v3/cmdv4` |
| `PluginRegistry` apps | ✅ | `apps/*/routes.php`, flags `*_APP_ENABLED` |
| Connecteur ERP WinBiz | 🟡 Stub client HTTP | `WinBizBridgeClient`, bridge externe |
| `ConnectorRegistry` unifié | ❌ Cible P1 | `config/connectors.php` |
| K-Time opérationnel | ✅ Bridge | `WinbizIntegrator/.../time_tracker.py` |

**Règle** : sans connecteur externe, la GED fonctionne. CMD remplace tout ou partie de l'ingest **si disponible**. WinBiz / K-Time / Bexio s'activent **si connectés**.

---

## Connecteurs (`connectors/`)

Chaque connecteur = dossier isolé, **maintenu séparément** du core (souvent client HTTP vers un service externe).

```
connectors/{name}/
├── {Name}Connector.php   # ou *BridgeClient.php
├── config.php
└── README.md
```

Interface cible (`connectors/README.md`) :

```php
interface ConnectorInterface
{
    public function health(): array;
    public function isAvailable(): bool;
    public function testConnection(): array;
}
```

> **Écart** : `WinBizConnector` (ODBC) = fallback dev ; prod = `WINBIZ_BRIDGE_URL`.

### Connecteurs ingest

| ID | Rôle |
|----|------|
| `ingest-native` | Pipeline PHP — **toujours** |
| `ingest-cmd-v3` | Sidecar port 5101 — optionnel |
| `ingest-cmd-v4` | API factures — optionnel, cible |

Routage : `INGEST_ENGINE=auto|native|coupled` — voir `docs/INGEST-DUAL-MODE.md`.

### Connecteurs ERP / apps

| ID | Service externe |
|----|-----------------|
| `erp-winbiz` | `k-winbiz-bridge` :5100 |
| `app-ktime` | Même bridge (`time_tracker`) |
| `erp-bexio` | Futur |

---

## Plugins métier (`apps/`)

```
apps/{name}/
├── config.php          # app.enabled ← env *_APP_ENABLED
├── routes.php
├── Controllers/
└── templates/
```

Chargement : `PluginRegistry::registerAppRoutes()` dans `index.php`.

| Plugin | Flag | Dépendance connecteur |
|--------|------|------------------------|
| `smq` | `SMQ_APP_ENABLED` | — |
| `rh` | `RH_APP_ENABLED` | — |
| `invoices` | `INVOICES_APP_ENABLED` | `erp-winbiz` (contrôle live) |

**K-Time** : UI partielle dans GED ; logique WinBiz = **bridge**, pas duplication dans `apps/timetrack/`.

---

## WinBiz — rappel

Plugin **contrôle ERP optionnel** (pas moteur ingest). Deux capacités :

| Capacité | Priorité | Description |
|----------|----------|-------------|
| **`winbiz-control`** | P1 | Ventilation lignes facture vs WinBiz |
| **`winbiz-viewer`** | P2 | Consultation lecture documents WB |

Ne pas fusionner `k-winbiz-bridge` dans le core PHP.

Détail : `docs/WINBIZ-PLUGIN-REPOSITIONNE.md`, mapping tables : `docs/WINBIZ-MODULE.md`.

---

## Registre unifié (cible)

Évolution prévue de `PluginRegistry` :

```
config/connectors.php  →  ConnectorRegistry
  · healthAll() pour page admin Diagnostic
  · requires[] entre plugins et connecteurs
  · chemins *_PATH / *_URL depuis .env uniquement
```

Hooks **optionnels** (jamais bloquants ingest) :

| Hook | Usage |
|------|-------|
| `document.understood` | Document structuré prêt — suggestion contrôle ERP |
| `invoice.allocations.confirmed` | Persistance + écriture bridge si autorisée |

---

## Sécurité

- Credentials et chemins **hors git** (`.env`)
- Écriture FoxPro uniquement via `write_layer` bridge
- `read_only=true` par défaut connecteurs ERP

---

## Actions concrètes (backlog)

1. ~~Créer `config/connectors.php` + `ConnectorRegistry::healthAll()`~~ ✅ P1 2026-06-29
2. Page admin **Connecteurs** (section intégrée à `/admin/diagnostic`) — ✅ P1
3. Client CMD v4 + extension `IngestEngineRouter`
4. `WinBizBridgeClient` complet — spec repositionnée
5. `INVOICES_APP_ENABLED` gated sur `erp-winbiz` health
6. Tests : ingest **native seul** + ingest **CMD** sur même fixture

---

*Dernière mise à jour : 2026-06-29 — aligné CONNECTEURS-PLUGINS.md*
