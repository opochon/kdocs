# K-Docs - Refonte Complète Indexation & Queues

## 📋 Résumé

Refonte complète du système d'indexation pour une navigation fluide (< 1 seconde) et une indexation intelligente en arrière-plan sans impact sur l'affichage.

## 🎯 Objectifs

- **Navigation instantanée** : Chargement < 1 seconde
- **Indexation en arrière-plan** : Pas de blocage de l'affichage
- **Pas de ré-indexation inutile** : Comparaison rapide avant checksum
- **Gestion intelligente des ressources** : Queues contrôlées, pauses configurables

## 🔄 Nouvelle Architecture

### Principe : Comparaison rapide AVANT toute action

```
FICHIER SUR DISQUE              FICHIER .index (version 2)
==================              ===========================
- mtime (date modif)    <-->    - files: {
- taille                           "fichier.pdf": {
- nom                                "mtime": 1234567890,
                                     "size": 12345,
                                     "checksum": "abc...",
                                     "db_id": 123
                                   }
                                 }
                                - last_scan: timestamp
                                - file_count: 10
                                - db_count: 10

SI mtime ET size identiques → Fichier inchangé, SKIP
SI mtime OU size différent → Recalculer checksum, vérifier DB
```

### Nouvelle structure du fichier `.index`

```json
{
  "version": 2,
  "last_scan": 1705847123,
  "file_count": 15,
  "db_count": 15,
  "files": {
    "document1.pdf": {
      "mtime": 1705847000,
      "size": 123456,
      "checksum": "abc123...",
      "db_id": 42,
      "indexed_at": 1705847100
    },
    "document2.pdf": {
      "mtime": 1705846000,
      "size": 78901,
      "checksum": "def456...",
      "db_id": 43,
      "indexed_at": 1705847100
    }
  }
}
```

## 📁 Fichiers Créés/Modifiés

### Nouveaux fichiers

1. **`app/Services/IndexingService.php`**
   - Service centralisé pour l'indexation
   - Gestion des queues avec contrôle de concurrence
   - Lecture/écriture des fichiers `.index` (version 2)
   - Comparaison rapide (mtime + size avant checksum)
   - Configuration depuis `config.php` + DB

2. **`app/workers/smart_indexer.php`**
   - Worker intelligent remplaçant `folder_crawler.php`
   - Comparaison rapide avant traitement
   - Pauses configurables
   - Gestion propre des ressources

3. **`database/migrations/add_indexing_settings.php`**
   - Migration pour ajouter les paramètres d'indexation en DB

4. **`docs/REFONTE_INDEXATION.md`** (ce fichier)
   - Documentation complète de la refonte

### Fichiers modifiés

1. **`config/config.php`**
   - Ajout de la section `indexing` avec tous les paramètres

2. **`app/Services/FolderIndexService.php`**
   - Compatibilité avec version 1 et version 2 du `.index`
   - Détection automatique de la version

3. **`app/Controllers/Api/FoldersApiController.php`**
   - Utilisation de `IndexingService` pour les queues
   - Contrôle du nombre de queues simultanées
   - Gestion des priorités (high/normal)

4. **`templates/admin/settings.php`**
   - Ajout de la section "Paramètres d'indexation"
   - Interface pour configurer tous les paramètres

5. **`app/Controllers/SettingsController.php`**
   - Sauvegarde des paramètres d'indexation

## ⚙️ Configuration

### Paramètres disponibles

| Paramètre | Défaut | Description |
|-----------|--------|-------------|
| `max_concurrent_queues` | 2 | Nombre max de workers simultanés |
| `process_priority` | 10 | Priorité processus (0-19, Linux seulement) |
| `memory_limit` | 128 | Mémoire max par worker (MB) |
| `delay_between_files` | 50 | Pause entre fichiers (ms) |
| `delay_between_folders` | 100 | Pause entre dossiers (ms) |
| `batch_size` | 20 | Fichiers par batch |
| `batch_pause` | 500 | Pause après batch (ms) |
| `queue_timeout` | 300 | Timeout queue (secondes) |
| `progress_update_interval` | 5 | Intervalle mise à jour progression (secondes) |
| `turbo_mode` | false | Mode turbo (ignore toutes les pauses) |

### Configuration dans `config.php`

```php
'indexing' => [
    'max_concurrent_queues' => 2,
    'process_priority' => 10,
    'memory_limit' => 128,
    'delay_between_files' => 50,
    'delay_between_folders' => 100,
    'batch_size' => 20,
    'batch_pause' => 500,
    'queue_timeout' => 300,
    'progress_update_interval' => 5,
    'turbo_mode' => false,
],
```

### Configuration dans la DB (prioritaire)

Les paramètres peuvent être modifiés via l'interface admin (`/admin/settings`) et sont stockés dans la table `settings` avec les clés `indexing_*`.

## 🚀 Utilisation

### Migration

Exécuter la migration pour créer les paramètres en DB :

```bash
php database/migrations/add_indexing_settings.php
```

### Worker

Le worker `smart_indexer.php` remplace `folder_crawler.php`. Il peut être appelé via :

