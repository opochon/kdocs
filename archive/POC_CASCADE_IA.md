# Archive POC - Cascade IA

## Statut : ARCHIVÉ (2026-02-04)

Le Proof of Concept a été **validé à 100%** (59/59 tests) et ses fonctionnalités ont été intégrées dans l'application principale.

## Ce qui a été intégré

| Composant POC | Intégré dans |
|---------------|--------------|
| 02_ocr_extract.php | app/Services/ExtractionService.php |
| 03_ai_classify.php | app/Services/AIProviderService.php |
| 04_suggest_classify.php | app/Services/ClassificationService.php |
| 05_thumbnail.php | app/Services/ThumbnailService.php |
| 06_consume_flow.php | app/Services/ConsumeFolderService.php |
| 07_detect_flow.php | app/Services/FilesystemWatcherService.php |
| 08_training.php | app/Services/TrainingService.php |
| helpers.php | app/Helpers/AIHelper.php |

## Samples

Les fichiers de test ont été déplacés vers `tests/samples/`.

## Pour référence

Le code POC original reste dans `proofofconcept/` pour référence historique.
Ne pas modifier - code figé.

## Tests

Les tests POC (`proofofconcept/test_all.php`) peuvent encore être exécutés pour valider que les composants de base fonctionnent, mais les tests principaux sont maintenant dans `tests/`.

```bash
# Tests principaux (recommandé)
test.bat all

# Tests POC legacy (optionnel)
php proofofconcept/test_all.php
```
