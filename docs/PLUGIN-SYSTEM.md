# GEDv1 — Système plugin et connecteurs

> État au 2026-06-17 — migration depuis `C:\wamp64\www\kdocs`.

## Résumé exécutif

K-Docs possède un **système de modularité naissant**, pas encore un plugin runtime complet :

| Mécanisme | Maturité | Emplacement |
|-----------|----------|-------------|
| Connecteurs ERP/cloud | Structure + 1 impl | `connectors/` |
| Apps métier intégrées | Stubs + K-Time partiel | `apps/` |
| Plugin system formel | **Non implémenté** | Roadmap `docs/ROADMAP.md` |
| Webhooks sortants | Fonctionnel | `WebhookService`, `WebhooksController` |
| Workflows extensibles | Fonctionnel | `app/Workflow/Nodes/` |

## Architecture cible (documentée)

### Connecteurs (`connectors/`)

Chaque connecteur = dossier isolé avec :

```
connectors/{name}/
├── {Name}Connector.php   # Implémente ConnectorInterface (cible)
├── config.php              # DSN, tables, mapping champs
└── README.md
```

**Interface standard** (`connectors/README.md`) :

```php
namespace KDocs\Connectors;

interface ConnectorInterface
{
    public function connect(): bool;
    public function disconnect(): void;
    public function isConnected(): bool;
    public function testConnection(): array;
}
```

> **Écart actuel** : `WinBizConnector` n'implémente pas encore formellement `ConnectorInterface` (classe autonome avec les mêmes méthodes).

### Apps satellites (`apps/`)

Pattern prévu par app :

```
apps/{name}/
├── routes.php
├── config.php
├── Controllers/
├── Models/
├── Services/
├── templates/
└── migrations/
```

**Chargement** : les `routes.php` devraient être inclus depuis `index.php` ou un bootstrap apps — **non fait** sauf routes K-Time en dur.

## WinBiz = module distinct (pas monolithique)

WinBiz est un **plugin / connecteur externe** au sens architecture, même si des stubs vivent dans ce dépôt.

| Dépôt | Rôle |
|-------|------|
| **GEDv1** (`connectors/winbiz/`, `apps/invoices/`) | Plugin WinBiz : orchestration UI, matching métier, consultation |
| **WinbizIntegrator** (`F:\DATA\DEVELOPPEMENT\WinbizIntegrator`) | Connecteur complet ODBC/OLEDB, schéma reverse, écriture sécurisée |

> **Ne pas fusionner** `k-winbiz-bridge` dans le core PHP. Intégration par `ConnectorInterface` + client HTTP vers le microservice Python (port 5100).

Documentation dédiée : **`docs/WINBIZ-MODULE.md`**.

### Deux capacités du plugin WinBiz

Un seul plugin, **deux sous-modules logiques** (priorités distinctes) :

| Capacité | ID | Priorité | Description |
|----------|-----|----------|-------------|
| **`winbiz-matching`** | liaison | **P1** | Document GED analysé → recherche croisée WinBiz (factures, BL, offres, stock) → correspondance, date introduction, écarts |
| **`winbiz-viewer`** | consultation | **P2** | Lecture factures / BL / offres / stock depuis la GED, sans flux matching obligatoire |

```
WinBizPlugin
├── Bridge/WinBizBridgeClient.php     # HTTP → k-winbiz-bridge (pas ODBC en prod)
├── Matching/WinBizMatchingService.php
└── Viewer/WinBizViewerService.php
```

Périmètre lecture WinBiz : factures (fournisseurs prioritaire), bulletins de livraison, offres, stock.

## Connecteur WinBiz — état détaillé (stubs GEDv1)

### Fichiers

| Fichier | Rôle |
|---------|------|
| `connectors/winbiz/WinBizConnector.php` | Classe ODBC (~240 lignes) — fallback dev ; prod → bridge |
| `connectors/winbiz/config.php` | DSN, tables FoxPro, field_mapping |
| `connectors/winbiz/README.md` | Doc utilisateur |

### Méthodes publiques implémentées

| Méthode | Description | Statut |
|---------|-------------|--------|
| `connect()` | Connexion ODBC Visual FoxPro | Code présent, à valider 32-bit |
| `disconnect()` | Fermeture connexion | OK |
| `searchArticles($query, $limit)` | Recherche stock | Code présent |
| `getArticle($code)` | Détail article | Code présent |
| `searchClients($query)` | Recherche clients | Code présent |
| `getBonLivraison($numero)` | Lecture BL | Code présent |
| `getBonsLivraison($filters)` | Liste BL | Code présent |
| `getFichesTravail($filters)` | Fiches travail | Code présent |
| `testConnection()` | Diagnostic | Code présent |

