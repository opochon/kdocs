# K-Docs - Restructuration et Feuille de Route

## Contexte

K-Docs est une GED PHP en développement actif (8 jours). Elle fonctionne mais nécessite :
1. Une restructuration propre des dossiers
2. Un nettoyage de la racine
3. Une documentation claire de l'architecture
4. La création de la structure pour les futures apps intégrées

## ⚠️ CONTRAINTE CRITIQUE : PAS DE DOCKER POUR LES APPS

Les applications intégrées (mail, timetrack, invoices) doivent être :
- **100% PHP natif** (pas de Docker, pas de services externes lourds)
- **Légères et performantes**
- **Portables** : embarquables dans un runtime léger (FrankenPHP, RoadRunner)
- **Cross-platform** : Windows, Mac, Linux, et potentiellement iOS/Android via wrapper

### Stack autorisée pour les apps

| ✅ Autorisé | ❌ Interdit |
|-------------|-------------|
| PHP natif (8.2+) | Docker |
| SQLite (embarqué) | Services externes lourds |
| MySQL (optionnel, partagé avec GED) | Elasticsearch |
| Qdrant **binaire natif** (pas Docker) | Redis obligatoire |
| Ollama local (optionnel) | Dépendances système complexes |
| Extensions PHP standards | Compilation custom |

### Qdrant sans Docker

```bash
# Windows : télécharger le binaire
# https://github.com/qdrant/qdrant/releases
# Extraire qdrant.exe dans C:\kdocs\bin\

# Lancer comme exécutable simple
.\bin\qdrant.exe

# Ou comme service Windows
sc create Qdrant binPath= "C:\kdocs\bin\qdrant.exe" start= auto
```

### Vision : Apps embarquables

```
┌─────────────────────────────────────────────────────┐
│           Application Desktop (Tauri/Electron)       │
│  ┌───────────────────────────────────────────────┐  │
│  │              FrankenPHP / RoadRunner           │  │
│  │  ┌─────────────────────────────────────────┐  │  │
│  │  │           K-Docs + Apps PHP              │  │  │
│  │  │  • GED Core                              │  │  │
│  │  │  • K-Mail (IMAP natif)                   │  │  │
│  │  │  • K-Time (SQLite)                       │  │  │
│  │  │  • K-Invoices                            │  │  │
│  │  └─────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────┘  │
│  ┌───────────────┐  ┌───────────────┐              │
│  │ Qdrant (bin)  │  │ SQLite (lib)  │              │
│  └───────────────┘  └───────────────┘              │
└─────────────────────────────────────────────────────┘
         ↓ Compile en binaire unique ↓
    [kdocs.exe] [kdocs.app] [kdocs.AppImage]
```

---

## Tâche principale

### 1. Analyser la racine actuelle

Lister tous les fichiers/dossiers à la racine de `C:\wamp64\www\kdocs\` et les catégoriser :
- **Core** : app/, public/, templates/, config/, storage/, vendor/
- **À garder** : docs/, tests/, scripts/
- **À classer** : fichiers loose (.php, .md, .txt, .json, .bat, .sh)
- **À supprimer ou archiver** : fichiers temporaires, doublons, obsolètes

### 2. Réorganiser la racine

Structure cible :
```
kdocs/
├── app/                    # Code source GED (existe)
├── apps/                   # À CRÉER - Applications intégrées
│   ├── mail/              # Client mail (PHP natif, IMAP)
│   ├── timetrack/         # Saisie horaire (PHP + SQLite)
│   └── invoices/          # Gestion factures (dépend GED)
├── connectors/            # À CRÉER - Connecteurs ERP
│   └── winbiz/            # WinBiz (ODBC FoxPro)
├── shared/                # À CRÉER - Code partagé
├── bin/                   # À CRÉER - Binaires (qdrant.exe, etc.)
├── config/                # Configuration (existe)
├── docs/                  # Documentation (existe, à enrichir)
├── public/                # Point d'entrée web (existe)
├── scripts/               # Scripts CLI (existe ou à créer)
├── storage/               # Stockage (existe)
├── templates/             # Vues (existe)
├── tests/                 # Tests (existe)
├── tools/                 # À CRÉER - Outils d'installation/maintenance
├── vendor/                # Composer (existe)
├── .gitignore
├── composer.json
├── README.md              # À CRÉER/METTRE À JOUR
└── ROADMAP.md             # À CRÉER - Feuille de route
```

### 3. Créer les fichiers README.md de structure

Chaque dossier principal doit avoir un README.md expliquant :
- Son rôle
- Sa structure interne
- Les fichiers clés
- Le statut (implémenté / en cours / à faire)
- **Les contraintes techniques** (pas de Docker, portable, etc.)

---

## Fichiers README à créer

### apps/README.md

```markdown
# K-Docs Applications

