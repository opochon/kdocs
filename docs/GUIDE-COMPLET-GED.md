# K-Docs (GEDv1) — Guide complet

> Document d'entrée unique : vision, installe, usage, admin, IA, outils,
> dépannage, tests, parité REDX. Pointe vers les docs détaillées sans les
> dupliquer. État au **2026-06-30** (post-sprint finalisation : bugs A–H
> résolus, IA Infomaniak active, pdftoppm/poppler opérationnel, healthcheck
> durci).
>
> Si un sujet n'est pas traité ici, suivre les liens « → détail ».

---

## 1. Vue d'ensemble

K-Docs est une GED modulaire **self-hosted** (PHP 8.2+, Slim 4, MySQL) pour
fiduciaire / PME suisse : documents on-premise, OCR local, IA configurable
(Infomaniak cloud CH > Ollama local), workflows visuels, édition OnlyOffice,
connecteur WinBiz. Voir `README.md` et `docs/ARCHITECTURE.md`.

| Indicateur | Valeur |
|------------|--------|
| Stack | PHP 8.2+ / Slim 4 / MySQL 8 ou MariaDB 11.5 / Qdrant (option) |
| Classes | ~165 · contrôleurs API ~40 |
| Tests | PHPUnit 307 (686 assertions) · Playwright (persona + pipeline + a11y + chrome) |
| Parité REDX (fiduciaire) | ~54 % — voir `docs/PARITE-REDX-TESTS.md` |
| IA active | Infomaniak AI Tools (cloud CH) > Claude (désactivé) > Ollama (qwen3:8b) |

---

## 2. Installation rapide

1. **Prérequis** : WAMP (PHP 8.2+, MySQL), Tesseract OCR, poppler (pdftoppm/pdftotext),
   Ghostscript, Optionally LibreOffice, Docker (OnlyOffice), Qdrant (recherche sémantique).
   → `docs/install/INSTALLATION_COMPLETE.md`
2. `git clone` + `composer install`.
3. Copier `.env.example` → `.env` (DB, IA, OnlyOffice) + `config/config.php` depuis
   `config/config.example.php`.
4. Créer la BDD + lancer `database/migrations/*.php` (scripts idempotents).
5. Démarrer le serveur (Apache/WAMP) → `http://localhost/kdocs`.

### Outils externes — état cible

| Outil | Rôle | Installation conseillée (Windows) | Config |
|-------|------|-----------------------------------|--------|
| Tesseract | OCR | installateur officiel | `ocr.tesseract_path` |
| **Poppler** (pdftoppm, pdftotext) | Thumbnails PDF + texte PDF | `scoop install poppler` (shim `~/scoop/apps/poppler/current/bin/`) | `tools.pdftoppm`, `tools.pdftotext` |
| Ghostscript | Conversion PDF | installateur officiel | `tools.ghostscript` |
| LibreOffice | Conversion Office | installateur officiel | `tools.libreoffice` |
| OnlyOffice | Édition en ligne | Docker `onlyoffice/documentserver` port 8080 | `onlyoffice.*` |
| Qdrant | Recherche sémantique | binaire local (pas Docker) | `qdrant.enabled` |
| Ollama | IA locale | `ollama pull qwen3:8b` | `ollama.url` |

> **pdftoppm (fix 2026-06-30)** : poppler installé via scoop ; `config.php`
> résout `pdftoppm`/`pdftotext` via `%USERPROFILE%\scoop\apps\poppler\current\bin`
> (ou `%POPPLER_BIN%`). Le diagnostic passe de « Non trouvé » à « Trouvé ».

---

## 3. Usage utilisateur (rôles)

Fonctions exposées UI : `tests/visual/FUNCTIONS-SPEC.md` (référentiel de test).
Guides détaillés : `docs/USER-GUIDE.md` (si présent), `docs/handoff/`.

| Fonction | Où | Spec |
|----------|----|------|
| Bibliothèque / arborescence / upload | `/documents` | F-LIB-01..08 |
| Fiche document (métadonnées, IA, OCR, validation, notes, versions) | modale `#preview` | F-DOC-01..11 |
| Recherche (fulltext + sémantique hybride) | `#search-input`, `/search` | F-SEARCH-01..03 |
| Mes tâches / file de validation | `/mes-tasks` | F-TASK-01/02, F-VAL-01..03 |
| Import / Consume folder | `/documents/upload`, `/admin/consume` | F-IMP-01/02 |
| Assistant IA (langage naturel) | `/ask` (chat) | T-ASK-COUNT |

