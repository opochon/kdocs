# K-Docs — GED légère, connecteurs et plugins

> **Statut** : architecture cible validée — 2026-06-29  
> **Principe** : une GED **efficace et autonome** ; les extensions (ingest avancé, ERP, métier) sont des **connecteurs optionnels**, maintenus **séparément**, activés par configuration de chemins et de flags.

---

## Décision produit

| Règle | Détail |
|-------|--------|
| **Core obligatoire** | PHP + MySQL + pipeline natif (OCR, thumbnails, classification, workflows). Fonctionne **sans** Python, sans WinBiz, sans CMD. |
| **Pas d'installation lourde imposée** | Tesseract, LibreOffice, Ollama, CMD, bridge WinBiz = **optionnels** ; dégradation gracieuse. |
| **Connecteurs = minima** | Regarder ce qui existe ailleurs (CMD, k-winbiz-bridge, K-Time), brancher le **strict nécessaire**, pas réécrire. |
| **Plugins séparés** | Code et cycles de vie hors core : dépôts / services distincts, versionnés à part. |
| **Activation explicite** | Connecté → activé (health OK + flag + chemin). Sinon : invisible UI, pas d'erreur bloquante. |

---

## Deux familles

```
┌─────────────────────────────────────────────────────────────────┐
│  K-DOCS CORE (ce dépôt GEDv1)                                   │
│  Bibliothèque · ingest natif · workflows · API · modale document│
└───────────────┬─────────────────────────────┬───────────────────┘
                │                             │
    ┌───────────▼──────────┐      ┌──────────▼──────────┐
    │ CONNECTEURS          │      │ PLUGINS MÉTIER (apps/) │
    │ (capacités techniques)│      │ (verticales UI/routes) │
    └───────────┬──────────┘      └──────────┬──────────┘
                │                             │
     ingest · erp · storage          smq · rh · invoices · …
```

### Connecteurs (`connectors/` + clients HTTP)

Capacité **technique** branchée sur un système externe. Pas de navigation user dédiée (sauf debug admin).

| ID | Rôle | Dépôt / service externe | Remplace dans le core |
|----|------|-------------------------|------------------------|
| **`ingest-cmd`** | OCR, split, extract structuré, enrich LLM | `clearmydocs-v3` (sidecar) → **`cmdv4`** (cible factures) | **Tout ou partie** du pipeline ingest lourd |
| **`ingest-native`** | OCR PHP, split Claude, UnifiedClassifier | *dans GED* | Fallback **toujours** disponible |
| **`erp-winbiz`** | Contrôle facture, ventilation, lecture ERP | `WinbizIntegrator/k-winbiz-bridge` | Rien en ingest — post-document |
| **`erp-bexio`** | Idem Bexio | futur bridge | — |
| **`app-ktime`** | Temps, facturation client, sync clients WB | bridge `time_tracker.py` | Pas `apps/timetrack/` stub |
| **`storage-kdrive`** | WebDAV | planifié | — |
| **`edit-onlyoffice`** | Édition Office | Docker optionnel | Téléchargement si absent |

### Plugins métier (`apps/`)

Verticale **produit** : routes, templates, règles métier GED. S'appuient sur connecteurs.

| Plugin | Flag | Connecteur requis | Statut |
|--------|------|-------------------|--------|
| SMQ | `SMQ_APP_ENABLED` | — | Livré |
| RH | `RH_APP_ENABLED` | — | Scaffold |
| Invoices (contrôle ERP) | `INVOICES_APP_ENABLED` | `erp-winbiz` (ou bexio) | Stub UI |
| K-Time UI | routes `/time` | `app-ktime` + bridge | Partiel GED ; **réel = bridge** |

Chargement actuel : `PluginRegistry::registerAppRoutes()` + `apps/{name}/config.php` → `app.enabled`.

---

## Ingest : natif d'abord, CMD en renfort

### Comportement obligatoire

**Sans CMD v4 (ni v3)** : upload → `GedNativeIngestEngine` → OCR PHP → queue classification → thumbnails → workflows.  
C'est déjà en place (`IngestEngineRouter`, `DocumentProcessor`, workers).

**Avec CMD** : le connecteur ingest peut remplacer **tout ou partie** du traitement :

| Capacité | Natif GED (existant) | CMD v3 sidecar | CMD v4 (cible) |
|----------|----------------------|----------------|----------------|
| Extract texte / OCR | `OCRService` | `/extract`, `/ingest` | `product/extract.py` |
| Split PDF multi-pièces | `PdfSplitService` + Claude | `/segment` | `cmd4/segment.py` |
| Classification | `UnifiedClassifier` | `/analyze` | schémas + gate |
| Lignes facture + TVA | `InvoiceLineItemExtractor` | partiel | `facture_fournisseur` + lines |
| Enrich LLM / entités | IA cascade GED | indexer enrich | cmd4 pipeline |

### Routage ingest

| Mode | Comportement |
|------|--------------|
| Facture PDF + CMD v4 joignable | `CmdV4IngestEngine` (schémas factures, gate fidélité) |
| Sinon | `GedNativeIngestEngine` (OCR PHP + queue UnifiedClassifier) — toujours disponible |

