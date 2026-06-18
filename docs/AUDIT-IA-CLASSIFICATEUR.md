# GEDv1 (K-Docs) — Audit IA et classificateur

> Date : 2026-06-18  
> Périmètre : IA GEDv1 + comparaison HTMLEDITOR v3 + recherche Flowy/Infomaniak  
> Vision utilisateur : le classificateur GED doit **reprendre les infos métier** de HTMLEDITOR et, si disponible, Flowy > Infomaniak

---

## Synthèse

| Dimension | Score (1–10) | Commentaire |
|-----------|--------------|-------------|
| IA GEDv1 (existant) | **6** | Cascade riche, training local, embeddings — fragmenté |
| Alignement HTMLEDITOR | **2** | Aucun pont données aujourd'hui |
| Alignement Flowy | **0** | Projet **non trouvé** sur poste dev |
| Architecture cible faisabilité | **7** | Contrats API GED + sidecar HTMLEDITOR mappables |

---

## État actuel IA dans GEDv1

### Stack IA

| Composant | Fichier / config | Rôle |
|-----------|------------------|------|
| Cascade | `config.ai.cascade` | `['training', 'claude', 'ollama', 'rules']` |
| Claude API | `ClaudeService`, `AIClassifierService` | Classification JSON, lecture PDF/images |
| Ollama local | `config.ollama` | `llama3.1:8b` classification, `nomic-embed-text` embeddings |
| Training | `TrainingService`, `storage/training.json` | Corrections + similarité embeddings |
| Règles | `AutoClassifierService`, `AttributionRuleEngine` | Regex dates/montants, règles BDD |
| Champs IA | `FieldAIClassifierService` | Extraction par champ classification |
| Embeddings | `EmbeddingService` | Ollama → MySQL ; sync auto |
| Vector search | `VectorSearchService`, Qdrant | **Désactivé** (`qdrant.enabled=false`) |
| Chat | `ChatApiController`, `AISearchService` | Interrogation documents |
| Recherche NL | `NaturalLanguageQueryService` | Requêtes langage naturel |
| Provider abstraction | `AIProviderService` | Fallback Claude/Ollama |

### Modèle de données classification (BDD)

| Table / concept | Usage |
|-----------------|-------|
| `document_types` | Types métier (Facture, Contrat…) |
| `correspondents` | Émetteurs / contreparties |
| `tags` | Étiquettes libres |
| `classification_fields` | Champs structurés (`field_code`, `field_name`) |
| `attribution_rules` | Règles if/then sur patterns |
| `custom_fields` | Métadonnées extensibles |
| `classification_audit_logs` | Traçabilité modifications |
| `embeddings` | Vecteurs document (migration 026) |

### Flux classification document

```
Document ingéré (content / ocr_text)
        ↓
1. TrainingService.getTrainedClassification()  — similarité embedding corrections passées
        ↓ (si miss)
2. AIClassifierService.classify()              — prompt Claude/Ollama → JSON suggestions
        ↓ (si miss / erreur)
3. AutoClassifierService.classify()            — regex + règles attribution
        ↓
ClassificationSuggestionsApiController       — UI valide / corrige
        ↓
TrainingService.storeCorrection()              — apprentissage si correction user
```

**Oracle respecté** : suggestions ≠ application automatique (`classification.auto_apply=false` par défaut).

### Points forts IA GEDv1

- Cascade configurable avec fallback local (Ollama) — pas de dépendance cloud obligatoire.
- Training par corrections — amélioration continue sans retraining lourd.
- Audit classification traçable.
- Split PDF multi-documents IA (`PDFSplitterService`, `classification.ai_split_enabled`).
- Chat + recherche sémantique prévus.

### Points faibles IA GEDv1

- **Prompt générique** — pas de taxonomie métier Stoco (manuel, sections, variables).
- Training stocké en **JSON fichier** (`storage/training.json`) — pas versionné, pas multi-tenant.
- Embeddings 768d Ollama vs Qdrant off — recherche hybride incomplète.
- Pas de contrat d'échange avec HTMLEDITOR (projets, variables, blocs, word conventions).
- Infomaniak kDrive : config stockage seulement (`config.kdrive`) — pas de classification cloud.

---

## HTMLEDITOR v3 — métadonnées et modèle doc

Chemin : `F:\DATA\DEVELOPPEMENT\htmleditor_v3\htmleditor`

### 1. Sidecar document (`sidecar.js`)

Fichier `<docStem>.html.json` à côté du HTML — **source de vérité métadonnées applicatives** :

| Domaine | Contenu sidecar |
|---------|-----------------|
| Identité | `doc`, `created`, `updated`, `schemaVersion` |
| Lineage | `kind`, `parent`, `forkOf`, `children` — arbre versions |
| Bookmarks | `uuid`, `label`, `author`, `status` |
| Notes | `uuid`, `title`, `body`, `status`, `resolution` |
| Revisions | `uuid`, `anchorPid`, batches |
| History | ops `save`, `milestone`, `fork` + `gitSha` |

**Invariant** : commentaires HTML ancrés survivent au pipeline ; sidecar = métadonnées hors contenu.

