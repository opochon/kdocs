# Intégration KDrive d'Infomaniak

## 🎯 Vue d'ensemble

K-Docs supporte maintenant **KDrive d'Infomaniak** comme source de documents alternative au filesystem local. L'intégration utilise **WebDAV** pour accéder aux fichiers stockés dans KDrive.

## 📋 Configuration

### 1. Obtenir les informations KDrive

1. **Drive ID** : 
   - Connectez-vous à votre compte KDrive
   - L'ID se trouve dans l'URL : `https://kdrive.infomaniak.com/app/drive/123456/`
   - Le Drive ID est `123456` dans cet exemple

2. **Email Infomaniak** : Votre adresse email du compte Infomaniak

3. **Mot de passe d'application** :
   - Si vous avez activé l'authentification à deux facteurs (2FA), créez un mot de passe d'application
   - Allez dans les paramètres Infomaniak → Sécurité → Mots de passe d'application
   - Créez un nouveau mot de passe d'application pour K-Docs

### 2. Configurer dans K-Docs

1. Allez dans **Paramètres** → **Stockage**
2. Sélectionnez **Type de stockage** : `KDrive (Infomaniak)`
3. Remplissez les champs :
   - **Drive ID** : Votre ID de Drive (ex: `123456`)
   - **Email Infomaniak** : Votre email
   - **Mot de passe d'application** : Le mot de passe d'application créé
   - **Chemin de base dans KDrive** (optionnel) : Dossier spécifique à utiliser (ex: `Documents/K-Docs`)

## 🔧 Architecture

### Classes créées

- **`StorageInterface`** : Interface commune pour tous les types de stockage
- **`LocalStorage`** : Implémentation pour filesystem local
- **`KDriveStorage`** : Implémentation pour KDrive via WebDAV
- **`StorageFactory`** : Factory pour créer l'instance appropriée selon la config

### Services adaptés

- **`FilesystemReader`** : Utilise maintenant `StorageInterface` (supporte local et KDrive)
- **`ConsumeFolderService`** : Peut scanner un dossier KDrive au lieu d'un dossier local
- **`DocumentProcessor`** : Télécharge automatiquement depuis KDrive si nécessaire

## 🚀 Fonctionnalités

### Lecture de documents

- **Liste des dossiers** : Navigation dans l'arborescence KDrive
- **Liste des fichiers** : Affichage des documents depuis KDrive
- **Métadonnées** : Récupération de la taille, date de modification, type MIME

### Traitement automatique

- **Consume Folder** : Peut surveiller un dossier spécifique dans KDrive
- **Téléchargement temporaire** : Les fichiers sont téléchargés localement pour traitement (OCR, thumbnails)
- **Suppression après traitement** : Les fichiers temporaires sont supprimés après traitement

### Compatibilité

- **Transparent** : Le reste de l'application fonctionne de la même manière, que le stockage soit local ou KDrive
- **Basculement facile** : Changez simplement le type de stockage dans les paramètres

## ⚙️ Détails techniques

### WebDAV

KDrive utilise WebDAV pour l'accès aux fichiers. L'URL WebDAV est construite comme suit :
```
https://{DriveID}.connect.kdrive.infomaniak.com
```

### Méthodes WebDAV utilisées

- **PROPFIND** : Liste le contenu d'un dossier
- **HEAD** : Récupère les métadonnées d'un fichier
- **GET** : Télécharge un fichier

### Gestion des erreurs

- Timeout de 30 secondes pour les requêtes PROPFIND/HEAD
- Timeout de 5 minutes pour les téléchargements de fichiers
- Gestion des erreurs réseau avec retry possible
- Logging des erreurs dans les logs PHP

## 📝 Notes importantes

1. **Performance** : KDrive peut être plus lent que le stockage local (dépend de la connexion réseau)
2. **Cache** : Les fichiers téléchargés sont mis en cache temporairement dans `storage/temp/kdrive_cache`
3. **Quotas** : Respectez les quotas de votre compte KDrive
4. **Sécurité** : Les identifiants sont stockés dans la base de données (chiffrés si possible)

## 🔍 Dépannage

### Erreur "Configuration KDrive incomplète"
- Vérifiez que tous les champs sont remplis dans les paramètres
- Vérifiez que le Drive ID est correct

### Erreur "Impossible de lire le dossier KDrive"
- Vérifiez vos identifiants (email et mot de passe d'application)
- Vérifiez que WebDAV est activé sur votre compte KDrive
- Vérifiez votre connexion réseau

### Fichiers non détectés
- Vérifiez que le chemin de base dans KDrive est correct
- Vérifiez que les extensions de fichiers sont autorisées dans les paramètres

## 🎯 Prochaines améliorations possibles

- Support de la synchronisation bidirectionnelle
- Cache intelligent des métadonnées
- Support d'autres services cloud (Nextcloud, Dropbox, etc.)
- Upload direct vers KDrive depuis l'interface
