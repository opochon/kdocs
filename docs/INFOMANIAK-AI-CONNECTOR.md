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

## Endpoints chat completions

`InfomaniakAIService::complete()` essaie **v2 d'abord** puis **v1 en fallback** :

| Endpoint | Catalogue | Remarque |
|----------|-----------|----------|
| `/2/ai/{pid}/openai/v1/chat/completions` | complet (Apertus-70B, etc.) | OpenAI-compatible, défaut |
| `/1/ai/{pid}/openai/chat/completions` | restreint (`mistral24b`, `mistral3`, `qwen3`) | 422 sur les autres modèles |

Retry sur `429/503/504` (délais `0/5/15/30s`), `404` et `422` non-retryables → fallback endpoint.

## Vérification live

```cmd
php tests\infomaniak_live_test.php
```

Gate : `INFOMANIAK_AI_ENABLED=true` + clé + product_id dans `.env`. Skip propre (exit 0) si non configuré.
Vérifie : détection provider, Claude désactivé, `GET /1/ai` (health), `complete()` (prompt court), `classifyDocument()` (doc réel de la base).

## Désactivation de Claude

Claude est désactivé dès que Infomaniak est activé (priorité de cascade). Pour que `claude.available=false` aussi :

1. Vider `ANTHROPIC_API_KEY` dans `.env` (et tout fichier `claude_api_key.txt` à la racine).
2. Vider le setting DB `ai.claude_api_key` (s'il provient de la table `settings`) :

```sql
UPDATE settings SET value = '' WHERE `key` = 'ai.claude_api_key';
```

## Prérequis environnement (curl TLS)

L'appel Infomaniak nécessite un CA bundle curl valide. Sur WAMP/local, si `curl.cainfo` est vide
(erreur `SSL certificate problem: self-signed certificate in certificate chain`) :

1. Télécharger `https://curl.se/ca/cacert.pem` vers `…\php\extras\ssl\cacert.pem`.
2. Dans `php.ini` : `curl.cainfo="…\cacert.pem"` et `openssl.cafile="…\cacert.pem"`.

## Tests

| Test | Portée | Fichier |
|------|--------|---------|
| Unit (mock HTTP, hermétique) | retry 503, fallback v1/v2, 404, parse JSON, désactivé | `tests/Unit/Services/InfomaniakAIServiceTest.php` |
| Live (gated .env) | détection + health + complete + classify réel | `tests/infomaniak_live_test.php` |

> Les tests unitaires neutralisent l'env Infomaniak (`phpunit.xml` + `setUp`) pour rester
> indépendants du `.env` local. Le live test est standalone (hors suite phpunit).

## Références

- HTMLEDITOR provider : `htmleditor/src/server/word-io/ai/providers/infomaniak.js`
- ClearMyDocs : `clearmydocs-v3/src/clearmydocs/core/providers_infomaniak.py`
- Catalogue modèles : `htmleditor/src/server/settings/infomaniak-catalog.js`
- kDrive (stockage, distinct) : `docs/architecture/KDRIVE_INTEGRATION.md`

---

*2026-06-30 — connexion live validée (clé + product_id 108640), endpoints v2-first, désactivation Claude, tests mock+live, CA bundle curl.*
