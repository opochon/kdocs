# K-Docs - Feuille de Route

## Contrainte globale

**PAS DE DOCKER pour les apps** - Tout doit etre portable et embarquable.

---

## Phase actuelle : GED Core v1.0

### Implemente
- [x] Structure de base
- [x] Indexation filesystem (.index incremental)
- [x] OCR Tesseract + pdftotext
- [x] Extraction texte DOCX/Office
- [x] Classification IA (Claude/Ollama)
- [x] Workflow visuel
- [x] Recherche semantique (Qdrant + Ollama)
- [x] OnlyOffice (Docker - GED seulement)
- [x] API REST complete
- [x] Corbeille avec hooks embeddings

### A corriger (voir docs/CORRECTIONS_PRIORITAIRES.md)
- [ ] Miniatures fonctionnelles
- [ ] Apercu document dans modale
- [ ] Extraction contenu OCR

---

## Phase 2 : Connecteur WinBiz (Fevrier 2026)

- [x] Structure connectors/winbiz/ creee
- [x] WinBizConnector.php (placeholder)
- [ ] Connexion ODBC fonctionnelle
- [ ] Lecture articles/stock
- [ ] Lecture BL/Fiches travail
- [ ] Tests avec vraie base

---

## Phase 3 : App K-Time (Fevrier-Mars 2026)

### Structure creee
- [x] apps/timetrack/
- [x] Controllers/, Models/, Services/, templates/, migrations/
- [x] routes.php, config.php
- [x] Migration SQL complete (001_create_timetrack_tables.sql)

### A implementer
- [ ] CRUD Clients/Projets
- [ ] Parser QuickCodeParser
- [ ] Saisie rapide Quick Codes
- [ ] Chronometre (TimerService)
- [ ] PDF factures (TCPDF)
- [ ] Integration K-Docs

---

## Phase 4 : App K-Invoices (Mars 2026)

### Structure creee
- [x] apps/invoices/
- [x] Controllers/, Models/, Services/, templates/, migrations/
- [x] routes.php, config.php

### A implementer
- [ ] Extraction lignes factures (IA)
- [ ] Rapprochement WinBiz
- [ ] Interface validation
- [ ] Export comptable

---

## Phase 5 : App K-Mail (Avril-Mai 2026)

### Structure creee
- [x] apps/mail/
- [x] Controllers/, Models/, Services/, templates/, migrations/
- [x] routes.php, config.php

### A implementer
- [ ] IMAP/SMTP natif (php-imap)
- [ ] SQLite cache local
- [ ] Qdrant binaire (recherche semantique)
- [ ] CalDAV agenda (Sabre/DAV)

---

## Phase 6 : App Desktop (Juin 2026)

- [ ] Tauri + FrankenPHP
- [ ] Build Windows/Mac/Linux
- [ ] Auto-update
- [ ] Qdrant embarque

---

## Version 1.1.0 - Q1 2026

### Amelioration Core
- [ ] Performance indexation
- [ ] Queue robuste (retry, dead-letter)
- [ ] Cache intelligent (APCu)
- [ ] Permissions granulaires par dossier

### Nouvelles fonctionnalites
- [ ] Versioning documents
- [ ] Annotations PDF
- [ ] OCR batch
- [ ] Export bulk

---

## Version 1.2.0 - Q2 2026

### Connecteurs
- [ ] kDrive (WebDAV)
- [ ] Nextcloud (WebDAV)
- [ ] S3/MinIO

---

## Version 2.0.0 - Q4 2026

### Architecture
- [ ] API GraphQL
- [ ] WebSockets temps reel
- [ ] Plugin system

### Fonctionnalites entreprise
- [ ] SSO/SAML
- [ ] LDAP/AD
- [ ] Retention legale
- [ ] Signatures electroniques

---

## Backlog (non planifie)

- [ ] Application mobile native
- [ ] Extension navigateur
- [ ] Integration ERP (SAP, Odoo)
- [ ] OCR cloud (Google Vision, Azure)
- [ ] Mode hors-ligne avec sync

---

## Priorites actuelles

1. **Corrections GED Core** - Miniatures, apercu, OCR
2. **Connecteur WinBiz** - ODBC fonctionnel
3. **K-Time MVP** - Saisie horaire basique
4. **K-Invoices** - Extraction IA

---

*Derniere mise a jour : 30 janvier 2026*
*RAPPEL : PAS DE DOCKER POUR LES APPS - PHP NATIF UNIQUEMENT*
