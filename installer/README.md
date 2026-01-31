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
4. Lancer Docker Desktop
5. Relancer `install.bat` et choisir **[4] Services Docker**

## Architecture Docker

K-Docs utilise deux services Docker définis dans `docker-compose.yml` à la racine :

```yaml
services:
  onlyoffice:   # Prévisualisation/édition Office (port 8080)
  qdrant:       # Recherche sémantique vectorielle (port 6333)
```

### Commandes Docker utiles

```batch
# Démarrer les services
cd C:\wamp64\www\kdocs
docker-compose up -d

# Arrêter les services
docker-compose down

# Voir le statut
docker-compose ps

# Voir les logs
docker-compose logs -f

# Redémarrer un service
docker-compose restart onlyoffice
docker-compose restart qdrant
```

## Composants installés

| Composant | Version | Taille | Usage |
|-----------|---------|--------|-------|
| Docker Desktop | Latest | ~500 MB | Conteneurs |
| OnlyOffice | Latest | ~2.5 GB | Prévisualisation Office |
| Qdrant | Latest | ~100 MB | Recherche sémantique |
| LibreOffice | 24.2.x | ~350 MB | Miniatures, conversion |
| Tesseract OCR | 5.3.x | ~50 MB | Reconnaissance de texte |
| Ghostscript | 10.x | ~100 MB | Traitement PDF |
| Poppler | 24.x | ~30 MB | Extraction texte PDF |

## Services externes (optionnels)

| Service | Usage | Configuration |
|---------|-------|---------------|
| Ollama | Embeddings locaux pour recherche sémantique | Installer depuis ollama.ai |
| Claude API | Classification IA avancée | Clé API dans config.php |

### Ollama (recommandé pour recherche sémantique)

```batch
# Installer Ollama depuis https://ollama.ai/

# Télécharger le modèle d'embeddings
ollama pull nomic-embed-text

# Vérifier
curl http://localhost:11434/api/tags
```

## Scripts disponibles

### Principal
- `install.bat` - Menu principal d'installation

### Scripts individuels (dans `scripts/`)
| Script | Description |
|--------|-------------|
| `check-deps.bat` | Vérifie toutes les dépendances |
| `setup-docker-services.bat` | Configure OnlyOffice + Qdrant |
| `install-docker.bat` | Installe Docker Desktop |
| `install-libreoffice.bat` | Installe LibreOffice |
| `install-tesseract.bat` | Installe Tesseract OCR |
| `install-pdf-tools.bat` | Installe Ghostscript et Poppler |

## Vérification de l'installation

```batch
# Vérifier toutes les dépendances
installer\scripts\check-deps.bat

# Ou via K-Docs
http://localhost/kdocs/health
```

### URLs des services

| Service | URL | Test |
|---------|-----|------|
| K-Docs | http://localhost/kdocs | Interface principale |
| OnlyOffice | http://localhost:8080/healthcheck | Doit retourner `true` |
| Qdrant | http://localhost:6333/dashboard | Interface web |
| Ollama | http://localhost:11434/api/tags | Liste des modèles |

## Configuration K-Docs

Les chemins sont auto-détectés dans `config/config.php`. Sections importantes :

```php
// Services Docker
'onlyoffice' => [
    'enabled' => true,
    'server_url' => 'http://localhost:8080',
    'callback_url' => 'http://192.168.x.x/kdocs', // Votre IP locale
],

// Recherche sémantique
'ollama' => [
    'url' => 'http://localhost:11434',
    'embedding_model' => 'nomic-embed-text',
],
'qdrant' => [
    'url' => 'http://localhost:6333',
    'collection' => 'kdocs_documents',
],

// Outils système
'tools' => [
    'ghostscript' => 'C:\\Program Files\\gs\\gs10.03.1\\bin\\gswin64c.exe',
    'libreoffice' => 'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
],
'ocr' => [
    'tesseract_path' => 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
],
```

## Dépannage

### Docker ne démarre pas
```batch
# Vérifier WSL2
wsl --status

# Si non installé
wsl --install
# Puis redémarrer
```

### OnlyOffice ne répond pas
```batch
docker logs kdocs-onlyoffice
docker restart kdocs-onlyoffice
```

### Qdrant ne répond pas
```batch
docker logs kdocs-qdrant
docker restart kdocs-qdrant
```

### Recréer les services Docker
```batch
cd C:\wamp64\www\kdocs
docker-compose down
docker-compose up -d
```

### Réinitialisation complète Docker
```batch
docker-compose down -v  # Supprime aussi les volumes
docker-compose up -d
```

## Désinstallation

### Logiciels Windows
Via "Programmes et fonctionnalités" :
- Docker Desktop
- LibreOffice  
- Tesseract-OCR
- Ghostscript

### Services Docker
```batch
cd C:\wamp64\www\kdocs
docker-compose down -v --rmi all
```

### Poppler
Supprimer le dossier `C:\Program Files\poppler`
