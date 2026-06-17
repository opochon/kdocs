# Test Samples

Fichiers de test pour la suite K-Docs.

## Fichiers

| Fichier | Type | Usage |
|---------|------|-------|
| test.pdf | PDF natif | Extraction texte |
| PDF105.pdf | PDF scanné | OCR Tesseract |
| PDF106.pdf | PDF scanné | OCR multi-pages |
| RA_anonymise.docx | Word | Extraction DOCX |
| *.msg | Outlook | Extraction emails |

## Copier depuis POC

```bash
# Windows
copy proofofconcept\samples\* tests\samples\

# Ou via PHP
php migrate_samples.php
```

## Usage dans les tests

```php
$sampleFile = __DIR__ . '/samples/test.pdf';
```

## Après migration

Supprimer le dossier `proofofconcept/` - le POC a validé la cascade IA, son travail est terminé.
