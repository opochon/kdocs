# K-Docs - Prompt Session Recherche Sémantique + Tests

## Contexte

K-Docs est une GED PHP (Slim 4, MariaDB) avec :
- OnlyOffice (Docker) ✅ fonctionnel
- Qdrant (Docker) ✅ container OK
- Ollama (local) ✅ avec nomic-embed-text
- EmbeddingService ✅ créé
- VectorStoreService ✅ créé
- SnapshotService ✅ créé

## État actuel : 90% production-ready

### Complété cette session :
1. ✅ `VectorStoreService.php` - Client Qdrant complet
2. ✅ `full_test_suite.php` - Suite de tests complète (quick/full/stress)
3. ✅ `smoke_test.php` - Mis à jour (32 checks)
4. ✅ `TEST_PLAN.md` - Documentation tests
5. ✅ Scripts batch (`run_tests.bat`)

### Reste à faire :

#### P1 - Intégration (1h)
```
1. Hook embedding dans DocumentProcessor.php
   - Après save() → dispatch EmbedDocumentJob
   - Après delete() → VectorStoreService::delete()

2. Modifier AISearchService.php
   - Utiliser VectorStoreService pour recherche sémantique
   - Fallback sur LIKE SQL si Qdrant indisponible

3. Tester flux complet:
   - Upload document
   - Vérifier embedding_status = 'completed'
   - Vérifier présence dans Qdrant
   - Recherche sémantique retourne le document
```

#### P2 - Extraction DOCX (30min)
```
1. composer require phpoffice/phpword
2. Modifier MetadataExtractor.php
   - Extraire texte DOCX directement (pas OCR)
3. Tester classification IA sur DOCX
```

#### P3 - UI Admin Snapshots (1h)
```
1. Créer templates/admin/snapshots.php
2. Route /admin/snapshots
3. Liste des snapshots avec stats
4. Bouton création manuelle
5. Afficher versions document dans show.php
```

## Fichiers clés créés/modifiés

```
app/Services/
├── VectorStoreService.php     ✅ NOUVEAU - Client Qdrant
├── EmbeddingService.php       ✅ Existait
├── SnapshotService.php        ✅ Existait
├── DocumentProcessor.php      ⚠️ À MODIFIER (hooks)
└── AISearchService.php        ⚠️ À MODIFIER (utiliser vectors)

tests/
├── smoke_test.php             ✅ MIS À JOUR (32 checks)
├── full_test_suite.php        ✅ NOUVEAU
├── TEST_PLAN.md               ✅ NOUVEAU
└── run_tests.bat              ✅ NOUVEAU

docker-compose.yml             ✅ OnlyOffice + Qdrant
AUDIT_2026-01-30.md           ✅ NOUVEAU
```

## Commandes utiles

```bash
# Démarrer les services Docker
cd C:\wamp64\www\kdocs
docker-compose up -d

# Vérifier statut
docker-compose ps

# Smoke test
php tests/smoke_test.php

# Tests complets
php tests/full_test_suite.php --quick
php tests/full_test_suite.php --full
php tests/full_test_suite.php --stress

# Sync embeddings manuellement
php -r "
require 'vendor/autoload.php';
\$service = new \KDocs\Services\VectorStoreService();
print_r(\$service->syncPending(50));
"
```

## Configuration actuelle (config.php)

```php
'embeddings' => [
    'enabled' => true,
    'provider' => 'ollama',
    'ollama_url' => 'http://localhost:11434',
    'ollama_model' => 'nomic-embed-text',
    'dimensions' => 768,
],
'qdrant' => [
    'host' => 'localhost',
    'port' => 6333,
    'collection' => 'kdocs_documents',
],
'onlyoffice' => [
    'enabled' => true,
    'server_url' => 'http://localhost:8080',
    'callback_url' => 'http://192.168.1.14/kdocs',
],
```

## Tests à exécuter

```bash
# 1. Vérifier services Docker
curl http://localhost:8080/healthcheck  # OnlyOffice
curl http://localhost:6333/collections  # Qdrant
curl http://localhost:11434/api/tags    # Ollama

# 2. Smoke test
php tests/smoke_test.php

# 3. Test VectorStoreService
php -r "
require 'vendor/autoload.php';
\$vs = new \KDocs\Services\VectorStoreService();
echo 'Available: ' . (\$vs->isAvailable() ? 'YES' : 'NO') . \"\n\";
echo 'Collection created: ' . (\$vs->createCollection() ? 'YES' : 'NO') . \"\n\";
print_r(\$vs->getStatus());
"

# 4. Test EmbeddingService
php -r "
require 'vendor/autoload.php';
\$es = new \KDocs\Services\EmbeddingService();
echo 'Available: ' . (\$es->isAvailable() ? 'YES' : 'NO') . \"\n\";
\$vec = \$es->embed('Test de génération embedding');
echo 'Vector size: ' . count(\$vec) . \"\n\";
"
```

## Prochaine session - Objectifs

1. **Intégrer les hooks** dans DocumentProcessor
2. **Modifier AISearchService** pour utiliser Qdrant
3. **Créer UI admin** pour snapshots
4. **Tester le flux complet** upload → embedding → recherche
5. **Optionnel**: Extraction DOCX avec phpword

## Score projet

| Avant session | Après session |
|---------------|---------------|
| 8.5/10 | 9.0/10 |

**Estimation pour 100%: 2-3 heures de travail**