### Prérequis WinBiz

- WinBiz installé localement
- Driver ODBC **Visual FoxPro 32-bit**
- Extension PHP `odbc`
- Chemin données : ex. `C:\WinBiz\Data\MACOMPAGNIE\`

### Intégration GED prévue

| Point d'intégration | Fichier | Description |
|---------------------|---------|-------------|
| Liaison document ↔ WinBiz (P1) | `WinBizMatchingService` | `matchDocumentToWinBiz()`, recherche croisée |
| Rapprochement lignes facture ↔ BL | `MatchingService::matchInvoiceToBL()` | MVP existant — à intégrer dans winbiz-matching |
| Consultation documents (P2) | `WinBizViewerService` | Listes et détail factures / BL / offres / stock |
| Routes invoices | `apps/invoices/routes.php` | `/invoices/{id}/matching`, `/winbiz/bl`, `/winbiz/documents` |
| Migration BDD | `database/migrations/007_add_matching_columns.sql` | Colonnes matching |

### Module externe (source de vérité terrain)

**WinbizIntegrator** — `F:\DATA\DEVELOPPEMENT\WinbizIntegrator\k-winbiz-bridge\`

- Microservice REST 32-bit (OLE DB VFP) pour apps 64-bit (GED, K-Time)
- Schéma reverse : `reverse/schema.json`, `docs/SCHEMA.md`
- Écriture : `service/write_layer.py` (règles immuables, backup CDX)
- Voir `docs/WINBIZ-MODULE.md` pour le plan de bridge GEDv1 ↔ WinbizIntegrator

## Vision plugin system (à implémenter)

D'après `docs/ROADMAP.md`, le système plugin formel est **planifié** mais absent du code. Recommandation d'architecture :

### Phase 1 — Registre statique

```php
// config/plugins.php (à créer)
return [
    'winbiz' => [
        'class' => \KDocs\Connectors\WinBiz\WinBizConnector::class,
        'enabled' => env('WINBIZ_ENABLED', false),
    ],
];
```

### Phase 2 — Lifecycle plugin

```
register → boot → routes → services → shutdown
```

Hooks suggérés :

| Hook | Usage |
|------|-------|
| `document.uploaded` | Sync ERP, classification externe |
| `document.classified` | Déclencher `winbiz-matching` si type facture/BL |
| `invoice.validated` | Persister liaison WinBiz confirmée |
| `workflow.completed` | Notification externe |

### Phase 3 — Apps comme plugins

Charger dynamiquement :

```php
foreach (glob(__DIR__ . '/../apps/*/routes.php') as $routes) {
    require $routes;
}
```

## Connecteurs planifiés

| Connecteur | Type | Priorité roadmap |
|------------|------|------------------|
| WinBiz | ODBC/FoxPro | Phase 2 (en cours) |
| kDrive | WebDAV | v1.2.0 |
| Nextcloud | WebDAV | v1.2.0 |
| SharePoint | Graph API | v1.2.0 |
| S3/MinIO | AWS SDK | v1.2.0 |

## Recommandations Winbiz (actions concrètes)

1. **Formaliser `ConnectorInterface`** — faire implémenter par `WinBizConnector`.
2. **Ajouter `isConnected()`** manquant par rapport au contrat documenté.
3. **Créer `connectors/winbiz/Models/`** (Article, Client, BonLivraison) — prévu README, absent.
4. **Brancher `apps/invoices/routes.php`** dans `index.php` avec DI du connecteur.
5. **Test ODBC dédié** : `tests/Integration/WinBizConnectorTest.php` (skip si pas d'ODBC).
6. **Réutiliser schéma** de `WinbizIntegrator/k-winbiz-bridge/reverse/schema.json` pour valider field_mapping.
7. **Mode lecture seule** par défaut dans `config.php` (`read_only => true`).
8. **Exposer health check** WinBiz dans `GET /health` (comme OnlyOffice/Qdrant).

## Sécurité connecteurs

- DSN et chemins WinBiz **hors git** (config locale)
- Pas d'écriture FoxPro sans couche `write_layer` validée (cf. WinbizIntegrator Python)
- Timeout ODBC court, pool connexion unique par requête

---
*Dernière mise à jour : 2026-06-17 — deux capacités plugin WinBiz (matching + viewer)*