### Personas de test (rôles fiduciaire)

| Persona | Rôle | Plafond / Scope | Spec |
|---------|------|-----------------|------|
| `eval_secretaire` | VALIDATOR_L1 | ≤ 1000, scope * | F-LIB/DOC/SEARCH/TASK, pas F-DOC-04 > 1000, pas admin |
| `eval_comptable` | VALIDATEUR_FACTURE | ≤ 5000, scope FACTURE | validation factures ≤ 5000 |
| `eval_rh` | VALIDATOR_L1 | scope RH | validation docs RH |
| `eval_employeur` | APPROVER | scope *, sans plafond | validation tous docs |

→ mapping complet : `tests/visual/FUNCTIONS-SPEC.md` § « Mapping persona → fonctions ».
→ specs Playwright : `persona.spec.ts`, `persona-preview.spec.ts`, `a11y.spec.ts`,
   `chrome-coherence.spec.ts`, `smq-versions.spec.ts`, `pipeline-ui.spec.ts`,
   `bugs-click.spec.ts`, `bugs-misc.spec.ts`.

---

## 4. Administration

| Fonction | Où | Spec |
|----------|----|------|
| Hub admin | `/admin` | F-ADM-01 |
| Référentiels (tags, types, correspondants, champs, storage paths, workflows, users, roles) | `/admin/{...}` | F-ADM-02 |
| Règles d'attribution | `/admin/attribution-rules` | F-ADM-03 |
| **Diagnostic** (services + outils + cascade IA) | `/admin/diagnostic` | F-ADM-04 |
| Indexation | `/admin/indexing` | F-ADM-05 |

### Diagnostic — ce qui doit être vert

- **MySQL** : connecté.
- **Outils** : Tesseract, Ghostscript, LibreOffice, **pdftotext**, **pdftoppm** → Trouvé.
- **Cascade IA** : Infomaniak ACTIF (cloud CH) > Claude (CONFIGURE/éteint) > Ollama (CONNECTE, 2 modèles) > Règles.
- **OnlyOffice** : CONNECTE (quand Docker est stable — voir §6).
- **Ollama** : CONNECTE (qwen3:8b, qwen2.5:7b-instruct).

> **Fix 2026-06-30 (`c7db9ce`)** : les healthchecks OnlyOffice/Ollama utilisent
> `httpProbe()` (fsockopen) car curl loopback est cassé sur ce build PHP.
> Ollama remonte désormais CONNECTE.

---

## 5. IA — Infomaniak (fournisseur actif)

Cascade : `AIProviderService` → Infomaniak (si `INFOMANIAK_AI_ENABLED=true` +
clé) > Claude > Ollama > règles. Voir `docs/INFOMANIAK-AI-CONNECTOR.md` et
`docs/architecture/AI_FALLBACK_ARCHITECTURE.md`.

- **Endpoints** : v2 (OpenAI-compatible) prioritaire, v1 en repli.
- **Config** : `.env` → `INFOMANIAK_AI_ENABLED`, `INFOMANI_AI_API_KEY`,
  `INFOMANI_PRODUCT_ID` (typo historique conservée).
- **SSL** : `cacert.pem` Mozilla configuré dans `php.ini`
  (`curl.cainfo`, `openssl.cafile`) — requis pour les appels Infomaniak.
- **Services consommateurs** : classification (`classify-ai`), extraction,
  Assistant IA (`NaturalLanguageQueryService` via `AIProviderService`).
- **Tests** : `tests/Unit/Services/InfomaniakAIServiceTest.php` (mock HTTP) +
  `tests/infomaniak_live_test.php` (live, hors PHPUnit). À compléter : feature
  cascade + Playwright assistant IA (voir `docs/PARITE-REDX-TESTS.md` T-IA-INF).

### Assistant IA — intentions couvertes

- « Combien de documents ai-je ? » → compte global (pas recherche mot-clé).
  Garde-fou `isCountAllQuestion()` + `AIProviderService`. (Fix bug E.)
- Recherche sémantique (« notaire », « factures 2024 ») → conversion IA →
  `SearchQuery` → résultats + résumé.

---

## 6. OnlyOffice — édition en ligne

