# Optimisations Arborescence - Résumé

## Problèmes identifiés et corrigés

### ✅ 1. Élimination de `readDirectory()` redondant
**Problème** : Dans `DocumentsController::index()`, on faisait `readDirectory()` pour compter les fichiers physiques même si le `.index` existait déjà.

**Solution** : Utiliser directement `$indexFileCount` du `.index` si disponible. Ne faire `readDirectory()` que si `.index` n'existe pas.

**Fichier** : `app/Controllers/DocumentsController.php` (lignes 210-237)

### ✅ 2. Suppression de `hasSubfolders()` coûteux
**Problème** : `hasSubfolders()` faisait `opendir()` et parcourait les fichiers pour chaque dossier, même si non nécessaire.

**Solution** : Assumer `has_children = true` par défaut. Le frontend découvrira au clic si vraiment vide.

**Fichiers** :
- `app/Controllers/Api/FoldersApiController.php` (ligne 68)
- `app/Controllers/DocumentsController.php` (ligne 419)

### ✅ 3. Élimination du code mort `$docIndex`
**Problème** : Création d'un index in-memory `$docIndex` jamais utilisé.

**Solution** : Supprimé complètement.

**Fichier** : `app/Controllers/DocumentsController.php` (lignes 239-248)

### ✅ 4. Duplication de `$currentFolder`
**Problème** : `$currentFolder` était défini deux fois (lignes 244 et 274).

**Solution** : Supprimé la première définition.

**Fichier** : `app/Controllers/DocumentsController.php`

### ✅ 5. Optimisation de `folderCache.clear()`
**Problème** : Invalidation de TOUT le cache quand une indexation se termine.

**Solution** : Invalider seulement les dossiers concernés par l'indexation.

**Fichier** : `templates/documents/index.php` (ligne 521)

### ✅ 6. Limitation de `triggerNeededSyncs()`
**Problème** : Déclenchait des syncs pour TOUS les dossiers qui ont besoin de sync, même non visibles.

**Solution** : Limiter à 5 dossiers max pour éviter surcharge.

**Fichier** : `templates/documents/index.php` (ligne 439-454)

### ✅ 7. Polling conditionnel
**Problème** : Le polling démarrait toujours, même sans indexation en cours.

**Solution** : Vérifier d'abord s'il y a des queues actives avant de démarrer le polling.

**Fichier** : `templates/documents/index.php` (lignes 508-526)

### ✅ 8. Utilisation du chemin déjà trouvé
**Problème** : Double recherche récursive `findPath()` pour trouver le même chemin.

**Solution** : Réutiliser `$folderPath` déjà trouvé dans la section précédente.

**Fichier** : `app/Controllers/DocumentsController.php` (lignes 441-452)

## Fonctions obsolètes à supprimer (optionnel)

- `FoldersApiController::hasSubfolders()` - Plus utilisée (ligne 105)
- `FoldersApiController::loadFoldersRecursive()` - Plus utilisée (ligne 153)
- `FoldersApiController::getFolderCounts()` - Plus utilisée (ligne 352) - L'API `/api/folders/counts` n'est plus appelée

## Résultat attendu

- **Chargement initial** : < 1 seconde (lecture directe du `.index`)
- **Clic sur dossier** : < 200ms (cache + lecture `.index`)
- **Pas de requête SQL** pour les comptages (données depuis `.index`)
- **Pas de `readDirectory()`** redondant
- **Polling intelligent** : seulement si nécessaire
- **Cache optimisé** : invalidation ciblée
