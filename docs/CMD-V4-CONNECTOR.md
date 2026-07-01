# Connecteur GED → ClearMyDocs v4

> Adaptateur côté K-Docs. **Contrat HTTP canonique** : `clearmydocs-v3/cmdv4/docs/API.md`
> (dépôt ClearMyDocs, chemin relatif `cmdv4/docs/API.md`).
> Swagger live : `http://127.0.0.1:8510/docs` · OpenAPI : `/openapi.json`.

---

## Rôle

Le GED **ne réimplémente pas** le pipeline CMD. Il orchestre l'API v4 pour les **PDF factures**
(lot P2) et mappe les champs gatés vers les tables MySQL existantes (`invoice_extraction_results`,
`classification_suggestions`, etc.).

Si v4 est indisponible : fallback **v3 sidecar** puis **ingest natif** — jamais de 500 bloquant.

---

## Configuration

| Variable | Défaut | Rôle |
|----------|--------|------|
| `CMD_V4_ENABLED` | `false` | Active le client HTTP |
| `CMD_V4_URL` | `http://127.0.0.1:8510` | Base URL FastAPI (`CLEARMYDOCS_PORT` côté CMD) |
| `CMD_V4_PATH` | — | Chemin dépôt `clearmydocs-v3` (admin / lancement local) |
| `CMD_V4_PROJECT_PROFILE` | `legal_ch` | Profil domaine (`POST /api/projects`) |
| `CMD_V4_JOB_TIMEOUT` | `300` | Timeout poll jobs async |
| `CMD_V4_INVOICE_ENABLED` | `true` | Routage factures PDF vers v4 |
| `CMD_V4_INVOICE_STRICT` | `false` | Si `true`, pas de fallback si v4 échoue |
| `CMD_V4_KEEP_STAGING` | `false` | Conserver le projet éphémère CMD après ingest |

Registre : `config/connectors.php` · spec lots : `docs/CONNECTEURS-PLUGINS.md`.

---

## Parcours API (une facture uploadée)

Pattern documenté dans **API.md §4** ; implémentation : `CmdV4IngestEngine`.

```
1. POST /api/projects          — projet éphémère (source_dir = dossier staging GED, 1 PDF)
2. POST …/extract              — job → corpus .txt
3. POST …/synthesize           — job → annexe gatée + manifeste docs.json
4. POST …/index                  — job → fields.sqlite (schéma profil legal_ch)
5. GET  …/fields/1             — champs gatés DOC ID 1 (facture_fournisseur)
6. DELETE …/projects/{slug}    — nettoyage zone projet (sauf CMD_V4_KEEP_STAGING)
```

Jobs async : poll `GET /api/jobs/{job_id}` jusqu'à `done` | `error` (cf. API.md §3).

**Non utilisé par le connecteur ingest** (hors scope P2) : `report`, `/query`, `/annexe` —
disponibles pour outillage ou lots ultérieurs.

---

## Étape 6 — substrat d'analyse + fraîcheur (dossier/fichier)

> **Contrat canonique côté cmdv4** : `clearmydocs-v3/cmdv4/docs/GED-CONNECTEUR-ETAPE6.md`
> (référence exhaustive + `API.md`). Ce qui suit est la **définition du connecteur GED** qui le
> consomme.

La GED demande l'analyse d'un **dossier** ou d'un **fichier unique** et consomme l'**annexe gatée**
(couche MACHINE : faits sourcés `[DOC ID:N]`, gate F0) comme **substrat** pour **classer et
rechercher** — jamais la prose (`report.md`). Elle sait ensuite si ce substrat est **à jour** vs
la source.

### Endpoints cmdv4 consommés

