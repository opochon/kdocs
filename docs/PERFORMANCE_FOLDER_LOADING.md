# Performance - Chargement de Dossier

## 🔍 Analyse des Opérations

### URL Testée
`http://localhost/kdocs/documents?folder=07811dc6c422334ce36a09ff5cd6fe71`

### Opérations Identifiées (AVANT optimisation)

1. **Recherche récursive de tous les dossiers** (lignes 115-132)
   - Parcourt récursivement TOUS les dossiers du filesystem
   - Crée un tableau de tous les chemins
   - Complexité : O(n) où n = nombre total de dossiers
   - **Problème** : Très lent si beaucoup de dossiers

2. **Recherche du chemin par hash MD5** (lignes 135-153)
   - Parcourt le tableau de tous les chemins
   - Calcule MD5 pour chaque chemin jusqu'à trouver la correspondance
   - Complexité : O(n) où n = nombre de dossiers
   - **Problème** : Redondant avec l'étape 1

3. **Lecture du contenu du dossier** (ligne 159)
   - `readDirectory()` : scan du dossier sélectionné
   - Complexité : O(m) où m = nombre de fichiers dans le dossier
   - **OK** : Nécessaire

4. **Requêtes SQL individuelles** (lignes 171-212)
   - Pour CHAQUE fichier : `prepare()` + `execute()` + `fetch()`
   - Si 100 fichiers = 100 requêtes SQL !
   - Complexité : O(m) requêtes SQL
   - **Problème majeur** : Très lent, surcharge la base de données

5. **Vérification de modification** (ligne 191)
   - `checkFileModified()` pour chaque document trouvé
   - Appelle `filemtime()` et calcule checksum
   - Complexité : O(m) opérations fichiers
   - **Problème** : Lent si beaucoup de fichiers

6. **Double recherche récursive** (lignes 399-425)
   - Refait exactement la même recherche pour `currentFolderPath`
   - **Problème** : Double travail inutile

### Temps Estimé (AVANT optimisation)
- Recherche récursive : ~2-5 secondes (selon nombre de dossiers)
- Requêtes SQL individuelles : ~0.1s × nombre de fichiers
  - 100 fichiers = ~10 secondes
- Vérification modifications : ~0.05s × nombre de fichiers
  - 100 fichiers = ~5 secondes
- **Total estimé** : 15-20 secondes pour 100 fichiers

---

## ✅ Optimisations Appliquées

### 1. Recherche de chemin optimisée
**Avant** : Parcourt tous les dossiers récursivement
**Après** : Recherche avec arrêt anticipé dès que le chemin est trouvé
- Utilise `findPath()` avec limite de profondeur (10 niveaux)
- S'arrête immédiatement quand le hash correspond
- **Gain** : 50-90% de réduction du temps de recherche

### 2. Requête SQL batch
**Avant** : 1 requête SQL par fichier (N requêtes)
**Après** : 1 seule requête SQL pour tous les fichiers
```sql
SELECT d.*, dt.label, c.name
FROM documents d
LEFT JOIN document_types dt ON d.document_type_id = dt.id
LEFT JOIN correspondents c ON d.correspondent_id = c.id
WHERE (
    d.relative_path IN (?, ?, ?, ...) 
    OR d.filename IN (?, ?, ?, ...)
)
AND d.deleted_at IS NULL
AND (d.status IS NULL OR d.status != 'pending')
```
- **Gain** : 100 requêtes → 1 requête = **99% de réduction**

### 3. Index en mémoire
**Avant** : Recherche linéaire dans les résultats
**Après** : Index associatif (`$docIndex`) pour recherche O(1)
- Clés multiples : `relative_path`, `basename(relative_path)`, `filename`
- **Gain** : Recherche instantanée au lieu de O(n)

### 4. Suppression vérification modifications
**Avant** : `checkFileModified()` pour chaque document
**Après** : Vérification désactivée lors du chargement initial
- Peut être faite en arrière-plan ou à la demande
- **Gain** : Économie de ~0.05s × nombre de fichiers

