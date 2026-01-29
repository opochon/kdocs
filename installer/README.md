# K-Docs - Installation des dépendances

Ce dossier contient tous les scripts nécessaires pour installer les dépendances de K-Docs sur Windows.

## Prérequis système

- Windows 10/11 (64-bit)
- Droits administrateur
- 8 Go RAM minimum (16 Go recommandé pour Docker)
- 20 Go d'espace disque libre

## Installation rapide

1. **Clic droit** sur `install.bat` → **Exécuter en tant qu'administrateur**
2. Choisir **[2] Installer TOUT**
3. Redémarrer l'ordinateur après l'installation

## Composants installés

| Composant | Version | Taille | Usage |
|-----------|---------|--------|-------|
| Docker Desktop | Latest | ~500 MB | Conteneurs (OnlyOffice) |
| LibreOffice | 24.2.x | ~350 MB | Miniatures, conversion Office |
| Tesseract OCR | 5.3.x | ~50 MB | Reconnaissance de texte |
| Ghostscript | 10.x | ~100 MB | Traitement PDF |
| Poppler | 24.x | ~30 MB | Extraction texte PDF |

## Scripts disponibles

### Principal
- `install.bat` - Menu principal d'installation

### Scripts individuels (dans `scripts/`)
- `check-deps.bat` - Vérifie les dépendances installées
- `install-docker.bat` - Installe Docker Desktop
- `install-libreoffice.bat` - Installe LibreOffice
- `install-tesseract.bat` - Installe Tesseract OCR
- `install-pdf-tools.bat` - Installe Ghostscript et Poppler
- `setup-onlyoffice.bat` - Configure le conteneur OnlyOffice

## Après l'installation

### 1. Démarrer Docker Desktop
- Lancez Docker Desktop depuis le menu Démarrer
- Attendez que l'icône baleine 🐳 soit stable (pas animée)

### 2. Démarrer OnlyOffice
```batch
cd docker\onlyoffice
start.bat
```

### 3. Vérifier le fonctionnement
Accédez à : http://localhost/kdocs/diag_onlyoffice.php

## Configuration K-Docs

Les chemins sont auto-détectés dans `config/config.php`. Si nécessaire, ajustez :

```php
'tools' => [
    'ghostscript' => 'C:\\Program Files\\gs\\gs10.03.1\\bin\\gswin64c.exe',
    'libreoffice' => 'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'pdftotext' => 'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe',
    'pdftoppm' => 'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe',
],
'ocr' => [
    'tesseract_path' => 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
],
```

## Dépannage

### Docker ne démarre pas
1. Vérifiez que WSL2 est activé : `wsl --status`
2. Si non : `wsl --install` puis redémarrer
3. Activez la virtualisation dans le BIOS si nécessaire

### OnlyOffice ne répond pas
```batch
# Voir les logs
docker logs kdocs-onlyoffice

# Redémarrer le conteneur
docker restart kdocs-onlyoffice

# Recréer complètement
docker stop kdocs-onlyoffice
docker rm kdocs-onlyoffice
cd docker\onlyoffice
start.bat
```

### Tesseract ne reconnaît pas le français
Réinstallez Tesseract et cochez la langue "French" dans l'installateur.

### LibreOffice conversion échoue
Vérifiez que le chemin est correct et que LibreOffice n'est pas déjà ouvert.

## Désinstallation

Les composants peuvent être désinstallés via "Programmes et fonctionnalités" Windows :
- Docker Desktop
- LibreOffice
- Tesseract-OCR
- Ghostscript

Poppler : supprimer le dossier `C:\Program Files\poppler`

OnlyOffice :
```batch
docker stop kdocs-onlyoffice
docker rm kdocs-onlyoffice
docker rmi onlyoffice/documentserver
```

## Support Linux

Les équivalents Linux sont disponibles dans `docker/` et via les gestionnaires de paquets :

```bash
# Debian/Ubuntu
sudo apt install tesseract-ocr tesseract-ocr-fra libreoffice ghostscript poppler-utils

# Docker
docker compose -f docker/onlyoffice/docker-compose.yml up -d
```
