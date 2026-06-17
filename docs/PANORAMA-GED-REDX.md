# Panorama GED — REDX, marché et positionnement GEDv1

> Analyse comparative — 2026-06-17  
> Projet : **GEDv1** (K-Docs migré) — `F:\DATA\DEVELOPPEMENT\GEDv1`  
> Objectif : atteindre le niveau **REDX** sans données outsourcées (self-hosted, documents et métadonnées on-premise).

---

## 1. Executive summary

**REDX** n'est pas un dépôt logiciel local ni un produit Xerox autonome. C'est l'offre GED/ECM de l'intégrateur suisse **[RedX](https://www.red-x.net/fr/ged-ecm/)** (red-x.net), principalement construite sur **M-Files** avec des configurations métier prêtes à l'emploi : REDX Factures, REDX Contrat, REDX SMQ, REDX RH. RedX revend aussi **Xerox DocuShare Go** comme solution ECM complémentaire ([fiche RedX](https://www.red-x.net/fr/sheets/solutions-fr/xerox-docushare-go-fr/)).

Pour une fiduciaire ou PME suisse romande, REDX représente la référence marché : GED orientée **métadonnées**, workflows de validation, archivage conforme **Olico/GeBüV**, intégration ERP, coffre numérique multi-mandants, mobilité et accompagnement local.

**GEDv1 (K-Docs)** possède une base technique riche et différenciante (OCR, IA locale/cloud configurable, workflows visuels, API REST, OnlyOffice, connecteur WinBiz ODBC) mais reste en **bêta opérationnelle** avec des bugs P0 bloquants, des apps satellites non branchées et l'absence de couches conformité archivage légal suisse.

| Indicateur | Estimation |
|------------|------------|
| Parité fonctionnelle vs REDX (cas fiduciaire) | **~48 %** |
| Parité vs Paperless-ngx (archivage personnel/PME) | **~70 %** (doc interne janv. 2026) |
| Fonctions gap identifiées | **38** (présent / partiel / absent) |
| Horizon réaliste niveau REDX self-hosted | **12–18 mois** en lots incrémentaux |

**Top 3 open source pertinents** pour le cas d'usage fiduciaire self-hosted : **Mayan EDMS** (workflows + RBAC), **Paperless-ngx** (OCR/simplicité, socle ingestion), **LogicalDOC Community** (Java mature, ECM PME).

**Top 3 marché comparables** : **M-Files** (socle REDX), **DocuWare** (workflows factures PME), **Xerox DocuShare** (ECM IA, on-premise possible).

**Recommandation lot 1** : corriger les P0 UI/OCR + valider connecteur WinBiz ODBC + brancher l'app invoices — voir section 9.

---

## 2. Xerox REDX — fiche produit et capacités de référence

### 2.1 Clarification terminologique (factuel vs hypothèse)

| Affirmation | Statut | Source |
|-------------|--------|--------|
| REDX = intégrateur suisse RedX + configurations M-Files | **Confirmé** | [red-x.net/ged-ecm](https://www.red-x.net/fr/ged-ecm/) |
| REDX Factures / Contrat / SMQ / RH = modules configurés M-Files | **Confirmé** | [red-x.net/ged-ecm](https://www.red-x.net/fr/ged-ecm/) |
| RedX revend aussi Xerox DocuShare Go | **Confirmé** | [red-x.net DocuShare Go](https://www.red-x.net/fr/sheets/solutions-fr/xerox-docushare-go-fr/) |
| REDX = produit Xerox natif | **Non** — confusion initiale | Xerox ECM = **DocuShare** ([xerox.com/fr-ch](https://www.xerox.com/fr-ch/services/solutions-ecm)) |
| REDX = dépôt code local | **Non** — aucun dépôt trouvé sur `F:\DATA\DEVELOPPEMENT\` | Recherche migration 2026-06-17 |

> **Référence de comparaison retenue** : l'ensemble des capacités REDX telles que commercialisées (configurations M-Files + exigences fiduciaires suisses), pas le code source Xerox DocuShare.

### 2.2 Offre REDX — configurations métier

D'après [RedX GED/ECM](https://www.red-x.net/fr/ged-ecm/) :

| Configuration | Fonction | Capacités clés |
|---------------|----------|----------------|
| **REDX Factures** | Dématérialisation factures fournisseurs | Capture, circuit validation, coffre numérique |
| **REDX Contrat** | Cycle de vie contrats | Centralisation, échéances, alertes, annexes |
| **REDX SMQ** | Système qualité ISO | Sommaire structuré, versions, quittance de lecture |
| **REDX RH** | Dossier digital collaborateur | Stockage sécurisé, accès par rôle |
| Socle **M-Files** | GED/ECM générique | Métadonnées, recherche par nature, workflows, mobilité |

### 2.3 Capacités typiques REDX / M-Files (référence marché)

| Domaine | Capacités attendues | Niveau REDX |
|---------|---------------------|-------------|
| **Capture** | Scanner MFP, email, upload, drag-drop | Élevé |
| **OCR / indexation** | Texte intégral, métadonnées auto | Élevé |
| **Organisation** | Métadonnées (pas dossiers physiques) | Élevé — cœur M-Files |
| **Workflow** | Validation factures, approbations, notifications | Élevé |
| **Archivage légal** | Conforme Olico/GeBüV, révision, 10 ans | Élevé — argument commercial CH |
| **Sécurité** | RBAC, chiffrement transit, audit | Élevé |
| **ERP** | Intégration comptable (WinBiz écosystème CH) | Élevé (via intégrateur) |
| **Recherche** | Par métadonnées, contexte, IA | Élevé |
| **Mobilité** | Apps natives, accès distant sécurisé | Élevé |
| **Conformité qualité** | ISO 9001/13485, quittances lecture | Élevé (REDX SMQ) |
| **RH** | Dossiers employés structurés | Élevé (REDX RH) |
| **Hébergement** | On-premise ou cloud fiduciaire suisse | Les deux proposés |

Sources complémentaires : [intusdata FAQ GED](https://www.intusdata.ch/fr/updates/faq-ged/), [intusdata M-Files fiduciaires](https://www.intusdata.ch/fr/produits/gestion-documentaire/document-management-avec-m-files/), [M-Files](https://www.m-files.com/fr/).

### 2.4 Xerox DocuShare (produit Xerox distinct, vendu par RedX)

Plateforme ECM Xerox, disponible cloud ou on-premise ([DocuShare platform](https://www.xerox.com/en-na/services/enterprise-content-management-solutions/docushare-platform)) :

- Capture intelligente, IDP (Intelligent Document Processing)
- IA : extraction données, recherche contextuelle, synthèse
- Workflows : validation factures, onboarding RH, contrats
- Sécurité : RBAC, conformité audit
- Intégrations ERP et systèmes métier
- **DocuShare Go** : version PME sans infrastructure dédiée ([fiche RedX](https://www.red-x.net/fr/sheets/solutions-fr/xerox-docushare-go-fr/)) — **non compatible** avec contrainte « sans SaaS tiers pour les documents » si hébergé chez Xerox.

---

## 3. Panorama GED open source (2025–2026)

### 3.1 Tableau comparatif

| Solution | Maturité | Self-host | OCR | Workflow | API | ERP / intégrations | Forces | Faiblesses |
|----------|----------|-----------|-----|----------|-----|-------------------|--------|------------|
| **Paperless-ngx** | ★★★★★ | Docker, très simple | Tesseract natif, excellent | Basique (tags/règles) | REST solide | Webhooks, email consume | Simplicité, communauté, OCR rapide | Pas ECM entreprise, pas conformité CH |
| **Mayan EDMS** | ★★★★ | Docker, lourd (6+ conteneurs) | Tesseract + plugins | **Moteur complet** (états, transitions) | REST + webhooks | Staging, email, API | RBAC fin, versioning, workflows | Complexité, RAM 4+ Go, courbe apprentissage |
| **OpenKM** | ★★★ | Java/Tomcat | Oui | Modéré | REST/SOAP | Connecteurs payants | ECM complet, records mgmt | UI datée, édition community limitée |
| **Alfresco CE** | ★★★★ | Docker/K8s, Java | Transform + OCR tiers | Activiti/Flowable | CMIS, REST | Écosystème Hyland | Gouvernance, scalabilité | Clustering = Enterprise, lourd PME |
| **Nuxeo** (open core) | ★★★★ | Docker, Java/OSGi | OCR plugins | BPM intégré | REST, low-code | Nombreux connecteurs | Modularité, assets riches | Open core ≠ tout gratuit, complexité |
| **LogicalDOC CE** | ★★★★ | Java/Tomcat, installeur | Oui | Basique à modéré | REST/WebDAV | LDAP, CMIS | Actif (v9.2, 2025), stable Java | Fonctions avancées = édition payante |
| **SeedDMS** | ★★★ | PHP, léger | Tesseract optionnel | Basique | API limitée | Peu | PHP natif, simple PME | Moins riche qu'un Mayan, UI basique |
| **Kimios** | ★ (stagnant) | Java J2EE | Oui | Modéré | SOAP/REST | Peu | Historique français | **Dernier commit 2023** — à éviter |
| **Docspell** | ★★★ | Scala, Docker | Tesseract + ML | Basique | REST | Email, IMAP | Extraction métadonnées auto | Communauté plus petite |
| **Teedy** | ★★★ | Java, léger | Tesseract | Minimal | REST | Peu | UI moderne, simple | Trop léger pour fiduciaire |

Sources : [Selfhostr 2026](https://selfhostr.com/comparatifs/paperless-ngx-vs-mayan-vs-docspell-2026/), [Big Iron](https://www.bigiron.cc/guides/paperless-ngx-vs-mayan-edms-vs-teedy), [LogicalDOC GitHub](https://github.com/logicaldoc/community), [Kimios GitHub](https://github.com/kimios/kimios).

### 3.2 Pertinence contexte PME / fiduciaire Suisse

| Besoin fiduciaire | Meilleur match OSS | Commentaire |
|-------------------|-------------------|-------------|
| Archivage factures + OCR | Paperless-ngx, GEDv1 | Paperless plus mature ingestion ; GEDv1 plus workflows |
| Workflows validation | Mayan EDMS, GEDv1 | Mayan clé en main ; GEDv1 déjà moteur visuel |
| Conformité Olico/GeBüV | **Aucun OSS natif CH** | Nécessite couche custom (GEDv1 ou extension Mayan) |
| Intégration WinBiz | **GEDv1 seul** | Connecteur ODBC présent |
| RBAC document | Mayan, Alfresco | GEDv1 partiel (groupes, rôles validation) |
| Self-hosted sans SaaS | Tous sauf Kimios | Mayan/Paperless/LogicalDOC recommandés |

### 3.3 Top 3 open source pour le cas d'usage Karbonic

1. **Mayan EDMS** — plus proche de REDX sur workflows, RBAC, versioning ; base de comparaison ECM.
2. **Paperless-ngx** — référence OCR/ingestion ; GEDv1 a dépassé ~70 % parité (doc interne).
3. **LogicalDOC Community** — alternative Java stable si abandon PHP ; moins pertinent vu investissement GEDv1.

---

## 4. Panorama GED marché / commercial

### 4.1 Tableau comparatif

| Solution | Type | Self-host | Capture | Workflow | Conformité CH | ERP | Coût indicatif | Lock-in |
|----------|------|-----------|---------|----------|---------------|-----|----------------|---------|
| **M-Files** (socle REDX) | Commercial | Oui + cloud CH | Élevé | Élevé | **Oui** (Olico, GeBüV) | Fort (API, M-365) | Licence/user + services | Élevé (métadonnées propriétaires) |
| **Xerox DocuShare** | Commercial | Oui + cloud | Élevé (IA/IDP) | Élevé | Configurable | ERP, M365 | Élevé | Moyen-élevé |
| **DocuWare** | Commercial SaaS/on-prem | Les deux | Intelligent Indexing | Très bon PME | UE (GoBD), adapt. CH | SAP B1, Dynamics, Sage | Moyen-élevé | Moyen |
| **OpenText ECM** | Enterprise | Principalement on-prem | Très élevé | Très élevé | Fort (régulé) | Extensif | Très élevé | Très élevé |
| **Alfresco Enterprise** | Commercial | Oui | Élevé | BPM intégré | Configurable | CMIS, API | Élevé | Moyen |
| **SharePoint + Purview** | Microsoft 365 | Cloud (M365) | Moyen | Power Automate | eDiscovery, rétention | Écosystème MS | Abonnement/user | **Très élevé** (cloud MS) |
| **REDX (package)** | Intégrateur CH | Via M-Files on-prem | Clé en main | Configuré métier | **Natif CH** | WinBiz via intégrateur | Services + licences | Via M-Files |
| **WinBiz écosystème** | ERP comptable CH | Local | N/A (ERP) | N/A | Oui | Natif | Inclus ERP | N/A |

Sources : [Doxis comparatif ECM](https://www.doxis.com/en/blog/top-6-ecm-systems), [Viewpoint Analysis 2026](https://www.viewpointanalysis.com/post/document-management-software-options-2026), [Imagex M-Files vs DocuWare](https://www.imagexinc.com/m-files-vs-docuware), [intusdata](https://www.intusdata.ch/fr/updates/faq-ged/).

### 4.2 Top 3 marché comparables

1. **M-Files** — socle réel de REDX ; référence absolue pour delta fonctionnel fiduciaire.
2. **DocuWare** — alternative PME européenne, workflows factures matures, moins complexe qu'OpenText.
3. **Xerox DocuShare** — ECM IA moderne, on-premise possible ; RedX le commercialise en parallèle de M-Files.

### 4.3 Solutions à éviter pour contrainte « sans données outsourcées »

| Solution | Risque |
|----------|--------|
| SharePoint / OneDrive seul | Documents chez Microsoft |
| DocuShare Go (cloud Xerox) | Hébergement tiers |
| M-Files Cloud (hors CH) | Données hors périmètre |
| Box, Google Drive, Dropbox | SaaS pur |

**Acceptable** : M-Files ou DocuShare **on-premise** ; OCR/IA **locaux** (Tesseract, Ollama) ; Claude API = **à éviter pour contenu document** si politique stricte (métadonnées et texte partent vers Anthropic).

---

## 5. Positionnement GEDv1 — où on en est

### 5.1 Synthèse capacités actuelles (K-Docs / GEDv1)

D'après `docs/ARCHITECTURE.md`, `docs/FUNCTIONS-INDEX.md`, `docs/CODE-ANALYSIS.md`, `docs/PLUGIN-SYSTEM.md` :

| Domaine | État | Détail |
|---------|------|--------|
| **Stack** | Mature | PHP 8.1+, Slim 4, MySQL, ~165 classes, ~40 API controllers |
| **Documents** | Opérationnel | CRUD, arborescence, corbeille, versions, snapshots |
| **OCR** | Partiel (P0) | Tesseract ; bugs indexation contenu OCR |
| **IA** | Avancé | Claude/Ollama cascade, classification, extraction, suggestions |
| **Recherche** | Bon | Fulltext MySQL + sémantique Qdrant optionnel |
| **Workflows** | Bon | Moteur visuel, nœuds typés, approbation par token |
| **Validation** | Partiel | `ValidationService` ; badge UI P0 cassé |
| **OnlyOffice** | Partiel | Édition DOCX ; intégration à stabiliser |
| **WinBiz** | Code présent | ODBC 32-bit, non validé terrain ; `MatchingService` |
| **Apps métier** | Stubs | invoices, mail non branchés ; timetrack partiel |
| **Conformité CH** | Absent | Pas archivage Olico/WORM certifié |
| **Plugin system** | Absent | Vision documentée, non implémenté |
| **Tests** | Moyen | Smoke migration + 20 PHPUnit ; P0 non épinglés |
| **Sécurité** | Moyen+ | Auth, CSRF, rate-limit ; pas antivirus uploads |

### 5.2 Score positionnement vs REDX

| Critère | Poids | GEDv1 | Commentaire |
|---------|-------|-------|-------------|
| Capture / ingestion | 10 % | 65 % | Consume folder, MSG, email partiel |
| OCR / indexation | 10 % | 55 % | P0 OCR non indexé |
| Organisation métadonnées | 10 % | 70 % | Types, tags, champs custom ; pas métadonnées-first pur |
| Workflows | 15 % | 75 % | Moteur visuel compétitif |
| Archivage légal CH | 15 % | 15 % | Gap majeur |
| Sécurité / RBAC | 10 % | 60 % | Groupes, rôles validation ; pas ACL document fin |
| ERP WinBiz | 15 % | 30 % | Connecteur code, pas UI ni validation prod |
| Modules métier (factures, RH, SMQ) | 10 % | 25 % | Stubs ou absents |
| Mobilité / UX | 5 % | 50 % | Web responsive ; P0 UI bloquants |
| **Score pondéré global** | 100 % | **~48 %** | |

### 5.3 Avantages différenciants GEDv1 vs REDX/M-Files

- **Contrôle total** du code et des données (self-hosted natif).
- **IA configurable** avec option 100 % locale (Ollama).
- **Coût licence** nul (hors infra et maintenance).
- **Personnalisation** illimitée (workflows, champs, connecteurs).
- **Connecteur WinBiz ODBC** déjà amorcé — rare en OSS.

### 5.4 Faiblesses critiques vs REDX

- Pas de **certification archivage** Olico/GeBüV.
- **Bugs P0** empêchent usage quotidien (miniatures, aperçu, OCR).
- **Apps invoices/mail** non opérationnelles.
- Pas de modules **SMQ** (quittance lecture) ni **RH** structurés.
- **Accompagnement** et maturité produit inférieurs à un intégrateur.

---

## 6. Matrice delta GEDv1 vs REDX — fonction par fonction

Légende : ✅ Présent · 🟡 Partiel · ❌ Absent

| # | Fonction REDX / M-Files | GEDv1 | Statut |
|---|-------------------------|-------|--------|
| 1 | Capture scanner MFP / ConnectKey | Consume folder, upload | 🟡 |
| 2 | Ingestion email automatique | `EmailIngestionService`, app mail stub | 🟡 |
| 3 | OCR texte intégral | `OCRService` Tesseract | 🟡 (P0 indexation) |
| 4 | Classification automatique | IA + règles attribution | ✅ |
| 5 | Organisation par métadonnées | Types, tags, champs, correspondants | ✅ |
| 6 | Recherche fulltext | `SearchService` MySQL | ✅ |
| 7 | Recherche sémantique / IA | Qdrant + Ollama optionnel | 🟡 |
| 8 | Workflows validation | `WorkflowEngine` visuel | ✅ |
| 9 | Circuit validation factures fournisseurs | Validation modulaire, pas pack factures | 🟡 |
| 10 | Matching facture ↔ ERP | `MatchingService` + WinBiz ODBC | 🟡 |
| 11 | Export comptable WinBiz | Stub `apps/invoices/` | ❌ |
| 12 | Gestion contrats + échéances | Types documents génériques | 🟡 |
| 13 | Alertes échéances contrats | Pas de module dédié | ❌ |
| 14 | SMQ / documentation qualité ISO | Absent | ❌ |
| 15 | Quittance de lecture (ISO) | Absent | ❌ |
| 16 | Dossier RH digital | Absent | ❌ |
| 17 | Versioning document | `document_versions` | ✅ |
| 18 | Historique / audit trail | `audit_logs`, classification audit | ✅ |
| 19 | Archivage légal Olico/GeBüV | Absent | ❌ |
| 20 | Rétention / durée de vie document | Partiel (pas politiques légales) | 🟡 |
| 21 | Documents non modifiables (WORM) | Absent | ❌ |
| 22 | RBAC par document | Groupes ; pas ACL fine | 🟡 |
| 23 | Chiffrement transit | HTTPS (config serveur) | 🟡 |
| 24 | Accès mobile | Web responsive | 🟡 |
| 25 | Apps natives iOS/Android | Absent (roadmap Tauri) | ❌ |
| 26 | Édition bureautique | OnlyOffice | 🟡 |
| 27 | Visualisation PDF avancée | PDF.js | ✅ |
| 28 | Miniatures tous formats | `ThumbnailGenerator` | 🟡 (P0) |
| 29 | Import MSG / pièces jointes | `MSGImportService` | ✅ |
| 30 | Multi-mandant / cloisonnement | Non documenté | ❌ |
| 31 | Portail client externe | Absent | ❌ |
| 32 | E-signature | Absent | ❌ |
| 33 | Time tracking / facturation | `apps/timetrack/` partiel | 🟡 |
| 34 | Webhooks sortants | `WebhookService` | ✅ |
| 35 | API REST complète | ~40 contrôleurs API | ✅ |
| 36 | Sauvegarde / restore | `BackupService`, snapshots | ✅ |
| 37 | Plugin / connecteur formel | Vision seule | ❌ |
| 38 | Conformité TVA AFC (piste audit) | Audit partiel, pas certifié | 🟡 |

**Synthèse** : ✅ 12 · 🟡 17 · ❌ 9

---

## 7. Fonctions à implémenter — liste priorisée (P0–P4)

### P0 — Bloquants usage quotidien (existant K-Docs)

| ID | Fonction | Module |
|----|----------|--------|
| GAP-001 | `fixDocumentThumbnailPreview()` | `templates/documents/index.php` |
| GAP-002 | `regenerateMissingThumbnails()` | `ThumbnailGenerator` |
| GAP-003 | `ensureOcrContentIndexed()` | `DocumentProcessor`, `OCRService` |
| GAP-004 | `cycleValidationBadgeUI()` | `templates/documents/index.php` |

### P1 — Parité REDX Factures + WinBiz (3 mois)

| ID | Fonction | Module |
|----|----------|--------|
| GAP-010 | `ConnectorInterface` + `WinBizConnector::isConnected()` | `connectors/winbiz/` |
| GAP-011 | `WinBizConnector::getFacturesFournisseur()` | `connectors/winbiz/` |
| GAP-012 | `MatchingService::matchInvoiceToBL()` UI complète | `apps/invoices/` |
| GAP-013 | `InvoicesController::showMatchingUI()` | `apps/invoices/` |
| GAP-014 | `registerInvoicesRoutes()` | `index.php` |
| GAP-015 | Workflow type « facture fournisseur » préconfiguré | `workflows` seed |
| GAP-016 | Health check WinBiz dans `GET /health` | `AdminController` |

### P2 — Conformité archivage Suisse (6 mois)

| ID | Fonction | Module |
|----|----------|--------|
| GAP-020 | `LegalArchiveService::sealDocument()` — scellement WORM | nouveau service |
| GAP-021 | `RetentionPolicyService` — durées Olico (10 ans compta) | nouveau service |
| GAP-022 | `AuditExportService` — export piste révision | `AuditLogsController` |
| GAP-023 | Horodatage qualifié (option TSA) | intégration externe locale |
| GAP-024 | Politique « document légal = non modifiable » | `documents` flag + enforcement |

### P3 — Modules métier REDX (9–12 mois)

| ID | Fonction | Module |
|----|----------|--------|
| GAP-030 | Module REDX Contrat — échéances, alertes | `apps/contracts/` |
| GAP-031 | Module REDX SMQ — arborescence qualité | `apps/smq/` |
| GAP-032 | Quittance de lecture | `apps/smq/` |
| GAP-033 | Module REDX RH — dossier employé | `apps/hr/` |
| GAP-034 | `MailApp::syncImapMailbox()` + lien document | `apps/mail/` |
| GAP-035 | `PluginRegistry` formel | `app/Core/` |

### P4 — Infrastructure et polish (12–18 mois)

| ID | Fonction | Module |
|----|----------|--------|
| GAP-040 | ACL document fine (héritage dossier) | `FolderPermissionService` |
| GAP-041 | Multi-mandant (cloisonnement BDD) | architecture |
| GAP-042 | Portail client lecture seule | `apps/portal/` |
| GAP-043 | E-signature (SwissSign / locale) | connecteur |
| GAP-044 | App desktop Tauri | hors repo |
| GAP-045 | Antivirus upload (ClamAV local) | middleware |

**Total fonctions gap nommées : 38** (dont 4 P0 déjà documentées, 34 nouvelles ou étendues).

---

## 8. Stratégie « sans données outsourcées »

### 8.1 Principes

| Règle | Application GEDv1 |
|-------|-------------------|
| Documents sur disque local | `storage/documents/` — déjà en place |
| Métadonnées en MySQL local | BDD on-premise — déjà en place |
| Pas de SaaS document cloud | Éviter SharePoint, DocuShare Go cloud, M-Files Cloud hors CH |
| OCR local | Tesseract — **déjà en place** |
| IA locale par défaut | Ollama en cascade prioritaire ; Claude = opt-in désactivé prod |
| OnlyOffice self-hosted | Docker local port 8080 — acceptable |
| Qdrant self-hosted | Binaire local — acceptable |
| WinBiz ODBC local | Lecture FoxPro locale — **cible P1** |
| Sauvegardes locales | `BackupService` + snapshots — renforcer |

### 8.2 Architecture cible self-hosted

```
┌─────────────────────────────────────────────────────────────┐
│  Poste utilisateur / Scanner / MFP                           │
└──────────────────────────┬──────────────────────────────────┘
                           │ LAN (pas Internet pour documents)
┌──────────────────────────▼──────────────────────────────────┐
│  Serveur GEDv1 (WAMP / FrankenPHP futur)                     │
│  ├── PHP Slim — API + UI                                     │
│  ├── MySQL — métadonnées                                     │
│  ├── storage/ — fichiers (WORM futur)                        │
│  ├── Tesseract — OCR local                                   │
│  ├── Ollama — IA locale                                      │
│  ├── OnlyOffice Docker — édition                             │
│  ├── Qdrant — recherche sémantique (optionnel)               │
│  └── ClamAV — scan uploads (futur)                           │
└──────────────────────────┬──────────────────────────────────┘
                           │ ODBC LAN
┌──────────────────────────▼──────────────────────────────────┐
│  WinBiz (FoxPro local) — lecture seule                       │
└─────────────────────────────────────────────────────────────┘
```

### 8.3 Ce qu'il faut éviter

- Envoyer le **contenu document** vers Claude API en production (fuite données mandants).
- Héberger sur **cloud public** non souverain (AWS US, Azure global).
- Dépendre d'un **SaaS GED** (M-Files Cloud, DocuWare Cloud) pour le stockage primaire.
- OCR cloud (Google Vision, Azure DI) sans anonymisation — préférer Tesseract.

### 8.4 Ce qui est acceptable

- **Ollama** local pour classification et extraction.
- **TSA / horodatage** sur serveur suisse si archivage légal (données hash seulement).
- **SwissSign** ou équivalent pour e-signature (flux document contrôlé).
- **Sauvegarde** vers NAS local ou backup chiffré offsite suisse (pas le document « actif »).

---

## 9. Recommandations roadmap — lots de travail

### Lot 1 — Stabilisation opérationnelle (2–4 semaines) ⭐ PRIORITÉ

| Tâche | Livrable |
|-------|----------|
| Corriger P0 UI/OCR | 4 fixes `CORRECTIONS_PRIORITAIRES.md` |
| `run-tests.bat` vert | Smoke migration + tests régression P0 |
| Config WAMP GEDv1 | URL stable `http://localhost/gedv1` |
| `.env.example` | Template sans secrets |

### Lot 2 — WinBiz + factures (4–6 semaines)

| Tâche | Livrable |
|-------|----------|
| Valider ODBC WinBiz 32-bit | Rapport test + health check |
| `ConnectorInterface` formalisé | `WinBizConnector` conforme |
| Brancher `apps/invoices/` | UI rapprochement facture ↔ BL |
| Workflow seed « facture fournisseur » | Template REDX Factures simplifié |

### Lot 3 — Conformité archivage (2–3 mois)

| Tâche | Livrable |
|-------|----------|
| `LegalArchiveService` | Scellement + flag immuable |
| `RetentionPolicyService` | Durées Olico par type document |
| Export audit révision | PDF/JSON pour contrôle |

### Lot 4 — Apps métier (3–6 mois)

| Tâche | Livrable |
|-------|----------|
| `apps/mail/` opérationnel | IMAP sync + archivage |
| `apps/contracts/` | Échéances REDX Contrat |
| `PluginRegistry` | Chargement dynamique apps |

### Lot 5 — Modules REDX avancés (6–12 mois)

| Tâche | Livrable |
|-------|----------|
| `apps/smq/` | Quittance lecture ISO |
| `apps/hr/` | Dossier RH |
| Multi-mandant | Cloisonnement fiduciaire |

---

## 10. Références

| Ressource | URL |
|-----------|-----|
| RedX GED/ECM | https://www.red-x.net/fr/ged-ecm/ |
| RedX DocuShare Go | https://www.red-x.net/fr/sheets/solutions-fr/xerox-docushare-go-fr/ |
| Xerox DocuShare ECM | https://www.xerox.com/fr-ch/services/solutions-ecm |
| M-Files | https://www.m-files.com/fr/ |
| intusdata GED Suisse | https://www.intusdata.ch/fr/updates/faq-ged/ |
| Paperless-ngx vs Mayan 2026 | https://selfhostr.com/comparatifs/paperless-ngx-vs-mayan-vs-docspell-2026/ |
| LogicalDOC Community | https://github.com/logicaldoc/community |
| Doc GEDv1 interne | `docs/DELTA-REDX.md`, `docs/ARCHITECTURE.md` |

---

*Document produit le 2026-06-17 — session panorama GED. REDX = intégrateur RedX / M-Files, pas projet local.*
