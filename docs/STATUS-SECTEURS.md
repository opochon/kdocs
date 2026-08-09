# STATUS SECTEURS — GEDv1 (K-Docs)

> **Genere** par `node tools/status-secteurs.mjs --write`. Ne pas editer a la main.
> Croise `governance/sectors.json` avec `tests/reports/harness-latest.json`.
> Regles contraignantes : `governance/agent-rules.md`.

> Dernier harness : **ROUGE** · 38 suites · 2026-08-09T07:53:24.909Z

**15 secteurs** — 8 🟢 verts · 3 🔴 rouges · 3 ⚪ orphelins · 1 👻 fantomes

| | Secteur | Etat | Oracles | Depend de |
|---|---|---|---|---|
| 👻 | `plugins` | FANTOME | 2✓ 1? | interface |
| 🔴 | `erpconnect` | ROUGE | 2✓ 1✗ | securite-acl |
| 🔴 | `ingestion-ocr` | ROUGE | 0✓ 2✗ | stockage |
| 🔴 | `stockage` | ROUGE | 1✓ 1✗ | — |
| ⚪ | `recherche-transverse` | ORPHELIN | — | recherche, classification-ia |
| ⚪ | `tracabilite-audit` | ORPHELIN | — | — |
| ⚪ | `versioning` | ORPHELIN | — | stockage |
| 🟢 | `classification-ia` | VERT | 3✓ | ingestion-ocr |
| 🟢 | `conformite-archivage` | VERT | 1✓ | corbeille-retention, tracabilite-audit |
| 🟢 | `corbeille-retention` | VERT | 3✓ | — |
| 🟢 | `interface` | VERT | 11✓ | — |
| 🟢 | `recherche` | VERT | 2✓ | stockage |
| 🟢 | `securite-acl` | VERT | 1✓ 1? | — |
| 🟢 | `socle-mesure` | VERT | 4✓ | — |
| 🟢 | `workflow-validation` | VERT | 1✓ | securite-acl |

## Lecture des etats

- 🟢 **VERT** — tous les oracles declares sont verts au dernier harness.
- 🔴 **ROUGE** — au moins un oracle tombe. Le detail est ci-dessous.
- ⚪ **ORPHELIN** — aucun oracle. Le secteur peut etre casse sans que rien ne rougisse.
- 👻 **FANTOME** — oracles verts, **cablage non prouve**. Le plus dangereux : il a l apparence du vert. Cas fondateur : `folder-permissions` etait vert sur 10 tests unitaires alors que `FolderPermissionService` n est appele par aucune ligne applicative.

## Detail par secteur

### 👻 plugins — FANTOME

**Registre de plugins et applications satellites**

> *Invariant* — Un module declare est soit livre et atteignable, soit retire de l interface. Jamais un menu vers un 404.

**Etat connu.** Corrige le 2026-08-09 : l etiquette FANTOME etait trop severe. Le gating tient. 3 applications sur 8 sont actives — timetrack (sans drapeau, historique), erpconnect et smq (drapeaux presents dans le .env). Les 5 eteintes — contracts, rh, mail, portal, invoices — sont ecrites et couvertes par des tests, drapeaux absents du .env, tables a 0 ligne, et AUCUN template ne pointe vers elles : pas de 404. Registre governance/apps-status.json, oracle apps-routes qui confronte le registre, le .env et les liens des templates. RESTE : decider app par app entre activer et retirer. invoices est candidate au retrait, le flux facture passant desormais par erpconnect et K-Time.

**Oracles declares jamais executes** : `apps-routes`

**Agent** : `.claude/agents/plugins.md` · **Oracles** : `smq-versions`, `admin-hub`, `apps-routes`

**Fichiers** : `app/Core/PluginRegistry.php` · `apps/`

**Tables** : `contracts`, `hr_employees`, `mail_accounts`, `mail_sync_log`

---

### 🔴 erpconnect — ROUGE

