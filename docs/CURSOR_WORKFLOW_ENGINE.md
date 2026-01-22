# K-Docs - Workflow Engine - Spécifications Complètes

## 🎯 OBJECTIF

Implémenter un moteur d'exécution de workflows complet et robuste pour K-Docs, permettant l'automatisation du traitement des documents.

## 📋 ARCHITECTURE

### Composants Principaux

1. **WorkflowEngine** - Moteur principal d'exécution
2. **TriggerMatcher** - Évaluation des conditions de déclenchement
3. **ActionExecutor** - Exécution des actions
4. **WorkflowScheduler** - Gestion des workflows planifiés
5. **WorkflowLogger** - Journalisation complète

### Flux d'Exécution

```
Événement → TriggerMatcher → WorkflowEngine → ActionExecutor → Résultat
                ↓
         WorkflowScheduler (pour scheduled)
                ↓
         WorkflowLogger (toutes les étapes)
```

## 🔧 FONCTIONNALITÉS REQUISES

### 1. Déclencheurs (Triggers)

#### Consumption Started
- Se déclenche avant qu'un document soit consommé
- Filtres : sources, filter_path, filter_filename

#### Document Added
- Se déclenche après l'ajout d'un document
- Filtres : tags, correspondents, types, storage paths, match text

#### Document Updated
- Se déclenche lors de la modification d'un document
- Mêmes filtres que Document Added

#### Scheduled
- Se déclenche selon une planification
- Basé sur une date du document (created, added, modified, custom_field)
- Support récurrent et offset

### 2. Actions

#### Assignment (Type 1)
- Assigner titre, tags, type, correspondent, storage path, owner
- Assigner permissions (view/change users/groups)
- Assigner custom fields avec valeurs

#### Removal (Type 2)
- Retirer tags, correspondents, types, storage paths
- Retirer custom fields, owners, permissions
- Support "remove_all" pour chaque type

#### Email (Type 3)
- Envoyer un email avec placeholders
- Support pièce jointe (document PDF)

#### Webhook (Type 4)
- Appeler une URL externe
- Support GET params ou POST body
- Support JSON ou form-data
- Support headers custom
- Option inclure document

### 3. Placeholders

Support des placeholders dans les templates :
- `{correspondent}` - Nom du correspondant
- `{document_type}` - Type de document
- `{title}` - Titre du document
- `{created_year}`, `{created_month}`, `{created_day}` - Date de création
- `{added_year}`, `{added_month}`, `{added_day}` - Date d'ajout
- `{asn}` - Archive Serial Number
- `{owner}` - Propriétaire
- `{original_filename}` - Nom de fichier original

### 4. Matching Algorithms

- **any** - Au moins un mot correspond
- **all** - Tous les mots correspondent
- **exact** - Correspondance exacte
- **regex** - Expression régulière
- **fuzzy** - Correspondance approximative (70% similarité)

### 5. Gestion des Erreurs

- Logging complet de toutes les erreurs
- Continuation de l'exécution même en cas d'erreur d'une action
- Retry automatique pour les webhooks (optionnel)
- Notification des erreurs critiques

### 6. Performance

- Exécution asynchrone pour les workflows longs
- Queue pour les workflows planifiés
- Cache des workflows actifs
- Optimisation des requêtes SQL

## 📁 FICHIERS À CRÉER/MODIFIER

### 1. app/Services/WorkflowEngine.php (NOUVEAU)
Moteur principal d'exécution des workflows

### 2. app/Services/TriggerMatcher.php (NOUVEAU)
Évaluation des conditions de déclenchement

### 3. app/Services/ActionExecutor.php (NOUVEAU)
Exécution des actions sur les documents

### 4. app/Services/WorkflowScheduler.php (NOUVEAU)
Gestion des workflows planifiés

### 5. app/Services/WorkflowLogger.php (NOUVEAU)
Journalisation complète des exécutions

### 6. app/Services/WorkflowService.php (MODIFIER)
Intégrer le nouveau moteur

### 7. app/Workflow/ExecutionEngine.php (AMÉLIORER)
Intégrer avec le nouveau système

## 🔄 INTÉGRATION

Le WorkflowEngine doit être intégré dans :
- `DocumentProcessor` - Pour déclencher sur document_added
- `ConsumeFolderService` - Pour déclencher sur consumption_started
- `DocumentsController` - Pour déclencher sur document_updated
- `ScheduledTasksController` - Pour les workflows planifiés

## 🧪 TESTS

1. Test trigger "Document Added" avec filtre tag
2. Test action "Assignment" avec placeholders
3. Test action "Email" avec pièce jointe
4. Test action "Webhook" vers endpoint de test
5. Test workflow planifié (scheduled)
6. Test matching algorithms (any, all, exact, regex, fuzzy)
7. Test gestion erreurs et retry

## 📌 PRIORITÉS

1. **WorkflowEngine** - Moteur principal
2. **TriggerMatcher** - Évaluation des triggers
3. **ActionExecutor** - Exécution des actions
4. **WorkflowScheduler** - Planification
5. **WorkflowLogger** - Journalisation
6. **Intégration** - Dans les services existants