- Conteneur Docker `onlyoffice/documentserver` port 8080.
- **callback_url** : IP locale reachable depuis Docker (PAS localhost).
- Diagnostic : `httpProbe('/healthcheck')` → `true` = CONNECTE.
- **Blocker env connu** : Docker Desktop peut cacher son named pipe
  (`docker_engine` / `dockerDesktopLinuxEngine`) et OnlyOffice peut cesser de
  répondre après démarrage. Actions manuelles :
  relancer Docker Desktop, `wsl --shutdown` (destructif — accord requis),
  allouer ≥ 4 Go à WSL2. → `docs/ONLYOFFICE_TROUBLESHOOTING.md`.

---

## 7. Sécurité

Voir `docs/SECURITY.md` et `docs/REGLES_IMMUABLES.md`. Points clés :
- Auth, CSRF, rate-limit en place.
- **PAS d'envoi de contenu document vers Claude en prod** (fuite mandants) —
  préférer Infomaniak (cloud CH) ou Ollama (local).
- Antivirus upload (ClamAV) = GAP-045 (absent).
- Conformité archivage légal CH (Olico/GeBüV) = GAP-020..024 (absent, P2).

---

## 8. Tests & fiabilité

| Suite | Commande | Couverture |
|-------|----------|------------|
| PHPUnit | `vendor/bin/phpunit` | 307 tests, 686 assertions |
| PHPStan | `vendor/bin/phpstan analyse --memory-limit=512M` | baseline `phpstan-baseline.neon` |
| Playwright | `npm --prefix tests/visual test` | persona + pipeline + a11y + chrome + bugs |
| Smoke migration | pre-commit hook | `tests/migration_smoke_test.php` |

Gates détaillées : `docs/ORACLES.md`, `docs/ORACLES-KDOCS-PRODUCT.md`,
`docs/PARITE-REDX-TESTS.md`. Règle : *toute régression corrigée est épinglée
par un test*.

---

## 9. Parité REDX & roadmap

- Analyse : `docs/PANORAMA-GED-REDX.md`.
- Delta statut : `docs/DELTA-REDX.md`.
- **Plan de tests par gap** : `docs/PARITE-REDX-TESTS.md` (38 gaps P0–P4, oracle
  + mécanisme par gap).
- Roadmap produit : `docs/ROADMAP-KDOCS-PRODUCT.md`, `docs/ROADMAP.md`.

Lots priorisés : P1 WinBiz/factures → P2 archivage légal CH → P3 modules métier
(contrats, SMQ, RH) → P4 infra (ACL fine, multi-mandant, portail, e-signature,
ClamAV).

---

## 10. Docs détaillées (index)

| Sujet | Document |
|-------|----------|
| Architecture | `docs/ARCHITECTURE.md`, `docs/architecture/*` |
| Inventaire fonctions PHP | `docs/FUNCTIONS-INDEX.md`, `docs/CODE-ANALYSIS.md` |
| Spec UI / tests | `tests/visual/FUNCTIONS-SPEC.md` |
| API | `docs/api/API.md`, `docs/api/WEBHOOKS_INTERET.md` |
| IA | `docs/INFOMANIAK-AI-CONNECTOR.md`, `docs/IA-ROADMAP.md`, `docs/architecture/AI_FALLBACK_ARCHITECTURE.md` |
| OnlyOffice | `docs/ONLYOFFICE_TROUBLESHOOTING.md` |
| WinBiz | `docs/WINBIZ-MODULE.md`, `docs/WINBIZ-PLUGIN-REPOSITIONNE.md` |
| Plugins/connecteurs | `docs/PLUGIN-SYSTEM.md`, `docs/CONNECTEURS-PLUGINS.md`, `docs/CMD-V4-CONNECTOR.md` |
| Sécurité | `docs/SECURITY.md`, `docs/REGLES_IMMUABLES.md` |
| Tests | `docs/GUIDE_TEST_COMPLET.md`, `docs/TEST_PLAN.md`, `docs/ORACLES.md` |
| Install | `docs/install/*` |
| État projet | `SESSION-STATUS.md`, `docs/SESSION-STATUS.md`, `docs/WORKLOG.md` |
| Dette | `docs/DETTE-UI-ORPHELINS.md`, `docs/AUDIT-CODE-QUALITE.md` |

---

*Dernière mise à jour : 2026-06-30 — sprint finalisation & fiabilisation.*