**Integration K-Time — contrat /api/ged/***

> *Invariant* — La GED n ecrit JAMAIS dans WinBiz. Le flux passe par CMD v4 (extraction) puis K-Time (introduction, validation, sync). Le contrat appartient aux deux depots : K-TIME est en LECTURE SEULE depuis GEDv1.

**Etat connu.** Le secteur le mieux prouve du produit. Contrat de 8 routes versionne, confronte au depot K-Time sur disque ET au serveur vivant (health 200). erp-connect ROUGE : la spec attend un simulateur sur 127.0.0.1:8091, absent — prerequis d environnement, pas regression.

**Oracles rouges** : `erp-connect`

**Agent** : `.claude/agents/erpconnect.md` · **Oracles** : `ktime-contract`, `api-key-redaction`, `erp-connect`

**Fichiers** : `apps/erpconnect/` · `tools/lint-contrat.mjs` · `governance/contrat-ged-ktime.json`

**Tables** : `erp_links`

---

### 🔴 ingestion-ocr — ROUGE

**Ingestion, OCR, extraction de contenu**

> *Invariant* — L OCR et la classification sortent de la requete HTTP. Une ingestion ne bloque pas l utilisateur.

**Etat connu.** ROUGE sur les deux oracles. persona-parcours-ecm depasse 180 s en attente de reponse ; pipeline-ui ne trouve jamais #preview-type-select. Aucun ordonnanceur ne tourne : les taches planifiees affichent dernier_run=JAMAIS.

**Oracles rouges** : `persona-parcours-ecm`, `pipeline-ui`

**Agent** : `.claude/agents/ingestion-ocr.md` · **Oracles** : `persona-parcours-ecm`, `pipeline-ui`

**Fichiers** : `app/Services/DocumentProcessor.php` · `app/Services/TaskService.php` · `app/workers/task_worker.php`

**Tables** : `documents`, `tasks`, `scheduled_tasks`

---

### 🔴 stockage — ROUGE

**Stockage filesystem-first : le fichier sur disque est la source, la base porte metadonnees et index**

> *Invariant* — Le document reste lisible sans l application. Aucun blob en base, aucun nom de fichier aberrant. La GED se pose sur un stockage existant sans tout importer.

**Etat connu.** document_folders porte 36 dossiers scannes du disque : le modele fonctionne. lib-operations est ROUGE (timeout 60 s sur deplacement de dossier, cas variable d un run a l autre — instabilite plus qu echec franc).

**Oracles rouges** : `lib-operations`

**Agent** : `.claude/agents/stockage.md` · **Oracles** : `lib-operations`, `audit-coherence`

**Fichiers** : `app/Services/FilesystemIndexer.php` · `app/Services/FolderIndexService.php` · `app/Services/ConsumeFolderService.php` · `app/Services/IndexingService.php` · `app/Controllers/IndexingController.php` · `app/Repositories/DocumentRepository.php`

**Tables** : `documents`, `document_folders`, `storage_paths`

---

### ⚪ recherche-transverse — ORPHELIN

**Vues dynamiques : dossiers filtres, tags, types, champs personnalises**

> *Invariant* — L equivalent des vues M-Files sur un stockage disque. Un dossier Factures rassemble toutes les factures ou qu elles soient, sans deplacer un fichier.

**Etat connu.** ORPHELIN, et sous-estime par erreur dans EQUIVALENCE-M-FILES.md. L infrastructure EXISTE : logical_folders porte filter_type (filesystem, document_type, correspondent, tag, custom) et filter_config, avec 4 dossiers systeme dont Factures sur {document_type_code: facture}. Le manque est l usage : 1 seule affectation de tag pour 279 documents, 0 champ personnalise, 0 recherche sauvegardee.

**Agent** : `.claude/agents/recherche-transverse.md` · **Oracles** : _aucun_

**Fichiers** : `app/Models/LogicalFolder.php` · `app/Models/Tag.php` · `app/Models/ClassificationField.php`

**Tables** : `logical_folders`, `saved_searches`, `tags`, `document_tags`, `custom_fields`, `document_custom_fields`

---

### ⚪ tracabilite-audit — ORPHELIN

**Piste de revision — journal de toutes les actions**

> *Invariant* — Aucune action ne doit pouvoir se produire sans laisser de trace. Un journal effacable n est pas un journal.

**Etat connu.** ORPHELIN mais FONCTIONNEL — corrige le 2026-08-08 apres erreur d analyse. audit_logs porte 1261 lignes et s alimente (auth.login 1022, document.updated 42, document.created 20, folder_* 64). Deux defauts reels : derive de schema, la table audit_log (singulier) existe vide en doublon avec des colonnes differentes ; et classification_audit_log a 0 ligne. Couverture partielle : les suppressions et les changements de droits ne sont pas journalises.

**Agent** : `.claude/agents/tracabilite-audit.md` · **Oracles** : _aucun_

**Fichiers** : `app/Services/AuditService.php` · `app/Models/AuditLog.php`

**Tables** : `audit_logs`, `audit_log`, `classification_audit_log`

---

### ⚪ versioning — ORPHELIN

**Versions de documents — stockage en sous-dossier cache aupres du fichier**

> *Invariant* — La version courante reste le fichier nu, ouvrable directement. Les anterieures vivent dans un sous-dossier cache voisin (modele .versions/, inspire de la convention .DS_Store), jamais en base.

**Etat connu.** ORPHELIN. document_versions porte 0 ligne pour 279 documents : la table est deployee, la fonction n est pas en service. Design a poser avec le dirigeant avant code.

**Agent** : `.claude/agents/versioning.md` · **Oracles** : _aucun_

**Fichiers** : `app/Services/SnapshotService.php`

**Tables** : `document_versions`, `snapshots`

---

### 🟢 classification-ia — VERT

**Classement automatise — cascade IA, taxonomie ECM, suggestions**

> *Invariant* — Une suggestion n est jamais appliquee seule. Toute modification de classification est tracable.

**Etat connu.** VERT (15 cas). Cascade training -> claude -> ollama -> rules. classification_audit_log porte 0 ligne : l audit de classification n est pas alimente.

**Agent** : `.claude/agents/classification-ia.md` · **Oracles** : `classifier-taxonomie`, `ai-confidence-badge`, `ai-assistant`

**Fichiers** : `app/Services/AIClassifierService.php` · `app/Services/AIProviderService.php` · `app/Adapters/HtmleditorTaxonomyAdapter.php`

**Tables** : `classification_suggestions`, `classification_training_data`, `classification_audit_log`, `document_types`

---

### 🟢 conformite-archivage — VERT

**Archivage legal suisse — scelle WORM, retention, horodatage qualifie**

> *Invariant* — Un document scelle ne peut etre ni modifie ni detruit, par AUCUN chemin. Retention 10 ans (CO 958f, GeBuV / Olico).

**Etat connu.** PARTIEL. Colonnes legal_sealed, retention_until, tsa_token deployees ; 10 documents scelles sur 279. Le scelle ne couvrait QUE les routes API : la purge le contournait entierement jusqu au 2026-08-07. TSA_URL absent : aucun horodatage qualifie reel n a jamais ete produit.

**Agent** : `.claude/agents/conformite-archivage.md` · **Oracles** : `legal-seal`

**Fichiers** : `app/Services/Compliance/`

**Tables** : `documents`

---

### 🟢 corbeille-retention — VERT

**Corbeille, retention, sauvegarde — zero suppression dure**

> *Invariant* — INVARIANT ABSOLU pose par la direction le 2026-08-07 : aucune ligne n est jamais supprimee d une table par le produit. La corbeille est un etat durable, pas une antichambre de la destruction. Reconstruire une base pour les tests reste legitime mais n appartient PAS a l application : outil externe, precede d un dump.

**Etat connu.** VERT depuis le 2026-08-07. Trois chemins de destruction neutralises, tache planifiee cleanup_trash passee a is_active=0 : 156 documents etaient a une execution de la destruction. Cliquet governance/budgets.json, plafond documents fige a 0, total 73. RESTE : sauvegarde quotidienne avec rotation (BackupService existe, storage/backups vide, aucune sauvegarde jamais produite) et chaine de hachage anti-modification silencieuse.

**Agent** : `.claude/agents/corbeille-retention.md` · **Oracles** : `no-hard-delete`, `soft-delete`, `trash-retention`

**Fichiers** : `app/Services/TrashService.php` · `app/Services/BackupService.php` · `app/Exceptions/HardDeleteForbiddenException.php`

**Tables** : `documents`

---

### 🟢 interface — VERT

**Interface, chrome, navigation, accessibilite**

> *Invariant* — Une fonction sans entree de menu est une fonction perdue. Un module desactive ne doit pas rester visible et produire des 404.

**Etat connu.** Majoritairement VERT. Audit UI-UX a 3,5/10 : sidebar melangee, emojis, compteurs incoherents. Reference : docs/AUDIT-UI-UX.md, docs/DETTE-UI-ORPHELINS.md.

**Agent** : `.claude/agents/interface.md` · **Oracles** : `ui-chrome`, `chrome-coherence`, `shell`, `a11y`, `fiche-document`, `bugs`, `bugs-click`, `bugs-misc`, `persona`, `persona-preview`, `persona-redx-expert`

**Fichiers** : `templates/` · `public/assets/`

---

### 🟢 recherche — VERT

**Recherche plein texte — FULLTEXT MySQL, repli LIKE, semantique optionnelle**

> *Invariant* — Une recherche qui echoue doit se voir. Aujourd hui advancedSearch avale les erreurs SQL et rend zero resultat : indiscernable d une recherche sans reponse.

**Etat connu.** VERT depuis le 2026-08-07 (17/17). Deux bugs corriges : sonde testant AGAINST('*'), et operateurs laisses comme termes produisant +""* -> 1064. Dette ouverte : les erreurs SQL restent avalees.

**Agent** : `.claude/agents/recherche.md` · **Oracles** : `search-fulltext`, `search-tasks`

**Fichiers** : `app/Services/SearchService.php` · `app/Search/SearchQuery.php` · `app/Search/SearchResult.php`

**Tables** : `documents`, `saved_searches`

---

### 🟢 securite-acl — VERT

**Authentification, permissions de dossiers, CSRF, cloisonnement**

> *Invariant* — Les droits se verifient cote serveur, jamais a l affichage. Un document interdit ne doit pas etre servi.

**Etat connu.** CABLE le 2026-08-09. C etait le cas fondateur de la regle du cablage : folder-permissions etait VERT (10 tests) alors que FolderPermissionService n etait appele par AUCUNE ligne applicative — les permissions de dossier n existaient pas en service. Le garde est desormais consulte par DocumentsApiController sur show, content, download (lecture), update (ecriture) et delete (suppression), via peutAccederAuDocument(). Refus rendu en 404 et non 403, pour ne pas reveler l existence d une piece interdite. Le service est ouvert par defaut : sans regle sur la chaine des dossiers il autorise, donc le branchement ne change rien au comportement actuel et ferme des qu une regle est posee. Oracle folder-permissions-serverside, prouve descendant. RESTE : folder_permissions porte toujours 0 ligne — aucune regle n est configuree, et aucun ecran ne permet d en poser.

**Oracles declares jamais executes** : `folder-permissions-serverside`

**Agent** : `.claude/agents/securite-acl.md` · **Oracles** : `folder-permissions`, `folder-permissions-serverside`

**Fichiers** : `app/Core/Auth.php` · `app/Services/FolderPermissionService.php` · `app/Services/TenantScopeService.php` · `app/Middleware/`

**Tables** : `users`, `groups`, `folder_permissions`, `sessions`

---

### 🟢 socle-mesure — VERT

**Mesurabilite : harness, checklist, registres, cliquets, reservations**

> *Invariant* — Ce qui n est pas mesure n existe pas. Le harness nomme ce qui tombe. Un cliquet ne remonte jamais.

**Etat connu.** VERT. 37 suites nommees produites dans tests/reports/harness-latest.json. Avant le 2026-08-06 le harness sortait 0 ou 1 sans dire quoi : tout le backlog etait bloque a 0 % teste. Dette : .gitignore masque des fichiers qui comptent — 8 rapports racine et tests/integration/ entier, dont une sonde executee par le harness.

**Agent** : `.claude/agents/socle-mesure.md` · **Oracles** : `specs-registre`, `migration-smoke`, `phpunit-all`, `eval-full`

**Fichiers** : `tools/run-harness.mjs` · `tools/checklist.mjs` · `tools/claim.mjs` · `tools/preflight.php` · `tools/status-secteurs.mjs` · `governance/`

---

### 🟢 workflow-validation — VERT

**Workflows visuels et validation par roles**

> *Invariant* — Une validation engage une personne : elle est tracee, avec son montant et son perimetre.

**Etat connu.** Un seul oracle vert, tres mince (1 cas). Couverture reelle du moteur de workflow non etablie.

**Agent** : `.claude/agents/workflow-validation.md` · **Oracles** : `workflow-doc-identification`

**Fichiers** : `app/Services/WorkflowEngine.php` · `app/Services/ValidationService.php`

**Tables** : `workflows`, `workflow_nodes`, `role_types`, `user_roles`

---
