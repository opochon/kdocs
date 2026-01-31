# K-Docs - Installation Windows

## Prérequis

- Windows 10/11 64-bit
- WAMP64 ou XAMPP avec PHP 8.1+ et MySQL/MariaDB
- Docker Desktop (optionnel, pour OnlyOffice et Qdrant)
- Connexion Internet (pour téléchargement des outils)
- Droits administrateur (pour installation des outils)

## Installation rapide

```powershell
# 1. Ouvrir PowerShell en tant qu'administrateur
# 2. Naviguer vers le dossier install
cd C:\wamp64\www\kdocs\install

# 3. Autoriser l'exécution des scripts
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser

# 4. Installation complète
.\kdocs-install.ps1
```

## Options d'installation

```powershell
# Installation complète (tous les composants)
.\kdocs-install.ps1

# Installer uniquement les outils (Tesseract, Ghostscript, Poppler, LibreOffice)
.\kdocs-install.ps1 -Components Tools

# Installer OnlyOffice et Qdrant uniquement
.\kdocs-install.ps1 -Components OnlyOffice,Qdrant

# Forcer la réinstallation
.\kdocs-install.ps1 -Force

# Sans téléchargement (utilise les fichiers existants)
.\kdocs-install.ps1 -SkipDownload

# Vérification uniquement
.\kdocs-install.ps1 -Components Verify
```

## Ce que l'installateur fait

1. **Vérifie les prérequis** (PHP, MySQL, Docker)
2. **Télécharge les outils** dans `downloads/`
3. **Installe les outils** dans `C:\Tools\` et `C:\Program Files\`
4. **Configure le PATH** système
5. **Crée la base de données** KDocs
6. **Déploie les conteneurs Docker** (OnlyOffice, Qdrant)
7. **Configure l'application**

## Composants installés

| Composant | Version | Usage | Requis |
|-----------|---------|-------|--------|
| Tesseract OCR | 5.4.0 | Extraction de texte (OCR) | Oui |
| Tesseract fra/deu | - | Packs langues | Oui |
| Ghostscript | 10.03.1 | Génération miniatures PDF | Oui |
| Poppler | 24.02.0 | pdftotext, pdftoppm | Oui |
| LibreOffice | 24.8.4 | Conversion Office, miniatures DOCX | Non |
| ImageMagick | 7.1.1 | Manipulation images | Non |
| OnlyOffice | latest | Prévisualisation Office en ligne | Non |
| Qdrant | latest | Recherche sémantique vectorielle | Non |
| Ollama | latest | Embeddings locaux | Non |

## Structure des fichiers

```
install/
├── README.md              # Cette documentation
├── install.ps1            # Script principal d'installation
├── scripts/
│   ├── download-tools.ps1 # Téléchargement des outils
│   ├── install-tools.ps1  # Installation des outils
│   ├── setup-database.ps1 # Configuration base de données
│   └── configure-app.ps1  # Configuration application
├── downloads/             # Fichiers téléchargés (auto)
├── tools/                 # Outils portables (optionnel)
└── config/
    └── tools.json         # Configuration des outils
```

## Installation manuelle

Si vous préférez installer manuellement :

### 1. Tesseract OCR

1. Télécharger depuis : https://github.com/UB-Mannheim/tesseract/wiki
2. Installer dans `C:\Program Files\Tesseract-OCR`
3. Télécharger `fra.traineddata` depuis https://github.com/tesseract-ocr/tessdata
4. Copier dans `C:\Program Files\Tesseract-OCR\tessdata\`

### 2. Ghostscript

1. Télécharger depuis : https://ghostscript.com/releases/gsdnld.html
2. Installer (version 64-bit)
3. Ajouter au PATH : `C:\Program Files\gs\gs10.03.1\bin`

### 3. Poppler (pdftotext)

1. Télécharger depuis : https://github.com/oschwartz10612/poppler-windows/releases
2. Extraire dans `C:\Tools\poppler`
3. Ajouter au PATH : `C:\Tools\poppler\Library\bin`

## Configuration KDocs

Après installation des outils, éditer `config/config.php` :

```php
'tools' => [
    'ghostscript' => 'C:\\Program Files\\gs\\gs10.03.1\\bin\\gswin64c.exe',
    'pdftotext' => 'C:\\Tools\\poppler\\Library\\bin\\pdftotext.exe',
    'pdftoppm' => 'C:\\Tools\\poppler\\Library\\bin\\pdftoppm.exe',
],
'ocr' => [
    'tesseract_path' => 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
],
```

## Vérification

Après installation, exécuter :

```powershell
.\scripts\verify-install.ps1
```

## Dépannage

### "tesseract n'est pas reconnu"
- Vérifier que Tesseract est dans le PATH
- Relancer le terminal après modification du PATH

### "pdftotext introuvable"
- Vérifier l'installation de Poppler
- Vérifier le chemin dans config.php

### Problèmes d'encodage OCR
- Vérifier que `fra.traineddata` est installé
- Le fichier doit être dans `tessdata/`

## Recherche Sémantique (Optionnel)

La recherche sémantique utilise des embeddings vectoriels pour trouver des documents similaires par sens, pas seulement par mots-clés.

### Composants requis

| Composant | Description | Port |
|-----------|-------------|------|
| Qdrant | Base de données vectorielle (stockage) | 6333, 6334 |
| Ollama | Génération d'embeddings (local, gratuit) | 11434 |

### Flux de données

```
Document → Ollama (embedding local) → Qdrant (stockage vecteur)
                                           ↓
                              Recherche sémantique locale
                                           ↓
                              Claude (enrichissement optionnel)
```

### 1. Installation Ollama (Embeddings)

```powershell
# Script automatique
.\scripts\install-ollama.ps1

# Ou manuellement:
# 1. Télécharger depuis https://ollama.ai/download
# 2. Installer et lancer Ollama
# 3. Télécharger le modèle d'embedding:
ollama pull nomic-embed-text
```

### 2. Installation Qdrant (Docker)

```powershell
# Méthode 1 : Script PowerShell
.\scripts\install-qdrant.ps1

# Méthode 2 : Batch file
.\install_qdrant.bat

# Méthode 3 : Commande Docker directe
docker run -d --name kdocs-qdrant --restart unless-stopped -p 6333:6333 -p 6334:6334 -v C:\wamp64\www\kdocs\storage\qdrant:/qdrant/storage qdrant/qdrant:latest
```

### 3. Configuration (config/config.php)

La configuration par défaut utilise Ollama (local, gratuit) :

```php
'embeddings' => [
    'enabled' => true,
    'provider' => 'ollama',
    'ollama_url' => 'http://localhost:11434',
    'ollama_model' => 'nomic-embed-text',
    'dimensions' => 768,
    'auto_sync' => true,
],
```

### Synchronisation des embeddings

```powershell
cd C:\wamp64\www\kdocs

# Vérifier le statut
php bin\kdocs embeddings:status

# Synchroniser tous les documents
php bin\kdocs embeddings:sync --all

# Tester la recherche
php bin\kdocs search:semantic "facture électricité"
```

### URLs Qdrant

- REST API : http://localhost:6333
- Dashboard : http://localhost:6333/dashboard
- Health : http://localhost:6333/health

### Commandes Docker Qdrant

```batch
docker start kdocs-qdrant    # Démarrer
docker stop kdocs-qdrant     # Arrêter
docker logs kdocs-qdrant     # Logs
docker rm -f kdocs-qdrant    # Supprimer
```

## Support

- Documentation : `/docs`
- Issues : Contacter l'administrateur
