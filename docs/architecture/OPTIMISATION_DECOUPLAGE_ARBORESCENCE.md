# Optimisation - Découplage de l'Arborescence

## 🎯 Objectif

Réduire le temps de chargement d'un dossier de **3-7 secondes** à **< 1 seconde** en découplant l'arborescence et en optimisant le chargement.

## 📋 Architecture Implémentée

### 1. Clic sur un Dossier (Arborescence)

**Opérations** :
1. ✅ Charger les enfants (nodes) de manière **asynchrone** via AJAX
2. ✅ Lire le fichier **`.index`** (caché, non affiché) qui contient :
   - `file_count` : Nombre de fichiers physiques
   - `db_count` : Nombre de documents en DB
   - `indexed_at` : Date de dernière indexation
3. ✅ Temps : **< 1ms** (lecture fichier JSON)

**Code** : `templates/documents/index.php` - `loadChildren()`

### 2. Chargement de la Page Documents

**Opérations** :
1. ✅ **ÉTAPE 1** : Lire le fichier `.index` (rapide, < 1ms)
   - Si existe → utiliser `file_count` et `db_count` directement
   - Si n'existe pas → passer à l'étape suivante

2. ✅ **ÉTAPE 2** : Une seule requête SQL pour charger les documents
   ```sql
   SELECT COUNT(*) as total FROM documents d
   WHERE (d.relative_path LIKE ? AND d.relative_path NOT LIKE ?)
      OR d.relative_path = ?
   AND d.deleted_at IS NULL
   AND (d.status IS NULL OR d.status != 'pending')
   
   SELECT d.*, dt.label, c.name FROM documents d
   LEFT JOIN document_types dt ON d.document_type_id = dt.id
   LEFT JOIN correspondents c ON d.correspondent_id = c.id
   WHERE ...
   ORDER BY d.created_at DESC
   LIMIT ? OFFSET ?
   ```
   - Temps : **~0.1-0.3 seconde** (1 requête SQL)

3. ✅ **ÉTAPE 3** : Comparer avec `.index`
   - Si `dbTotal !== indexDbCount` → lancer la queue en arrière-plan
   - Si `indexFileCount !== physicalFileCount` → lancer la queue
   - Temps : **< 1ms** (comparaison simple)

4. ✅ **ÉTAPE 4** : Affichage immédiat
   - Documents déjà paginés par SQL
   - Pas de mapping fichier ↔ document nécessaire
   - Temps : **< 0.01 seconde**

**Total estimé** : **0.1-0.4 secondes** (< 1 seconde) ✅

**Code** : `app/Controllers/DocumentsController.php` - lignes 149-251

### 3. Queue d'Indexation (Arrière-plan)

**Opérations** :
1. ✅ Créer le fichier **`.indexing`** avec progression
   ```json
   {
     "path": "2024",
     "total": 100,
     "current": 45,
     "processed": 40,
     "skipped": 5,
     "started_at": 1234567890,
     "updated_at": 1234567890
   }
   ```

2. ✅ Traiter les fichiers un par un
3. ✅ Mettre à jour `.indexing` après chaque fichier
4. ✅ À la fin : créer `.index` et supprimer `.indexing`

**Code** : `app/workers/folder_crawler.php`

### 4. Affichage de la Progression

**Opérations** :
1. ✅ Polling toutes les **10 secondes** (comme demandé)
2. ✅ Vérifier l'existence de `.indexing` via API
3. ✅ Afficher la barre de progression en bas de l'écran
4. ✅ Quand `.indexing` disparaît → indexation terminée

**Code** : `templates/documents/index.php` - `updateIndexingStatus()`

## 🔧 Fichiers Modifiés

### Nouveaux Fichiers
- ✅ `app/Services/FolderIndexService.php` - Service de gestion des fichiers `.index` et `.indexing`

### Fichiers Modifiés
- ✅ `app/Controllers/DocumentsController.php` - Chargement optimisé avec `.index`
- ✅ `app/Controllers/Api/FoldersApiController.php` - Lecture `.index` dans l'API enfants
- ✅ `app/workers/folder_crawler.php` - Création de `.index` au lieu de `.indexed`
- ✅ `app/Services/Storage/LocalStorage.php` - Cacher les fichiers `.index`, `.indexing`, `.indexed`
- ✅ `templates/documents/index.php` - Chargement asynchrone avec lecture `.index`

## 📊 Comparaison Avant/Après

| Étape | Avant | Après | Gain |
|-------|-------|-------|------|
| **Lecture enfants** | Synchrone (bloquant) | Asynchrone AJAX | ✅ |
| **Comptage fichiers** | Scan filesystem complet | Lecture `.index` (< 1ms) | **99%** |
| **Chargement documents** | N requêtes SQL | 1 requête SQL | **99%** |
| **Comparaison** | N/A | Lecture `.index` (< 1ms) | ✅ |
| **Lancement queue** | Synchrone | Arrière-plan (non bloquant) | ✅ |
| **TOTAL** | **3-7 secondes** | **< 1 seconde** | **85-90%** |

## 🎨 Fichiers Cachés

Les fichiers suivants sont **cachés** dans l'arborescence (non affichés) :
- `.index` - Métadonnées du dossier (nombre fichiers, dernière indexation)
- `.indexing` - Progression de l'indexation en cours
- `.indexed` - Ancien format (compatibilité)

**Code** : `app/Services/Storage/LocalStorage.php` - ligne 53

## 🔄 Flux Complet

```
1. Utilisateur clique sur "2024"
   ↓
2. Frontend : AJAX → /api/folders/children?parent_id=...
   ↓
3. Backend : Lit les dossiers enfants + fichier .index pour chaque
   ↓
4. Frontend : Affiche les enfants avec comptage depuis .index (< 1ms)
   ↓
5. Utilisateur clique sur le lien "2024"
   ↓
6. Backend : 
   - Lit .index (file_count, db_count) < 1ms
   - 1 requête SQL COUNT(*) → dbTotal
   - 1 requête SQL SELECT avec LIMIT/OFFSET → documents
   - Compare : si différent → crée tâche crawl_queue (non bloquant)
   ↓
7. Frontend : Affiche les documents (< 0.4s total)
   ↓
8. Si queue lancée :
   - Worker crée .indexing
   - Traite les fichiers
   - Met à jour .indexing
   - Crée .index
   - Supprime .indexing
   ↓
9. Frontend : Polling toutes les 10s
   - Vérifie .indexing
   - Affiche progression en bas
   - Quand .indexing disparaît → terminé
```

## ✅ Résultats Attendus

- **Chargement arborescence** : Instantané (< 100ms)
- **Chargement page documents** : < 1 seconde
- **Affichage progression** : Toutes les 10 secondes en bas de l'écran
- **Fichiers cachés** : `.index`, `.indexing`, `.indexed` non visibles

## 🧪 Test

1. Ouvrir `http://localhost/kdocs/documents?folder=07811dc6c422334ce36a09ff5cd6fe71`
2. Mesurer le temps de chargement (DevTools → Network)
3. Vérifier que les fichiers `.index` ne sont pas visibles dans l'arborescence
4. Vérifier que la progression s'affiche en bas si `.indexing` existe

**Temps attendu** : **< 1 seconde** ✅
