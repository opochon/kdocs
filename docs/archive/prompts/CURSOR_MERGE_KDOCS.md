# Fusion K-Docs + K-Docs2

## 🎯 Objectif

Fusionner les fonctionnalités avancées de kdocs2 dans kdocs, en priorisant :
1. **Repositories** - Pattern Repository pour abstraction de données
2. **Search Builder** - Construction fluide de requêtes de recherche
3. **NL Query** - Requêtes en langage naturel avec IA

## 📋 Fonctionnalités à Fusionner

### 1. Repositories (Priorité 1)

**Fichiers à créer** :
- `app/Repositories/DocumentRepository.php`
- `app/Repositories/TagRepository.php`
- `app/Repositories/CorrespondentRepository.php`
- `app/Repositories/DocumentTypeRepository.php`
- `app/Repositories/SavedViewRepository.php`
- `app/Repositories/UserRepository.php`
- `app/Repositories/WorkflowRepository.php`

**Avantages** :
- Abstraction de l'accès aux données
- Code plus testable
- Réutilisabilité
- Séparation des responsabilités

### 2. Search Builder (Priorité 2)

**Fichiers à créer** :
- `app/Search/SearchQuery.php` - Objet de requête
- `app/Search/SearchQueryBuilder.php` - Builder fluide
- `app/Search/SearchResult.php` - Résultat avec facets et aggregations

**Fonctionnalités** :
- Construction fluide de requêtes
- Support facets (correspondents, types, tags, années)
- Support aggregations (totaux, moyennes)
- Pagination intégrée
- Tri personnalisable

### 3. NL Query (Priorité 3)

**Fichiers à créer** :
- `app/Services/NaturalLanguageQueryService.php` - Service de conversion NL → SearchQuery

**Fonctionnalités** :
- Conversion questions en français → filtres de recherche
- Utilise Claude API pour comprendre l'intention
- Génération de résumés de résultats
- Fallback sur recherche simple si IA indisponible

## 🔄 Intégration

### Étapes

1. **Créer les Repositories**
   - Adapter les namespaces (`App\Repositories` → `KDocs\Repositories`)
   - Utiliser `Database::getInstance()` au lieu de PDO injecté
   - Adapter les modèles existants

2. **Créer le Search Builder**
   - Adapter les namespaces
   - Intégrer avec `AISearchService` existant
   - Utiliser dans `DocumentsController` et `SearchController`

3. **Créer NL Query Service**
   - Utiliser `ClaudeService` existant
   - Intégrer avec `SearchQueryBuilder`
   - Exposer via API et interface

### Migration Progressive

- Les Repositories peuvent coexister avec les Models existants
- Migration progressive des contrôleurs vers les Repositories
- Le Search Builder remplace progressivement les requêtes SQL directes
- NL Query s'intègre dans le Chat IA existant

## 📁 Structure Cible

```
app/
├── Repositories/          # NOUVEAU
│   ├── DocumentRepository.php
│   ├── TagRepository.php
│   ├── CorrespondentRepository.php
│   └── ...
├── Search/                # NOUVEAU
│   ├── SearchQuery.php
│   ├── SearchQueryBuilder.php
│   └── SearchResult.php
└── Services/
    ├── NaturalLanguageQueryService.php  # NOUVEAU
    └── ...
```

## 🧪 Tests

1. Tester chaque Repository individuellement
2. Tester Search Builder avec différents filtres
3. Tester NL Query avec diverses questions
4. Vérifier compatibilité avec code existant
