# Intégration POC → GED

## Principe

Chaque composant POC validé est intégré **un par un** dans la GED, avec test après chaque merge.

## Mapping POC → GED

| POC | GED | Action |
|-----|-----|--------|
| `helpers.php` | - | Ne pas intégrer (utilitaires POC) |
| `config.php` | - | Ne pas intégrer (config POC) |
| `01_detect_changes.php` | `Services/IndexingService.php` | Améliorer détection delta |
| `02_ocr_extract.php` | `Services/OCRService.php` | Remplacer/améliorer extraction |
| `03_semantic_embed.php` | `Services/EmbeddingService.php` | Ajouter stockage MySQL BLOB |
| `04_suggest_classify.php` | `Services/ClassificationService.php` | Hybride règles + sémantique |
| `05_thumbnail.php` | `Services/ThumbnailGenerator.php` | Fix chaîne LibreOffice |

## Processus d'intégration

### Pour chaque composant:

```bash
# 1. Backup
copy app\Services\XXXService.php app\Services\XXXService.php.backup

# 2. Intégrer les fonctions validées
# (copier/adapter le code du POC)

# 3. Tester
php tests\smoke\smoke_test.php

# 4. Si OK, commit
git add app\Services\XXXService.php
git commit -m "feat: integrate POC XXX - [description]"

# 5. Si KO, rollback
copy app\Services\XXXService.php.backup app\Services\XXXService.php
```

## Ordre d'intégration recommandé

1. **05_thumbnail.php** → ThumbnailGenerator (fix miniatures DOCX)
2. **02_ocr_extract.php** → OCRService (améliorer extraction)
3. **03_semantic_embed.php** → EmbeddingService (MySQL BLOB)
4. **04_suggest_classify.php** → ClassificationService (hybride)
5. **01_detect_changes.php** → IndexingService (delta)

## Fonctions à copier

### ThumbnailGenerator

```php
// Depuis 05_thumbnail.php
- office_to_pdf()      // LibreOffice → PDF
- thumbnail_from_pdf() // Ghostscript → JPG
- generate_placeholder() // Placeholder stylisé
```

### EmbeddingService

```php
// Depuis 03_semantic_embed.php
- embedding_to_blob()  // pack('f*', ...)
- blob_to_embedding()  // unpack('f*', ...)
- cosine_similarity()  // Recherche sémantique
```

### ClassificationService

```php
// Depuis 04_suggest_classify.php
- extract_date()       // Détection date dans texte
- extract_amount()     // Détection montant
- suggest_by_rules()   // Classification par patterns
- suggest_by_semantic() // Classification par similarité
```

## Tests après intégration

```bash
# Smoke test complet
php tests\smoke\smoke_test.php

# Test spécifique miniatures
php -r "
require 'vendor/autoload.php';
\$gen = new \KDocs\Services\ThumbnailGenerator();
var_dump(\$gen->getAvailableTools());
"

# Test recherche sémantique
php -r "
require 'vendor/autoload.php';
\$search = new \KDocs\Services\SearchService();
\$result = \$search->search('facture swisscom');
echo \$result->total . ' résultats';
"
```

## Rollback

Si régression détectée:

```bash
# Restaurer backup
copy app\Services\XXXService.php.backup app\Services\XXXService.php

# Ou via git
git checkout HEAD~1 -- app/Services/XXXService.php
```
