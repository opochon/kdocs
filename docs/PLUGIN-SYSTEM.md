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
| Connecteur ingest CMD v4 | 🟡 client P2 (factures) | `clearmydocs-v3/cmdv4` · API `cmdv4/docs/API.md` · `docs/CMD-V4-CONNECTOR.md` |
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
| `ingest-cmd-v4` | API factures — optionnel, cible |

Routage : `IngestEngineRouter` (CMD v4 si facture + joignable, sinon natif) — voir `docs/CMD-V4-CONNECTOR.md`.

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

## Slots visuels — segmentation de l'interface par module (2026-08-25)

Un **slot** est une zone nommée du shell K-Docs qu'une app satellite peut
alimenter. Le shell rend la zone ; jamais les modules individuels.

### Déclaration (côté app)

Dans `apps/{name}/config.php` :

```php
'ui_slots' => [
    'admin.sidebar.navigation' => __DIR__ . '/templates/slots/admin_sidebar.php',
],
```

Le fragment reçoit en contexte `$slot`, `$app_name`, et ce que l'appelant
passe à `View::pluginSlot()`. Convention : un fragment de zone *navigation*
fournit des `<li>` complets, construits avec le composant `ui/nav_item`.

### Rendu (côté shell)

```php
echo \KDocs\Core\View::pluginSlot('admin.sidebar.navigation', [
    'user' => $user, 'currentRoute' => $currentRoute, 'basePath' => $basePath,
]);
```

### La règle qui compte

`View::pluginSlot()` ne rend **que les apps activées** — même source de vérité
que les routes (`PluginRegistry::isEnabled` → `app.enabled` ← `*_APP_ENABLED`).
Un module éteint disparaît de l'interface tout seul : aucun `if()` éparpillé
dans les gabarits. Preuve par effet : `tests/integration/test_ui_modulaire.php`
(K-Time rendu, K-Portail déclaré mais invisible tant que `PORTAL_APP_ENABLED`
est absent).

### Zones ouvertes

| Zone | Consommateur | Premier alimenté |
|---|---|---|
| `admin.sidebar.navigation` | `templates/partials/sidebar_admin.php` | timetrack (K-Time) ; portal (posé d'avance, éteint) |

Zones candidates à venir : `documents.fiche.tabs`, `documents.toolbar.actions`.

### Moteur de rendu central

`KDocs\Core\View` : `render($template, $data, $layout)` (fin des paires
ob_start/include dupliquées par contrôleur — pilote : `ConsumeController`),
`component($name, $props)` (briques `templates/components/ui/`), `pluginSlot()`.