### 2. Variables projet (`src/server/variables/`)

Fichier `_variables.json` par projet :

```json
{
  "version": 2,
  "variables": { },
  "sets": { "default": {}, "fr": {} },
  "docOverlay": {}
}
```

- Overlay ordonné : projet → langue → document (`resolveVariableOverlay`).
- Substitution `{{var}}` et `{{Set.var}}` style Flare-lite.
- API : `GET/PUT /api/variables`, `POST /resolve`, `POST /preview`.

**Intérêt GED** : même vocabulaire métier (client, produit, version manuel) pour classifier et router documents.

### 3. Blocs / sections (`src/server/sections/`, `docs/SPEC-BLOCS-SUPERBLOCS.md`)

- Registre `_shared-sections.json` : `guid`, `level`, `label`, `category`, `tags[]`, `externalIds{}`.
- Versionnage content-addressed `_blocks/<guid>/vN.html`.
- API promote, propagate, usages — **taxonomie contenu réutilisable**.

**Intérêt GED** : tags/catégories IA alignés sur catégories blocs HTMLEDITOR.

### 4. Word I/O conventions (`docs/word-io/word-conventions.md`)

- Contrat styles Word → HTML (Heading 1–6, Note, Warning, tables…).
- Mapping styles inconnus persisté par projet à l'import.

**Intérêt GED** : détecter type document (manuel technique vs facture) via conventions structurelles.

### 5. Envelope / préambule

- `splitEnvelope` au open/save — métadonnées SECTION, TOC, firstpage.
- Variables CSS PrinceXML — contexte édition technique Stoco.

### 6. IA HTMLEDITOR

- `src/server/revisions/ai-suggest.js` — suggestions révision (pas classification GED).
- `docs/MENU-IA.md` — architecture chrome, pas de classificateur document entrant.

**Conclusion HTMLEDITOR** : riche en **métadonnées structurées et taxonomie contenu** ; pas de pipeline ingestion PDF — **complémentaire** à GEDv1.

---

## Flowy / Infomaniak — résultat recherche

### Recherche effectuée

| Chemin | Résultat |
|--------|----------|
| `F:\DATA\DEVELOPPEMENT\**\*flowy*` (depth 4–5) | **Aucun fichier** |
| `F:\DATA\DEVELOPPEMENT\**\*Flowy*` | **Aucun fichier** |
| `F:\DATA\DEVELOPPEMENT\**\*infomaniak*` | Timeout / vide |
| `F:\DATA\OBSIDIAN\**\*flowy*` | **Aucun fichier** |
| `htmleditor_v3` | **Aucune référence Flowy** |

### Présence Infomaniak dans GEDv1 uniquement

- `config.kdrive` : `drive_id`, `username`, `password`, `base_path`.
- Settings UI : option stockage « KDrive (Infomaniak) ».
- Doc : `docs/architecture/KDRIVE_INTEGRATION.md`.

**Flowy n'est pas analysable** sur ce poste — hypothèse : produit SaaS Infomaniak externe ou projet non cloné. L'audit recommande de **documenter l'API Flowy** dès accès (Swagger, export OpenAPI, ou dump Obsidian).

---

## Matrice de réutilisation HTMLEDITOR vs Flowy

| Besoin classificateur GED | HTMLEDITOR | Flowy (attendu) | Source prioritaire |
|---------------------------|------------|-----------------|-------------------|
| Taxonomie types document | Sections `category`, `tags`, `externalIds` | Tags cloud ? | **HTMLEDITOR** |
| Variables métier (client, produit, langue) | `_variables.json` sets + overlay | Champs custom SaaS ? | **HTMLEDITOR** |
| Correspondants / tiers | Notes, auteurs sidecar | Contacts org ? | GED natif + sync Flowy si CRM |
| Conventions structure document | `word-conventions.md`, styles | — | **HTMLEDITOR** |
| Version / lineage document | Sidecar `lineage`, history gitSha | Version cloud ? | **HTMLEDITOR** (docs techniques) |
| Stockage fichier | — | kDrive natif | **Infomaniak kDrive** (via GED connector) |
| Classification auto entrante | — | ML cloud ? | **Flowy** (à confirmer) + GED cascade |
| Workflows validation | Révisions, notes status | Workflows SaaS ? | GED + mapping statuts |
| Embeddings / recherche | — | Recherche Infomaniak ? | GED Ollama/Qdrant + index Flowy si API |
| Rapprochement ERP | — | — | GED WinBiz bridge |

### Légende

- ✅ **Reprendre** : importer/sync régulièrement dans référentiels GED.
- 🔄 **Mapper** : correspondance champs, pas copie brute.
- ⏳ **En attente Flowy** : spécifier dès accès projet.

---

## Architecture cible — plugin IA unifié

### Principe

Un module **`KDocs\Plugins\UnifiedClassifier`** (ou connecteur dédié) expose un contrat unique ; adapters par source.

