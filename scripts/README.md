# Kit d'indexation - Affaire VPO vs OPO

## Objectif
Indexer tous les emails (.msg) et documents (PDF, DOCX, images) dans une base SQLite avec recherche full-text (FTS5) pour des requêtes instantanées.

## Installation

### 1. Dépendances Python
```bash
pip install extract-msg python-dateutil pymupdf python-docx pillow
```

### 2. OCR (optionnel mais recommandé)
```bash
# Windows - installer Tesseract:
# https://github.com/UB-Mannheim/tesseract/wiki

# Puis:
pip install ocrmypdf
```

### 3. Vérifier l'installation
```bash
python -c "import extract_msg; import fitz; print('OK')"
tesseract --version
```

## Utilisation

### Étape 1: Initialiser la base
```bash
python init_db.py
# Crée: vpo_affaire.db
```

### Étape 2: Importer les emails
```bash
python ingest_msg.py "C:\Users\opochon\Documents\Affaire VPO vs OPO"
```
- Extrait tous les .msg récursivement
- Stocke corps + métadonnées
- Sauvegarde les pièces jointes dans `vault/`
- Déduplique par hash SHA256

### Étape 3: Importer les documents
```bash
python ingest_docs.py "C:\Users\opochon\Documents\Affaire VPO vs OPO"
```
- Traite PDF, DOCX, images, fichiers texte
- OCR automatique si peu de texte natif
- Déduplique par hash

### Étape 4: Rechercher
```bash
# Recherche simple
python query_db.py "pension alimentaire"

# Filtrer par type
python query_db.py "expertise" --type email

# Filtrer par date
python query_db.py "avocat" --from 2023-01-01 --to 2024-06-01

# Voir les stats
python query_db.py --stats

# Détail d'un email
python query_db.py --detail email:42

# Export JSON
python query_db.py "tribunal" --export-json resultats.json
```

## Structure de la base

### Tables principales
- `emails` - Métadonnées et corps des emails
- `attachments` - Pièces jointes (liées aux emails)
- `documents` - Documents autonomes (PDF, DOCX, etc.)
- `links` - Relations entre objets

### Index FTS5
- `emails_fts` - Recherche dans subject, sender, recipients, body
- `attachments_fts` - Recherche dans filename, extracted_text
- `documents_fts` - Recherche dans filename, extracted_text

## Syntaxe de recherche FTS5

```bash
# Terme simple
python query_db.py "divorce"

# Plusieurs termes (ET implicite)
python query_db.py "pension alimentaire"

# Expression exacte
python query_db.py '"pension alimentaire"'

# OU
python query_db.py "pension OR aliments"

# Négation
python query_db.py "divorce NOT provisoire"

# Préfixe
python query_db.py "tribun*"
```

## Réingestion (après modification des scripts)

Pour réimporter avec une nouvelle version du parser:
```bash
# Supprimer et recréer la base
del vpo_affaire.db
python init_db.py
python ingest_msg.py "..."
python ingest_docs.py "..."
```

## Intégration avec Claude

Une fois la base créée, tu peux:
1. **Uploader `vpo_affaire.db`** dans la conversation
2. Je pourrai faire des requêtes SQL directes
3. Ou tu copies le résultat de `query_db.py` dans le chat

## Fichiers

```
vpo_scripts/
├── init_db.py      # Crée le schéma SQLite + FTS5
├── ingest_msg.py   # Importe les .msg Outlook
├── ingest_docs.py  # Importe PDF/DOCX/images avec OCR
├── query_db.py     # Outil de recherche CLI
└── README.md       # Ce fichier

Après exécution:
├── vpo_affaire.db  # Base SQLite (à uploader)
└── vault/          # Pièces jointes extraites
    └── ab/cd/...   # Structure par hash
```