Applications intégrées légères et portables.

## ⚠️ Contraintes techniques

- **PAS DE DOCKER** - Toutes les apps sont 100% PHP natif
- **Légères** - Démarrage < 1 seconde
- **Portables** - Embarquables dans FrankenPHP/Tauri
- **Cross-platform** - Windows, Mac, Linux (futur: iOS, Android)

## Stack technique

| Composant | Technologie |
|-----------|-------------|
| Runtime | PHP 8.2+ natif |
| Base locale | SQLite (embarqué) |
| Base partagée | MySQL (optionnel, GED) |
| Vectorisation | Qdrant binaire (PAS Docker) |
| Embeddings | Ollama local (optionnel) |
| Mail | PHP IMAP extension |
| Calendrier | CalDAV (Sabre/DAV) |

## Applications

| App | Description | Statut | Dépendances |
|-----|-------------|--------|-------------|
| [mail](./mail/) | Client mail + agenda | 🔴 À faire | IMAP ext, Qdrant bin |
| [timetrack](./timetrack/) | Saisie horaire + factures | 🔴 À faire | SQLite |
| [invoices](./invoices/) | Gestion factures | 🔴 À faire | GED Core |

## Objectif : Application standalone

Chaque app peut fonctionner :
1. **Intégrée** dans K-Docs (mode web classique)
2. **Standalone** dans une app desktop (Tauri + FrankenPHP)
3. **Mobile** via PWA ou wrapper natif (futur)
```

### apps/mail/README.md

```markdown
# K-Mail

Client de messagerie léger avec recherche sémantique.

## ⚠️ Contraintes

- **PAS DE DOCKER**
- PHP IMAP extension uniquement
- Qdrant en binaire natif (pas conteneur)
- Doit démarrer en < 2 secondes

## Stack technique

| Composant | Solution |
|-----------|----------|
| IMAP/SMTP | `php-imap` extension native |
| CalDAV | Sabre/DAV (PHP pur) |
| Vectorisation | Qdrant binaire + API HTTP |
| Embeddings | Ollama local OU API externe |
| Cache | SQLite local |
| UI | PHP + Tailwind (SSR) |

## Fonctionnalités prévues

### Phase 1 - MVP (léger)
- [ ] Connexion IMAP
- [ ] Liste mails
- [ ] Lecture mail
- [ ] Envoi simple
- [ ] Recherche full-text (SQLite FTS5)

### Phase 2 - Sémantique
- [ ] Indexation vectorielle (Qdrant bin)
- [ ] Recherche par sens
- [ ] Suggestions

### Phase 3 - Agenda
- [ ] CalDAV sync
- [ ] Types de RDV
- [ ] Champs métier
```

### apps/timetrack/README.md

```markdown
# K-Time

Saisie horaire ultra-rapide avec facturation.

## ⚠️ Contraintes

- **PAS DE DOCKER**
- **PAS DE DÉPENDANCES EXTERNES**
- 100% PHP + MySQL (même base que GED)
- Démarrage instantané

## Stack technique

| Composant | Solution |
|-----------|----------|
| Base de données | MySQL (partagée avec K-Docs) |
| PDF | TCPDF ou Dompdf (PHP pur) |
| UI | PHP + Tailwind (SSR) |
| Export | CSV, JSON, WinBiz |