| Méthode | Chemin | Rôle |
|---|---|---|
| `POST` | `/api/analyze-file` | Fichier unique : un job extract+synthèse+gate+manifeste+snapshot → annexe d'UN fichier. `{job_id, slug}`. |
| `GET`  | `/api/projects/{slug}/annexe` | Substrat : `{annexe_md, gate}`. |
| `GET`  | `/api/projects/{slug}/docs` | Manifeste : résoudre `[DOC ID:N]` → fichier source. |
| `GET`  | `/api/projects/{slug}/fidelity` | Verdict F2 de l'annexe. |
| `GET`  | `/api/projects/{slug}/freshness` | Fraîcheur : `up_to_date` + `changed/added/removed`. |
| `POST` | `/api/projects` + `/extract` + `/synthesize` | Variante dossier (parcours API §4 d'`API.md`). |

### Séquence fichier unique
```
1. POST /api/analyze-file   {path, profile} -> {job_id, slug} -> poll GET /api/jobs/{job_id}
2. GET  /api/projects/{slug}/annexe     -> substrat (annexe_md) à indexer
3. GET  /api/projects/{slug}/freshness  -> up_to_date ? si false -> ré-analyser puis ré-indexer
```

### Définition du connecteur GED (à implanter côté K-Docs)
- **Client** : étendre `app/Services/Ingest/CmdV4Client.php` — méthodes `analyzeFile(path, profile)`,
  `annexe(slug)`, `docs(slug)`, `fidelity(slug)`, `freshness(slug)`. Poll jobs réutilisé.
- **Mapper** : `CmdV4ResultMapper` — `annexe_md` → table d'indexation GED (un fait = une ligne
  ancrée `DOC ID` + `slug` cmdv4 + `gate` F0/F2). Conserver le `slug` cmdv4 pour le lien retour.
- **Indexation** : indexer `annexe_md` dans Qdrant (recherche sémantique) + MySQL (recherche
  déterministe par `DOC ID`/`source`). `GET .../docs` résout une citation vers le fichier réel.
- **Fraîcheur** : colonne `cmdv4_analyzed_at` + `cmdv4_up_to_date` par dossier/fichier analysé.
  Job GED périodique appelle `GET .../freshness` ; si `up_to_date=false` → ré-analyser
  (`synthesize` ou `analyze-file`) puis ré-indexer. Surface ce statut dans l'UI (« à jour / non »).
- **Config** : réutilise `CMD_V4_URL`, `CMD_V4_PROJECT_PROFILE` (ex. `legal_ch`).
- **Garanties** : le substrat est gaté (ancré à la source) ; la GED ne réinvente rien, elle
  indexe et recherche sur des faits sourcés.

---

## Code GED

| Fichier | Rôle |
|---------|------|
| `app/Services/Ingest/CmdV4Client.php` | Client HTTP (health, projets, jobs, fields, **étape 6** annexe/fraîcheur) |
| `app/Services/Ingest/CmdV4CapabilityProbe.php` | Santé + éligibilité PDF facture |
| `app/Services/Ingest/CmdV4IngestEngine.php` | Pipeline staging → champs |
| `app/Services/Ingest/CmdV4ResultMapper.php` | JSON CMD → tables GED |
| `app/Services/Ingest/IngestEngineRouter.php` | v4 → v3 → natif |

Sonde admin : `app/Core/ConnectorRegistry.php` via `GET /api/health`.

---

## Schéma facture & P2.5

Profil `legal_ch` : schéma `facture_fournisseur` dans
`cmdv4/product/builtin_schemas/legal_ch.json` (en-tête aujourd'hui).

**P2.5** (côté CMD v4, pas PHP) : extension lignes / TVA dans le schéma ; le mapper GED
suivra sans changer le contrat HTTP.

---

## Vérification locale

```powershell
# CMD v4 debout
irm http://127.0.0.1:8510/api/health

# GED avec connecteur
# .env : CMD_V4_ENABLED=true, CMD_V4_URL=http://127.0.0.1:8510
```

Tests gate : `tests/Unit/Services/Ingest/CmdV4*.php`, smoke migration.

---

*Dernière mise à jour : 2026-06-30 — étape 6 (substrat annexe + fraîcheur) ; contrat canonique
dans `clearmydocs-v3/cmdv4/docs/GED-CONNECTEUR-ETAPE6.md`.*