```
┌─────────────────────────────────────────────────────────┐
│              UnifiedClassifierPlugin                     │
│  Interface: classify(DocumentContext): ClassificationResult │
│  Interface: enrichMetadata(DocumentContext): Field[]     │
│  Interface: syncTaxonomy(): TaxonomySnapshot             │
└────────────┬──────────────────┬──────────────────┬────────┘
             │                  │                  │
    ┌────────▼────────┐ ┌───────▼───────┐ ┌───────▼────────┐
    │ GEDNativeAdapter  │ │ HTMLEditorAdapter │ │ FlowyAdapter │
    │ (cascade actuelle)│ │ (API/projet sync) │ │ (API kDrive) │
    └───────────────────┘ └─────────────────┘ └──────────────┘
```

### Contrat `DocumentContext` (proposé)

```php
interface DocumentContext {
    public function getDocumentId(): int;
    public function getTextContent(): string;
    public function getMimeType(): string;
    public function getFilePath(): ?string;
    public function getProjectKey(): ?string;  // lien HTMLEDITOR
    public function getExternalIds(): array;   // Flowy, WinBiz, etc.
}
```

### Contrat `ClassificationResult` (proposé)

```php
interface ClassificationResult {
    public function getSuggestions(): array; // type, correspondent, tags, fields
    public function getConfidence(): float;
    public function getSource(): string;     // training|claude|ollama|htmleditor|flowy|rules
    public function getAuditPayload(): array;
}
```

### Sync taxonomie HTMLEDITOR → GED

| Donnée HTMLEDITOR | Cible GED | Fréquence |
|-------------------|-----------|-----------|
| `_variables.json` keys + sets | `classification_fields` + valeurs suggérées | À l'ouverture projet / webhook |
| Sections `category`, `tags` | `tags` + mapping `document_types` | Sync nocturne |
| `externalIds` blocs | `custom_fields.external_id` | Event-driven |
| Word style mappings | Règles `attribution_rules` | Import one-shot |

### Pont technique minimal (lot 1)

1. **Endpoint HTMLEDITOR** : `GET /api/projects/{id}/taxonomy-export` — agrège variables + sections tags.
2. **Endpoint GED** : `POST /api/classification/sync-taxonomy` — upsert tags/types/champs.
3. **Lien document** : champ `documents.project_key` + `documents.htmleditor_doc_stem`.

---

## Roadmap lots — aligner classificateur

### Lot 0 — Inventaire et contrats (S, 1 semaine)

- [ ] Documenter schéma export taxonomie HTMLEDITOR (variables + sections).
- [ ] Localiser ou créer spec Flowy API (responsable métier).
- [ ] Définir `ClassificationResult` PSR interface dans `app/Contracts/`.

### Lot 1 — Sync taxonomie HTMLEDITOR → GED (M, 2–3 semaines)

- [ ] Script `tools/sync-htmleditor-taxonomy.php` — lecture `_variables.json` + `_shared-sections.json`.
- [ ] Upsert `tags`, `classification_fields`, règles attribution seed.
- [ ] UI admin : bouton « Sync projet HTMLEDITOR » + log audit.

### Lot 2 — Enrichissement classification (M, 3–4 semaines)

- [ ] `HTMLEditorAdapter` dans cascade après `training`, avant `claude`.
- [ ] Prompt Claude enrichi du contexte variables projet si `project_key` présent.
- [ ] Mapping notes/bookmarks sidecar → champs GED optionnels (review status).

### Lot 3 — Connecteur Flowy / kDrive (L, 4–6 semaines — **bloqué accès Flowy**)

- [ ] Reverse-engineer API Flowy ou utiliser kDrive WebDAV/API Infomaniak.
- [ ] `FlowyAdapter` : import métadonnées cloud + push classification validée.
- [ ] Stratégie conflit : GED filesystem-first reste source ; Flowy = miroir ou index.

### Lot 4 — Recherche unifiée (L, 6+ semaines)

- [ ] Activer Qdrant + index hybride (fulltext + vector).
- [ ] Embeddings enrichis texte + métadonnées HTMLEDITOR.
- [ ] Chat IA avec scope projet / variable.

### Lot 5 — Industrialisation (M)

- [ ] Tests intégration cascade avec mock HTMLEDITOR export.
- [ ] Métriques : précision classification par source (training vs Claude vs HTMLEDITOR rules).
- [ ] Dashboard admin IA : taux auto-apply, corrections, coût API Claude.

---

## Décisions recommandées

1. **HTMLEDITOR = source de vérité taxonomie métier** Stoco (variables, sections, conventions Word).
2. **GED = source de vérité documents entrants** (PDF, factures, scans) + workflows validation.
3. **Flowy** : intégration stockage/recherche cloud **après** obtention spec ; ne pas bloquer lots 0–2.
4. **Un seul classificateur** côté GED avec adapters — ne pas dupliquer logique IA dans HTMLEDITOR.

---

*Références : `app/Services/AIClassifierService.php`, `TrainingService.php`, `docs/ORACLES.md`, HTMLEDITOR `sidecar.js`, `src/server/variables/`, `docs/SPEC-BLOCS-SUPERBLOCS.md`, `docs/word-io/word-conventions.md`*
