# Cherry Pick Guide - POC → GED

## Code validé à intégrer

Ce fichier trace précisément **quoi prendre, d'où, vers où**.

---

## 05_thumbnail.php → ThumbnailGenerator.php

### Fonctions à copier

```php
// DEPUIS: proofofconcept/05_thumbnail.php
// VERS: app/Services/ThumbnailGenerator.php

/**
 * Convertit Office en PDF via LibreOffice
 * LIGNE: ~45-85
 */
function office_to_pdf(string $officePath): ?string

/**
 * Génère miniature depuis PDF via Ghostscript
 * LIGNE: ~15-40
 */
function thumbnail_from_pdf(string $pdfPath, string $outputPath): bool

/**
 * Génère placeholder stylisé
 * LIGNE: ~120-180
 */
function generate_placeholder(string $outputPath, string $extension): bool
```

### Adaptations requises

- Changer `poc_config()` → `Config::load()`
- Changer `poc_log()` → `error_log()` ou Logger
- Ajouter `$this->` pour méthodes de classe

---

## 03_semantic_embed.php → EmbeddingService.php

### Fonctions à copier

```php
// DEPUIS: proofofconcept/03_semantic_embed.php
// VERS: app/Services/EmbeddingService.php

/**
 * Convertit embedding en BLOB MySQL
 * LIGNE: ~65
 */
function embedding_to_blob(array $embedding): string {
    return pack('f*', ...$embedding);
}

/**
 * Convertit BLOB MySQL en embedding
 * LIGNE: ~70
 */
function blob_to_embedding(string $blob): array {
    return array_values(unpack('f*', $blob));
}

/**
 * Similarité cosinus
 * LIGNE: ~75-95
 */
function cosine_similarity(array $a, array $b): float
```

### Adaptations requises

- Intégrer dans classe existante
- Ajouter méthode `storeInMySQL($docId, $embedding)`

---

## 04_suggest_classify.php → ClassificationService.php

### Fonctions à copier

```php
// DEPUIS: proofofconcept/04_suggest_classify.php
// VERS: app/Services/ClassificationService.php

/**
 * Extrait date du texte
 * LIGNE: ~25-60
 */
function extract_date(string $text): ?string

/**
 * Extrait montant du texte
 * LIGNE: ~65-90
 */
function extract_amount(string $text): ?float

/**
 * Suggestion par règles (patterns)
 * LIGNE: ~130-200
 */
function suggest_by_rules(string $text, string $filename, array $rules): array

/**
 * Suggestion par sémantique
 * LIGNE: ~205-280
 */
function suggest_by_semantic(string $text): array
```

### Adaptations requises

- Merger avec logique existante
- Utiliser `$this->db` au lieu de `poc_db()`

---

## 02_ocr_extract.php → OCRService.php

### Fonctions à copier

```php
// DEPUIS: proofofconcept/02_ocr_extract.php
// VERS: app/Services/OCRService.php

/**
 * Extraction texte DOCX (XML natif)
 * LIGNE: ~85-115
 */
function extract_docx_text(string $path): ?array

/**
 * Détection langue simple
 * LIGNE: ~120-145
 */
function detect_language(string $text): string
```

### Adaptations requises

- Vérifier si méthodes similaires existent déjà
- Unifier avec méthodes existantes

---

## 01_detect_changes.php → IndexingService.php

### Fonctions à copier

```php
// DEPUIS: proofofconcept/01_detect_changes.php
// VERS: app/Services/FilesystemIndexer.php ou nouveau IndexingService.php

/**
 * Compare état actuel vs précédent
 * LIGNE: ~70-100
 */
function detect_changes(array $current, array $previous): array

/**
 * Compare avec la DB
 * LIGNE: ~105-145
 */
function compare_with_db(array $currentFiles): array
```

### Adaptations requises

- Intégrer avec système de queue existant
- Utiliser fichiers .index/.indexing existants

---

## Checklist post cherry-pick

Pour chaque fichier modifié:

- [ ] Code copié et adapté
- [ ] Imports/namespaces corrects
- [ ] Tests unitaires passent
- [ ] Smoke test passe
- [ ] Test manuel OK
- [ ] Git commit avec message clair

---

## Commandes utiles

```bash
# Diff entre POC et GED
diff proofofconcept/05_thumbnail.php app/Services/ThumbnailGenerator.php

# Backup avant modification
copy app\Services\ThumbnailGenerator.php app\Services\ThumbnailGenerator.php.bak

# Test après modification
php tests\smoke\smoke_test.php
```