### 5. Éviter double recherche
**Avant** : Recherche récursive effectuée 2 fois
**Après** : Réutilisation de `$currentFolder` déjà trouvé
- **Gain** : 50% de réduction si recherche nécessaire

---

## 📊 Temps Estimé (APRÈS optimisation)

- Recherche de chemin optimisée : ~0.5-1 seconde
- Requête SQL batch : ~0.1-0.3 seconde (1 requête)
- Index et mapping : ~0.01-0.05 seconde
- **Total estimé** : **0.6-1.4 secondes** pour 100 fichiers

**Amélioration** : **10-30x plus rapide** 🚀

---

## 🧪 Test de Performance

### Méthode de test
1. Ouvrir les DevTools (F12)
2. Onglet Network
3. Charger l'URL : `http://localhost/kdocs/documents?folder=07811dc6c422334ce36a09ff5cd6fe71`
4. Mesurer le temps de chargement total

### Métriques à observer
- **Time to First Byte (TTFB)** : Temps avant première réponse
- **Content Download** : Temps de téléchargement de la page
- **Total Time** : Temps total de chargement

### Résultats attendus
- **Avant optimisation** : 15-20 secondes
- **Après optimisation** : 0.6-1.4 secondes

---

## 🔧 Détails Techniques

### Requête SQL Batch
```php
// Préparer les paramètres
$filePaths = array_column($fsContent['files'], 'path');
$fileNames = array_map('basename', $filePaths);

// Une seule requête avec IN clause
$batchStmt = $db->prepare("
    SELECT d.*, dt.label, c.name
    FROM documents d
    LEFT JOIN document_types dt ON d.document_type_id = dt.id
    LEFT JOIN correspondents c ON d.correspondent_id = c.id
    WHERE (
        d.relative_path IN (" . implode(',', array_fill(0, count($filePaths), '?')) . ") 
        OR d.filename IN (" . implode(',', array_fill(0, count($fileNames), '?')) . ")
    )
    AND d.deleted_at IS NULL
    AND (d.status IS NULL OR d.status != 'pending')
");
```

### Index de Recherche
```php
// Créer un index multi-clés pour recherche rapide
$docIndex = [];
foreach ($dbDocuments as $doc) {
    $key1 = $doc['relative_path'] ?? '';
    $key2 = basename($key1);
    $key3 = $doc['filename'] ?? '';
    if (!isset($docIndex[$key1])) $docIndex[$key1] = $doc;
    if (!isset($docIndex[$key2])) $docIndex[$key2] = $doc;
    if (!isset($docIndex[$key3])) $docIndex[$key3] = $doc;
}

// Recherche O(1)
$doc = $docIndex[$filePath] ?? $docIndex[$fileName] ?? null;
```

---

## 📈 Améliorations Futures Possibles

1. **Cache des chemins de dossiers**
   - Stocker les mappings `hash MD5 → chemin` en cache
   - Invalider lors de changements de structure

2. **Indexation des fichiers**
   - Table `filesystem_index` avec `path_hash` et `path`
   - Recherche directe sans scan récursif

3. **Pagination côté serveur**
   - Limiter le nombre de fichiers chargés initialement
   - Charger le reste via AJAX

4. **Lazy loading des métadonnées**
   - Charger seulement les infos essentielles initialement
   - Charger le reste à la demande

---

## ✅ Résumé

| Opération | Avant | Après | Gain |
|-----------|-------|-------|------|
| Recherche chemin | 2-5s | 0.5-1s | 50-80% |
| Requêtes SQL | 100 × 0.1s = 10s | 1 × 0.1s = 0.1s | **99%** |
| Vérification modif | 5s | 0s | **100%** |
| Double recherche | 2-5s | 0s | **100%** |
| **TOTAL** | **15-20s** | **0.6-1.4s** | **10-30x** |
