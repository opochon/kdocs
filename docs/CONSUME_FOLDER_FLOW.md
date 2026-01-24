# Consume Folder - Flux de Traitement

## 📋 Flux Complet

### 1. Dépôt des fichiers
- Fichiers déposés dans `storage/consume/`
- Les fichiers restent dans ce dossier jusqu'à validation

### 2. Scan (automatique ou manuel)
**Déclenchement automatique** :
- Au chargement de `/admin/consume` si :
  - Des fichiers sont présents dans `consume/`
  - Aucun document n'est déjà en attente (`pending`)
  
**Déclenchement manuel** :
- Bouton "Scanner" dans l'interface `/admin/consume`
- Route POST `/admin/consume/scan`
- Route API POST `/api/consume/scan` (pour cron)

### 3. Import des fichiers
Pour chaque fichier dans `consume/` :

1. **Vérification doublon** : Checksum MD5
   - Si déjà validé → déplacé vers `processed/`
   - Si déjà importé mais non validé → supprimé et réimporté

2. **Utilisation directe du fichier** :
   - Le fichier dans `consume/` est utilisé directement (pas de copie vers `toclassify/`)
   - **Le fichier reste dans `consume/`** jusqu'à validation

3. **Création document en DB** :
   - Status = `pending`
   - `file_path` = chemin direct vers le fichier dans `consume/`
   - `original_filename` = nom original

4. **Traitement automatique** :
   - OCR (extraction texte)
   - Classification (rules/ai/auto selon config)
   - Si PDF multi-pages → séparation IA (si activé)
   - Génération thumbnail

### 4. Validation utilisateur
- Page `/admin/consume` affiche les documents `pending`
- Utilisateur corrige/valide les métadonnées
- Clic sur "Valider" → `validateDocument()`

### 5. Après validation
- Document status → `validated`
- Fichier déplacé depuis `toclassify/` vers le chemin de stockage final
- **Fichier original dans `consume/` déplacé vers `processed/`**

## 🔍 Points Importants

### Fichiers dans `consume/`
- **Normal** : Les fichiers restent dans `consume/` jusqu'à validation
- Ils sont utilisés **directement** pour traitement (OCR, classification, etc.)
- Après validation, le fichier est déplacé directement vers son chemin final dans `documents/`

### Fichiers séparés (PDF multi-pages)
- Les fichiers créés par séparation PDF sont placés dans `documents/pending/`
- Ils sont également déplacés vers leur chemin final après validation

### Scan automatique
- Se déclenche uniquement si aucun document n'est déjà `pending`
- Évite les scans répétés inutiles
- Peut être désactivé en retirant le code dans `ConsumeController::index()`

## 🐛 Dépannage

### Les fichiers ne sont pas importés
1. Vérifier que le scan a été déclenché (bouton "Scanner" ou automatique)
2. Vérifier les logs PHP pour erreurs
3. Vérifier les permissions sur `storage/consume/` et `storage/documents/`
4. Vérifier que le lock n'est pas bloqué (`storage/.consume_scan.lock`)

### Les fichiers restent dans `consume/`
- **C'est normal** jusqu'à validation
- Après validation, ils sont déplacés directement vers leur chemin final dans `documents/`

### Note sur l'architecture
- Plus besoin du dossier `toclassify/` : les fichiers sont utilisés directement depuis `consume/`
- Les fichiers séparés (PDF multi-pages) sont temporairement dans `documents/pending/` jusqu'à validation