- **Cron/Tâche planifiée** : Exécuter toutes les X minutes
- **API** : Déclenchement automatique lors de l'ajout d'une queue
- **CLI** : `php app/workers/smart_indexer.php`

### API

#### Déclencher une indexation

```javascript
POST /api/folders/crawl
{
  "path": "2024/tribunal",
  "priority": "normal" // ou "high"
}
```

Réponses possibles :
- `queued` : Queue ajoutée avec succès
- `rejected` : Trop de queues actives (voir `active_queues` et `max_queues`)
- `queued` (déjà) : Une queue existe déjà pour ce chemin

## 🔍 Optimisations

### 1. Comparaison rapide

**Avant** : Calcul du checksum MD5 pour chaque fichier (lecture complète)
**Après** : Comparaison `mtime` + `size` d'abord (instantané)

**Gain** : 100-1000x plus rapide pour les fichiers inchangés

### 2. Pas de ré-indexation inutile

Les fichiers avec `mtime` et `size` identiques sont automatiquement skippés sans calcul de checksum ni requête SQL.

### 3. Queues contrôlées

- Limite du nombre de queues simultanées
- Détection des queues en double
- Nettoyage automatique des queues expirées
- Priorités (high/normal)

### 4. Pauses configurables

- Pause entre fichiers : évite la saturation CPU
- Pause après batch : permet au serveur de respirer
- Mode turbo : désactive toutes les pauses (charge max)

### 5. Cache en mémoire

Les fichiers `.index` sont mis en cache en mémoire pour éviter les lectures répétées.

## 📊 Résultats Attendus

### Performance

- **Chargement initial** : < 1 seconde (lecture directe du `.index`)
- **Clic sur dossier** : < 200ms (cache + lecture `.index`)
- **Pas de requête SQL** pour les comptages (données depuis `.index`)
- **Pas de `readDirectory()`** redondant

### Indexation

- **Fichiers inchangés** : Skippés instantanément (comparaison mtime/size)
- **Fichiers modifiés** : Traitement avec checksum uniquement si nécessaire
- **Nouveaux fichiers** : Création en DB avec pauses configurables

## 🧪 Tests de Validation

### Test 1 : Pas de ré-indexation des fichiers inchangés
```
1. Indexer un dossier avec 100 fichiers
2. Relancer l'indexation du même dossier
3. Vérifier les logs : devrait afficher "skipped: 100"
4. Temps < 2 secondes (comparaison mtime/size seulement)
```

### Test 2 : Limite des queues
```
1. Configurer max_concurrent_queues = 2
2. Déclencher 5 indexations simultanées
3. Vérifier que seules 2 sont actives
4. Les autres sont rejetées ou en attente
```

### Test 3 : Navigation fluide pendant indexation
```
1. Lancer une indexation sur un gros dossier (1000+ fichiers)
2. Naviguer dans l'interface
3. Temps de réponse < 1 seconde partout
```

### Test 4 : Pauses configurables
```
1. Configurer delay_between_files = 100
2. Indexer 50 fichiers
3. Temps total ≈ 50 × 100ms = 5 secondes (+ traitement)
4. CPU moyen < 50%
```

## 🔄 Migration depuis l'ancien système

### Compatibilité

Le système est **100% compatible** avec l'ancien format `.index` (version 1). Les anciens fichiers `.index` continueront de fonctionner et seront progressivement migrés vers la version 2 lors des prochaines indexations.

### Ancien worker

L'ancien `folder_crawler.php` peut continuer à fonctionner en parallèle pendant la transition, mais il est recommandé de le remplacer par `smart_indexer.php` dans les tâches planifiées.

## 📝 Notes Importantes

### Règle absolue

**L'affichage a TOUJOURS la priorité sur l'indexation.**
Si l'utilisateur navigue, l'indexation doit se mettre en pause ou ralentir.

### Fichiers cachés

Les fichiers `.index` et `.indexing` sont automatiquement ignorés par `LocalStorage` et n'apparaissent pas dans l'arborescence.

### Windows vs Linux

- **Priorité processus** : Fonctionne uniquement sur Linux/Mac (via `proc_nice()`)
- **Exécution en arrière-plan** : Sur Windows, utiliser les tâches planifiées au lieu de `exec()`

## 🐛 Dépannage

### Queues bloquées

Si des queues restent bloquées, elles seront automatiquement nettoyées après le `queue_timeout` (par défaut 300 secondes).

### Performance lente

1. Vérifier `max_concurrent_queues` : réduire si trop élevé
2. Augmenter `delay_between_files` et `batch_pause`
3. Désactiver `turbo_mode` si activé

### Fichiers non indexés

1. Vérifier les logs du worker : `error_log` ou fichier de log PHP
2. Vérifier les permissions sur les fichiers `.index` et `.indexing`
3. Vérifier que le worker est bien exécuté (cron/tâche planifiée)

## 📚 Références

- `docs/OPTIMISATIONS_ARBORESCENCE.md` : Optimisations précédentes de l'arborescence
- `app/Services/IndexingService.php` : Service principal d'indexation
- `app/workers/smart_indexer.php` : Worker intelligent
