# K-DOCS — PILOTAGE

> **Claude : lis BEFORE_YOU_START.md en premier, puis ce fichier.**

---

## MATURITÉ PROJET : 95%

| Critère | Status |
|---------|--------|
| Gouvernance | ✅ BEFORE_YOU_START + REGLES_IMMUABLES + PROCESS_DEV |
| Mémoire inter-sessions | ✅ SESSION_STATE.md (lecture/écriture via MCP) |
| Tests automatisés | ✅ smoke + api + integration + ui |
| Anti-régression | ✅ Pre-commit hook + test.bat check |
| Error tracking | ✅ ErrorTracker centralisé |
| DB Migrations | ✅ Versionnées + rollback |
| Backups | ✅ Auto + vérification + restore |
| Rate limiting | ✅ API protégée |
| Input validation | ✅ Validator strict |
| Config validation | ✅ ConfigValidator au boot |
| CLI tools | ✅ kdocs.bat |

---

## COMPOSANTS CORE

### Sécurité & Fiabilité
| Fichier | Rôle |
|---------|------|
| `app/Core/ConfigValidator.php` | Valide config au démarrage |
| `app/Core/ErrorTracker.php` | Centralise toutes les erreurs |
| `app/Core/Migrations.php` | Migrations DB versionnées |
| `app/Core/Validator.php` | Validation stricte inputs |
| `app/Services/BackupService.php` | Backup/restore avec vérification |
| `app/Middleware/RateLimitMiddleware.php` | Protection contre abus |

### IA & Classification
| Fichier | Rôle |
|---------|------|
| `app/Services/AIProviderService.php` | Cascade Claude → Ollama → Rules |
| `app/Services/ClassificationService.php` | Classification documents |
| `app/Services/TrainingService.php` | Apprentissage corrections |
| `app/Helpers/AIHelper.php` | Utilitaires IA |

### Extraction & Traitement
| Fichier | Rôle |
|---------|------|
| `app/Services/ExtractionService.php` | PDF, DOCX, MSG |
| `app/Services/ThumbnailService.php` | Miniatures |
| `app/Services/ConsumeFolderService.php` | Flux consume |

---

## COMMANDES CLI

```cmd
REM Tests
test.bat check          # Validation rapide (obligatoire)
test.bat all            # Suite complète

REM Administration
kdocs.bat migrate           # Exécuter migrations
kdocs.bat migrate:status    # État migrations
kdocs.bat migrate:rollback  # Annuler dernière
kdocs.bat migrate:create X  # Créer migration

kdocs.bat backup            # Créer backup
kdocs.bat backup:list       # Lister
kdocs.bat backup:verify X   # Vérifier
kdocs.bat backup:restore X  # Restaurer

kdocs.bat config:check      # Valider config
kdocs.bat errors            # Erreurs récentes
kdocs.bat errors:clear      # Nettoyer logs

REM Nettoyage
clean.bat                   # Clean + check
```

---

## STRUCTURE

```
kdocs/
├── app/
│   ├── Controllers/        # HTTP
│   ├── Services/           # Logique métier
│   ├── Core/               # Framework (DB, Config, Validator, Migrations, ErrorTracker)
│   ├── Middleware/         # RateLimit, Auth, CSRF
│   └── Helpers/            # Utilitaires
├── config/
├── database/
│   └── migrations/         # Migrations versionnées
├── storage/
│   ├── backups/            # Backups auto
│   ├── logs/               # Error tracking
│   └── documents/          # Fichiers utilisateur
├── tests/
│   └── samples/            # Fichiers de test
├── docs/
│   ├── REGLES_IMMUABLES.md
│   ├── PROCESS_DEV.md
│   └── pilotage/PILOTAGE.md
├── tools/
│   └── pre-commit          # Git hook
├── BEFORE_YOU_START.md     # Point d'entrée
├── test.bat                # Tests
├── kdocs.bat               # CLI admin
└── clean.bat               # Nettoyage
```

---

## TESTS

### Seuils
| Suite | Minimum | Cible |
|-------|---------|-------|
| Smoke | 100% | 100% |
| API | 95% | 100% |
| Integration | 90% | 95% |

### Commandes
```cmd
test.bat check       # OBLIGATOIRE avant commit
test.bat all         # Avant release
test.bat report      # Rapport HTML
```

---

## HISTORIQUE

### 2026-02-04 (MATURITÉ 95%)
- ConfigValidator : validation config au boot
- ErrorTracker : centralisation erreurs
- Migrations : système complet avec rollback
- BackupService : backup/restore + vérification
- RateLimitMiddleware : protection API
- Validator : validation stricte inputs
- kdocs.bat : CLI admin
- Pre-commit hook amélioré

### 2026-02-04 (MATURITÉ 80%)
- Suite de tests complète
- Anti-régression basique
- Documentation

### 2026-02-01-03
- POC validé 100%
- Merge dans app/
- Cascade IA fonctionnelle

---

## WORKFLOW

1. `kdocs.bat config:check` → OK ?
2. `test.bat check` → OK ?
3. Créer branche
4. Coder
5. `test.bat check` → OK ?
6. Commit conventionnel
7. Push

---

*Dernière mise à jour : 2026-02-04*