Implémentation : `app/Services/Ingest/IngestEngineRouter.php` + `CmdV4CapabilityProbe.php`.
Le connecteur ClearMyDocs v3 (sidecar couplé, `INGEST_ENGINE`) a été retiré — ancienne version.

### Évolution CMD v4

- **Contrat API officiel** : `clearmydocs-v3/cmdv4/docs/API.md` (guide d'intégration ; Swagger `/docs`, OpenAPI `/openapi.json` sur le serveur v4). Fiche adaptateur côté GED : `docs/CMD-V4-CONNECTOR.md`.
- CMD v4 **remplace progressivement** v3 pour le lot factures (schémas, gate, segment).
- Le core GED ne duplique pas la logique Python : **client HTTP** + mapper JSON → `invoice_line_items`.
- Variables cibles (à ajouter au fur et à mesure) :

```env
# Connecteur ingest CMD v4 (optionnel — voir clearmydocs-v3/cmdv4/docs/API.md)
CMD_V4_ENABLED=false
CMD_V4_URL=http://127.0.0.1:8510
CMD_V4_PATH=F:\DATA\DEVELOPPEMENT\clearmydocs-v3
```

Tant que v4 non branché : pipeline natif GED.

---

## ERP : action post-ingest, jamais bloquante

WinBiz / Bexio ne participent **pas** à l'ingest obligatoire.

Flux : document **compris** (core ou CMD) → utilisateur **« Contrôler vs WinBiz »** → connecteur `erp-winbiz`.

Spec détaillée : `docs/WINBIZ-PLUGIN-REPOSITIONNE.md`.

---

## Gestion des plugins et des chemins

### Configuration (source de vérité)

| Niveau | Fichier | Contenu |
|--------|---------|---------|
| Environnement | `.env` / `.env.project` | Flags `*_ENABLED`, URLs, chemins absolus vers dépôts externes |
| App plugin | `apps/{name}/config.php` | `app.enabled`, dépendances déclarées |
| Connecteur | `connectors/{name}/config.php` | DSN, mapping, `read_only` |
| Core | `config/config.php` | Merge env + défauts |

**Chemins** : toujours configurables (poste dev, prod fiduciaire, Tauri futur). Jamais de chemin de dépôt externe en dur dans le code applicatif.

### Registre unifié (lot P1 — livré 2026-06-29)

`PluginRegistry` (apps/) + **`ConnectorRegistry`** (ingest + ERP) :

```php
// config/connectors.php (cible)
return [
    'ingest' => [
        'native'  => ['class' => GedNativeIngestEngine::class, 'always' => true],
        'cmd_v4'  => ['enabled' => env('CMD_V4_ENABLED'), 'url' => env('CMD_V4_URL'), 'path' => env('CMD_V4_PATH')],
    ],
    'erp' => [
        'winbiz' => ['enabled' => env('WINBIZ_ENABLED'), 'url' => env('WINBIZ_BRIDGE_URL'), ...],
        'bexio'  => ['enabled' => env('BEXIO_ENABLED'), ...],
    ],
    'apps' => [
        'smq'      => ['enabled' => env('SMQ_APP_ENABLED')],
        'invoices' => ['enabled' => env('INVOICES_APP_ENABLED'), 'requires' => ['erp.winbiz']],
    ],
];
```

### Cycle de vie connecteur

```
discover (config) → health() → isAvailable() → boot() [optionnel] → services UI/routes si plugin lié
```

| Méthode | Rôle |
|---------|------|
| `health()` | Ping HTTP / test ODBC — affiché admin Diagnostic |
| `isAvailable()` | `enabled && health.ok` |
| `capabilities()` | `['extract','segment','invoice_lines']` — pour choix ingest partiel |

**UI** : hub `/admin` → tuile **Connecteurs** : état vert/gris, URL, version, dernier health.

### Règles d'activation UI

| État | Sidebar / modale |
|------|------------------|
| Plugin off | Aucune entrée, aucun onglet |
| Connecteur down | Message « non configuré » ; core inchangé |
| Connecteur up | Onglets / actions contextuelles (SMQ, Contrôle WinBiz, …) |

Référence dette : `docs/DETTE-UI-ORPHELINS.md` (stubs masqués).

---

## Maintenance séparée

| Composant | Dépôt / emplacement | Maintenu par |
|-----------|---------------------|--------------|
| GED core | `GEDv1` | Ce dépôt |
| CMD v3 / v4 | `clearmydocs-v3` (`cmdv4/`, API `cmdv4/docs/API.md`) | Dépôt ClearMyDocs |
| Bridge WinBiz + K-Time réel | `WinbizIntegrator` | Dépôt dédié |
| HTMLEDITOR taxonomie | `htmleditor_v3` | Export JSON consommé par GED |
| Bexio bridge | futur | Dépôt dédié |

Le core GED ne **vend** pas le code des connecteurs : clients HTTP, mappers, flags. Mise à jour bridge = redéploiement service, pas refonte GED.

---

## Intégration minimale — méthode de travail

1. **Inventorier** l'existant externe (API, schémas, tests bench).
2. **Définir** le contrat PHP (interface + DTO JSON stable côté GED).
3. **Mapper** vers tables GED déjà là (`invoice_line_items`, `documents`, …).
4. **Gate** : test reproductible connecté / déconnecté.
5. **UI** : uniquement si plugin métier activé.

Pas de réécriture du moteur externe dans PHP. Pas de feature UI sans connecteur disponible.

---

## État actuel vs cible

| Brique | Aujourd'hui | Cible proche |
|--------|-------------|--------------|
| Ingest natif | ✅ `GedNativeIngestEngine` | Inchangé — socle |
| Ingest CMD v3 | ✅ `IngestEngineRouter` + sidecar 5101 | Conservé |
| Ingest CMD v4 | 🟡 client + routage factures | Client HTTP + sonde ; schéma facture (lignes P2.5) |
| `PluginRegistry` apps | ✅ `apps/*/routes.php` | + dépendances `requires` |
| `ConnectorRegistry` | ✅ P1 | `config/connectors.php`, `app/Core/ConnectorRegistry.php`, API `/api/admin/connectors/health` |
| Health connecteurs | 🟡 partiel (`/health`, probe CMD) | Page admin unifiée |
| WinBiz ERP | 🟡 stub `WinBizBridgeClient` | `erp-winbiz` complet |
| K-Time | 🟡 stub GED + bridge réel | Lien via bridge uniquement |
| Bexio | ❌ | Interface `ErpInvoiceControl` |

---

## Variables d'environnement (référence)

```env
# --- Core (obligatoire) ---
DB_*  APP_URL  ...

# --- Connecteur ingest CMD v4 (optionnel) ---
CMD_V4_ENABLED=false                  # cible factures
CMD_V4_URL=http://127.0.0.1:8510      # défaut CMD v4 (CLEARMYDOCS_PORT)
CMD_V4_PATH=

# --- Connecteur ERP WinBiz (optionnel) ---
WINBIZ_ENABLED=false
WINBIZ_BRIDGE_URL=http://127.0.0.1:5100

# --- Plugins métier (optionnel) ---
INVOICES_APP_ENABLED=false            # requiert erp-winbiz pour contrôle live
SMQ_APP_ENABLED=false
RH_APP_ENABLED=false

# --- Autres connecteurs (optionnel) ---
ONLYOFFICE_ENABLED=false
INFOMANIAK_AI_ENABLED=false
INFOMANIAK_AI_API_KEY=
INFOMANIAK_AI_API_SECRET=
INFOMANIAK_AI_MODEL=swiss-ai/Apertus-70B-Instruct-2509
HTMLEDITOR_TAXONOMY_PATH=
```

---

## Diagramme flux document

```
                    ┌──────────────┐
                    │ Upload/scan  │
                    └──────┬───────┘
                           │
              ┌────────────▼────────────┐
              │   IngestEngineRouter    │
              └────────────┬────────────┘
         ┌──────────────────┼──────────────────┐
         ▼                  ▼                  ▼
   ┌───────────┐    ┌─────────────┐    ┌─────────────┐
   │ CMD v4    │    │ CMD v3      │    │ Native GED  │
   │ (si up)   │    │ (si up)     │    │ (toujours)  │
   └─────┬─────┘    └──────┬──────┘    └──────┬──────┘
         └──────────────────┼──────────────────┘
                           ▼
              ┌────────────────────────┐
              │ Document + line_items  │
              │ Workflows · archivage  │
              └────────────┬───────────┘
                           │ optionnel
              ┌────────────▼───────────┐
              │ Plugin invoices +      │
              │ connecteur erp-winbiz  │
              └────────────────────────┘
```

---

## Lots d'implémentation (ordre)

| Lot | Contenu | Gate |
|-----|---------|------|
| **P0** | Documenter + `.env.example` + health admin | Revue |
| **P1** | `config/connectors.php` + `ConnectorRegistry::healthAll()` | Diagnostic admin | ✅ 2026-06-29 |
| **P2** | Client CMD v4 + routage ingest partiel (factures) | Natif seul + v4 OK | ✅ 2026-06-29 |
| **P3** | `erp-winbiz` selon `WINBIZ-PLUGIN-REPOSITIONNE.md` | Contrôle sans écriture |
| **P4** | Dépendances plugins (`invoices` requires winbiz) | UI masquée si down |

---

## Documents liés

| Document | Rôle |
|----------|------|
| `docs/CONNECTEURS-PLUGINS.md` | **Ce fichier** — architecture globale |
| `docs/PLUGIN-SYSTEM.md` | Détail WinBiz terrain + historique |
| `docs/WINBIZ-PLUGIN-REPOSITIONNE.md` | Plugin contrôle facture WinBiz |
| `docs/CMD-V4-CONNECTOR.md` | Connecteur CMD v4 (factures) |
| `docs/DETTE-UI-ORPHELINS.md` | Stubs masqués |
| `docs/ORACLES-KDOCS-PRODUCT.md` | Invariants shell / plugins |
| `connectors/README.md` | Guide création connecteur |

---

*Dernière mise à jour : 2026-06-29 — GED légère, connecteurs optionnels, plugins séparés.*
