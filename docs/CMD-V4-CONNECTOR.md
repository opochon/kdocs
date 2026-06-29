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

## Code GED

| Fichier | Rôle |
|---------|------|
| `app/Services/Ingest/CmdV4Client.php` | Client HTTP (health, projets, jobs, fields) |
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

*Dernière mise à jour : 2026-06-29 — alignement sur cmdv4/docs/API.md (port 8510).*
