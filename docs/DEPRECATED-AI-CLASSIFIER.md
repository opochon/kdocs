# AIClassifierService — gel cascade directe (B0.12)

> Statut : **gelé** depuis lot B0 crédibilité produit (2026-06-22).

## Décision

Toute **nouvelle** logique de classification à l'ingest doit passer par :

```
IngestClassificationService → UnifiedClassifier → GedNativeClassifierAdapter → AIClassifierService (interne)
```

`AIClassifierService` reste le moteur cascade Claude/Ollama/règles, mais **ne doit plus être instancié** depuis :

- contrôleurs API ou UI ;
- `DocumentProcessor` (nouveaux chemins) ;
- jobs workers hors adapter natif.

## Appels legacy encore présents (migration progressive B1+)

| Fichier | Usage | Action cible |
|---------|-------|--------------|
| `DocumentProcessor.php` | IA complexe auto | Migrer vers UnifiedClassifier |
| `DocumentsApiController.php` | Re-classify API | Migrer vers IngestClassificationService |
| `DocumentsController.php` | Action manuelle | Idem |
| `ClassificationService.php` | Lazy init | Idem |
| `AiExtractProcessor.php` | Workflow node | Idem |

Aucune suppression tant que les tests ingest/classifiers restent verts.

## Références

- Oracle : `docs/ORACLES-KDOCS-PRODUCT.md` § ingest workers-only
- Spec : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`
- Tests : `tests/Unit/Services/Classifiers/UnifiedClassifierTest.php`
