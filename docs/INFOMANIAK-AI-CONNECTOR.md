# Connecteur Infomaniak AI Tools (GED)

> Provider cloud suisse pour classification, extraction et chat documentaire.
> Remplace **Claude** lorsque activé (`INFOMANIAK_AI_ENABLED=true`).

## Credentials

| Variable GED | Alias ClearMyDocs / HTMLEDITOR | Rôle |
|--------------|-------------------------------|------|
| `INFOMANIAK_AI_API_KEY` | `INFOMANIAK_API_TOKEN` | Bearer token (clé API Manager) |
| `INFOMANIAK_AI_API_SECRET` | `INFOMANIAK_AI_PRODUCT_ID`, `INFOMANIAK_PRODUCT_ID` | Identifiant produit AI Tools |

Récupérer le `product_id` :

```powershell
$env:INFOMANIAK_API_TOKEN = "votre-cle"
node F:\DATA\DEVELOPPEMENT\htmleditor_v3\htmleditor\scripts\ai-poc-infomaniak-info.js --product-id-only
```

## Configuration `.env`

```env
INFOMANIAK_AI_ENABLED=true
INFOMANIAK_AI_API_KEY=...
INFOMANIAK_AI_API_SECRET=...    # product_id
INFOMANIAK_AI_MODEL=swiss-ai/Apertus-70B-Instruct-2509
```

## Cascade IA

| Couche | Comportement |
|--------|--------------|
| `AIProviderService` | Infomaniak → Ollama (si Infomaniak activé) ; sinon Claude → Ollama |
| `UnifiedClassifier` | Taxonomie HTMLEDITOR → **Infomaniak** → cascade GED native |
| `InfomaniakClassifierAdapter` | Prompt JSON classification → tables GED |

API : `GET /api/ai/status` · test : `POST /api/ai/test`

## Références

- HTMLEDITOR provider : `htmleditor/src/server/word-io/ai/providers/infomaniak.js`
- ClearMyDocs : `clearmydocs-v3/src/clearmydocs/core/providers_infomaniak.py`
- Catalogue modèles : `htmleditor/src/server/settings/infomaniak-catalog.js`
- kDrive (stockage, distinct) : `docs/architecture/KDRIVE_INTEGRATION.md`

---

*2026-06-29 — branchement clé + secret (product_id).*