## Fonctionnalités

- Saisie rapide via Quick Codes : `2.5hA1 pAA2`
- Timer start/stop avec persistance
- Mode freelance + mode équipes planifié
- Génération factures PDF
- Intégration K-Docs (stockage factures, sync clients)

## Voir KTIME_SPECIFICATION.md pour les détails complets
```

### apps/invoices/README.md

```markdown
# K-Invoices

Gestion des factures fournisseurs.

## ⚠️ Contraintes

- **PAS DE DOCKER**
- Dépend de K-Docs Core (documents)
- PHP natif uniquement

## Fonctionnalités

- Extraction automatique des lignes (IA)
- Rapprochement avec BL, fiches de travail, stock (WinBiz)
- Validation ligne par ligne
- Export comptable WinBiz
- Apprentissage par fournisseur
```

### connectors/README.md

```markdown
# K-Docs Connecteurs

Connecteurs vers systèmes externes (ERP, comptabilité).

## Principe

- Chaque connecteur est **isolé** dans son dossier
- Communication via **classes PHP** (pas d'API externe)
- Configuration dans `config/connectors.php`

## Connecteurs

| Connecteur | Type | Statut | Description |
|------------|------|--------|-------------|
| [winbiz](./winbiz/) | FoxPro/ODBC | 🔴 À faire | ERP suisse, compta |
```

### connectors/winbiz/README.md

```markdown
# Connecteur WinBiz

Connexion à WinBiz via ODBC (base FoxPro).

## Prérequis

- WinBiz installé localement
- Driver ODBC Visual FoxPro (32-bit)
- PHP ODBC extension

## Configuration

```php
// config/connectors.php
'winbiz' => [
    'enabled' => true,
    'db_path' => 'C:\\WinBiz\\Data\\MACOMPAGNIE\\',
    'read_only' => false,
]
```
```

### bin/README.md

```markdown
# Binaires K-Docs

Exécutables nécessaires au fonctionnement (PAS de Docker).

## Contenu

| Binaire | Usage | Téléchargement |
|---------|-------|----------------|
| qdrant.exe | Base vectorielle | [GitHub Qdrant](https://github.com/qdrant/qdrant/releases) |
| ollama.exe | LLM local (optionnel) | [Ollama.ai](https://ollama.ai) |

## Installation Qdrant

```bash
# Windows
curl -LO https://github.com/qdrant/qdrant/releases/latest/download/qdrant-x86_64-pc-windows-msvc.zip
unzip qdrant-*.zip -d bin/

# Lancer
bin/qdrant.exe --config-path config/qdrant.yaml
```
```

### shared/README.md

```markdown
# K-Docs Shared

Code partagé entre la GED et les applications.

## Modules

| Module | Description |
|--------|-------------|
| Auth/ | Authentification unifiée |
| ApiClient/ | Client API interne K-Docs |
| UI/ | Composants UI réutilisables |
| Helpers/ | Fonctions utilitaires |
```

---

## ROADMAP.md (racine)

```markdown
# K-Docs - Feuille de Route

## Contrainte globale

**PAS DE DOCKER pour les apps** - Tout doit être portable et embarquable.

---

## Phase actuelle : Core GED ✅🟡

- [x] Structure de base
- [x] Indexation filesystem (.index incrémental)
- [x] OCR Tesseract
- [x] Classification IA (Claude/Ollama)
- [x] Workflow visuel
- [x] OnlyOffice (Docker - GED seulement)
- [ ] **Corrections prioritaires** (voir docs/CORRECTIONS_PRIORITAIRES.md)
  - [ ] Miniatures fonctionnelles
  - [ ] Aperçu document dans modale
  - [ ] Extraction contenu OCR

## Phase 2 : Connecteur WinBiz (Février 2025)

- [ ] WinBizConnector.php (ODBC)
- [ ] Lecture articles/stock
- [ ] Lecture BL/Fiches travail
- [ ] Tests avec vraie base

## Phase 3 : App Invoices (Février-Mars 2025)

- [ ] Extraction lignes factures (IA)
- [ ] Rapprochement WinBiz
- [ ] Interface validation
- [ ] Export comptable

## Phase 4 : App Timetrack (Mars 2025)

- [ ] Migration schema depuis Next.js
- [ ] Saisie rapide Quick Codes
- [ ] Chronomètre
- [ ] PDF factures (TCPDF)
- [ ] Intégration K-Docs

## Phase 5 : App Mail (Avril-Mai 2025)

- [ ] IMAP/SMTP natif
- [ ] SQLite cache local
- [ ] Qdrant binaire (recherche sémantique)
- [ ] CalDAV agenda

## Phase 6 : App Desktop (Juin 2025)

- [ ] Tauri + FrankenPHP
- [ ] Build Windows/Mac/Linux
- [ ] Auto-update
```

---

## README.md (racine)

```markdown
# K-Docs

Gestion Électronique de Documents (GED) modulaire avec applications métier intégrées.

## 🎯 Vision

- **Filesystem-first** : Documents dans une arborescence classique
- **Léger** : Pas de Docker pour les apps (PHP natif)
- **Portable** : Embarquable dans une app desktop (Tauri)
- **Intelligent** : Classification IA, recherche sémantique

## 📁 Structure

```
kdocs/
├── app/              # GED Core
├── apps/             # Applications intégrées (PHP natif)
│   ├── mail/        # Client mail + agenda
│   ├── timetrack/   # Saisie horaire
│   └── invoices/    # Gestion factures
├── connectors/      # Connecteurs ERP
│   └── winbiz/      # WinBiz (ODBC)
├── bin/             # Binaires (qdrant, etc.)
├── shared/          # Code partagé
├── config/          # Configuration
├── docs/            # Documentation
├── public/          # Point d'entrée web
├── storage/         # Fichiers stockés
├── templates/       # Vues PHP
└── tests/           # Tests
```

## 🚀 Stack technique

| Composant | Technologie |
|-----------|-------------|
| Backend | PHP 8.2+ natif |
| Base GED | MySQL 8.0 |
| Base Apps | MySQL (partagée) ou SQLite |
| Vectorisation | Qdrant (binaire, PAS Docker) |
| IA | Claude API / Ollama local |
| OCR | Tesseract |
| Office | OnlyOffice (Docker, GED seulement) |

## 📖 Documentation

- [Feuille de route](ROADMAP.md)
- [Structure apps](docs/KDOCS_STRUCTURE_APPS.md)
- [Spec K-Time](docs/KTIME_SPECIFICATION.md)
- [Corrections prioritaires](docs/CORRECTIONS_PRIORITAIRES.md)

## 📄 Licence

Propriétaire - Karbonic Sàrl
```

---

## Commandes à exécuter

```bash
# 1. Créer la structure
mkdir -p apps/{mail,timetrack,invoices}/{Controllers,Models,Services,templates,migrations}
mkdir -p connectors/winbiz
mkdir -p shared/{Auth,ApiClient,UI,Helpers}
mkdir -p bin
mkdir -p tools/{install,sql}
mkdir -p _archive

# 2. Créer les .gitkeep
find apps connectors shared bin -type d -empty -exec touch {}/.gitkeep \;

# 3. Mettre à jour .gitignore
cat >> .gitignore << 'EOF'
# Binaires
/bin/qdrant*
/bin/ollama*

# Apps storage
/storage/apps/

# Archive
/_archive/
EOF
```

---

## Checklist finale

- [ ] Racine nettoyée (fichiers loose classés)
- [ ] Structure apps/ créée avec README.md
- [ ] Structure connectors/ créée avec README.md
- [ ] Structure bin/ créée avec README.md
- [ ] Structure shared/ créée
- [ ] README.md racine mis à jour
- [ ] ROADMAP.md créé
- [ ] .gitignore mis à jour
- [ ] Aucune référence à Docker dans les apps

---

*Document pour Claude Code - 30/01/2026*
*⚠️ RAPPEL : PAS DE DOCKER POUR LES APPS - PHP NATIF UNIQUEMENT*
