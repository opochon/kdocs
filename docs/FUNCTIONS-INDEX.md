# GEDv1 — Index des fonctions

> Inventaire généré lors de la migration — 2026-06-17.
> Périmètre : `app/`, `apps/`, `connectors/`.

## Statistiques

| Métrique | Valeur |
|----------|--------|
| Classes PHP | 165 |
| Méthodes (public/protected/private) | ~1120 |
| Méthodes publiques | ~730 |
| Contrôleurs web | 28 |
| Contrôleurs API | ~40 |
| Services | ~55 |
| Modèles | ~30 |
| Nœuds workflow | ~20 |
| Tests PHPUnit | 20 fichiers Unit + Feature |

---

## Core (`app/Core/`)

| Classe | Méthodes publiques clés |
|--------|-------------------------|
| `App` | `create()`, `getInstance()` |
| `Config` | `load()`, `get()`, `has()`, `all()`, `baseUrl()`, `basePath()` |
| `Database` | `getInstance()` |
| `Auth` | `attempt()`, `createSession()`, `getUserFromSession()`, `destroySession()`, `isWeakPassword()` |
| `Cache` | `get()`, `set()`, `has()`, `delete()`, `remember()`, `cleanup()`, `stats()` |
| `CSRF` | `generateToken()`, `validateToken()`, `field()`, `metaTag()` |
| `Validator` | `make()`, `validate()`, `errors()`, `validated()` |
| `Migrations` | `migrate()`, `rollback()`, `status()`, `create()` |
| `ErrorTracker` | `init()`, `capture()`, `handleException()`, `stats()` |
| `ConfigValidator` | `validate()`, `check()`, `getErrors()` |
| `DebugLogger` | `log()`, `logException()` |

---

## Middleware (`app/Middleware/`)

| Classe | Méthode |
|--------|---------|
| `AuthMiddleware` | `process()` |
| `CSRFMiddleware` | `process()` |
| `RateLimitMiddleware` | `process()` |
| `AutoIndexMiddleware` | `process()` |
| `ErrorHandlerMiddleware` | `process()` |
| `PermissionMiddleware` | `process()` |

---

## Contrôleurs Web (`app/Controllers/`)

| Contrôleur | Méthodes publiques |
|------------|-------------------|
| `AuthController` | `showLogin`, `login`, `logout` |
| `DashboardController` | `index` |
| `DocumentsController` | `index`, `upload`, `show`, `download`, `view`, `thumbnail`, `onlyofficeEdit`, `edit`, `delete`, `restore`, `bulkAction`, `scanFilesystem`, `apiUpload`, `share`, `history`, `listSavedSearches`, `saveSearch`, `listNotes`, `addNote` |
| `AdminController` | `index`, `users`, `settings`, `diagnostic`, `apiUsage` |
| `SettingsController` | `index`, `save` |
| `CorrespondentsController` | `index`, `showForm`, `save`, `delete`, `search` |
| `TagsController` | `index`, `showForm`, `save`, `delete` |
| `DocumentTypesController` | `index`, `showForm`, `save`, `delete` |
| `CustomFieldsController` | `index`, `showForm`, `save`, `delete` |
| `StoragePathsController` | `index`, `showForm`, `save`, `delete` |
| `WorkflowsController` | `index`, `showForm`, `save`, `delete` |
| `WorkflowDesignerPageController` | `newDesigner`, `designer` |
| `WorkflowDesignerController` | `list`, `get`, `create`, `update`, `delete`, `toggleEnabled` |
| `WorkflowApprovalController` | `showApprovalPage`, `processApproval` |
| `WebhooksController` | `index`, `showForm`, `logs`, `save`, `delete`, `test` |
| `AuditLogsController` | `index` |
| `UsersController` | `index`, `showForm`, `save`, `delete` |
| `RolesController` | `index`, `showAssignForm`, `assign`, `remove` |
| `UserGroupsController` | `index`, `showForm`, `save`, `delete`, `apiIndex`, `apiShow` |
| `ExportController` | `index`, `exportDocuments`, `exportMetadata`, `import` |
| `MailAccountsController` | `index`, `showForm`, `save`, `testConnection`, `process`, `delete` |
| `ScheduledTasksController` | `index`, `run`, `processQueue` |
| `ConsumeController` | `index`, `documentCard`, `scan`, `rescan`, `validate` |
| `ClassificationFieldsController` | `index`, `showForm`, `save`, `delete` |
| `IndexingController` | `index`, `status`, `start`, `stop`, `logs`, `saveSettings`, `worker` |
| `ChatController` | `index` |
| `MyTasksController` | `index`, `apiIndex`, `apiCounts`, `apiSummary` |
| `TasksController` | `index`, `showCreate`, `create`, `updateStatus` |

### Admin (`app/Controllers/Admin/`)

| Contrôleur | Méthodes |
|------------|----------|
| `AttributionRulesController` | `index`, `editor`, `logs` |
| `SnapshotsController` | `index`, `compare`, `show`, `create`, `delete`, `restore` |

---

## Contrôleurs API (`app/Controllers/Api/`)

| Contrôleur | Endpoints / méthodes principales |
|------------|----------------------------------|
| `DocumentsApiController` | CRUD, `classifyWithAI`, `analyzeWithAI`, `triggerOcr`, tags, type, correspondent, fields |
| `OnlyOfficeApiController` | `getConfig`, `download`, `saveCallback`, `status`, `publicDownload`, `publicCallback` |
| `ValidationApiController` | `getPending`, `submit`, `approve`, `reject`, `validate`, `getHistory`, `getStatistics` |
| `SearchApiController` | `ask`, `quick`, `reference`, `summary` |
| `SemanticSearchApiController` | `search`, `similar`, `indexDocument`, `sync`, `stats` |
| `EmbeddingsApiController` | `status`, `sync`, `semanticSearch`, `hybridSearch`, `embedDocument` |
| `FoldersApiController` | `getTree`, `getDocuments`, `renameFolder`, `moveFolder`, `deleteFolder`, crawl/index |
| `WorkflowApiController` | `getNodeCatalog`, `getNodeConfig`, `getOptions` |
| `AttributionRulesApiController` | CRUD, `test`, `processDocument`, `processBatch`, `logs` |
| `ClassificationSuggestionsApiController` | `generate`, `apply`, `ignore`, `listPending`, `stats` |
| `InvoiceLineItemsApiController` | CRUD lignes, `extract`, `extractionHistory` |
| `ExtractionApiController` | templates, `extractDocument`, `confirmValue`, `correctValue` |
| `SnapshotsApiController` | CRUD snapshots, `compare`, `restore`, `export` |
| `DocumentVersionsApiController` | `index`, `create`, `diff`, `restore`, `download` |
| `MSGImportApiController` | `import`, `getAttachments`, `getThread` |
| `NotificationsApiController` | `index`, `unread`, `count`, `markRead` |
| `ChatApiController` | conversations CRUD, `sendMessage` |
| `UserNotesApiController` | notes, threads, `markComplete` |
| `CorrespondentsApiController` | CRUD |
| `TagsApiController` | CRUD |
| `AIStatusApiController` | `status`, `test`, `refresh` |
| `ClassificationAuditApiController` | `documentHistory`, `globalHistory`, `revert`, `compare` |
| `ConsumptionApiController` | `consume`, `consumeBatch` |
| `EmailIngestionApiController` | ingestion email |
| `FolderActionsApiController` | actions dossiers |

---

## Services (`app/Services/`) — sélection

| Service | Méthodes publiques clés |
|---------|-------------------------|
| `DocumentProcessor` | `process()`, `processDocument()`, `processPendingDocuments()`, `reprocessAll()` |
| `OCRService` | `extractText()` |
| `ThumbnailGenerator` | `generate()`, `regenerateMissing()`, `getAvailableTools()` |
| `SearchService` | `search()`, `advancedSearch()` |
| `VectorSearchService` | `isAvailable()`, index/search Qdrant |
| `EmbeddingService` | génération embeddings |
| `AIProviderService` | `getBestProvider()`, `classifyDocument()`, `extractData()`, `summarize()` |
| `AIClassifierService` | `classify()`, `classifyWithFile()`, `applySuggestions()` |
| `ClaudeService` | `sendMessage()`, `sendMessageWithFile()`, `extractText()` |
| `OnlyOfficeService` | `generateConfig()`, `verifyToken()`, `testConnectivity()` |
| `ValidationService` | `submitForApproval()`, `validate()`, `getPendingForUser()` |
| `WorkflowEngine` | exécution workflows |
| `MatchingService` | rapprochement factures WinBiz |
| `MSGImportService` | `import()`, `importWithAttachments()`, `getThreadDocuments()` |
| `EmailIngestionService` | ingestion IMAP |
| `FilesystemIndexer` | indexation disque |
| `ConsumeFolderService` | scan dossier consume |
| `SnapshotService` | snapshots métadonnées |
| `BackupService` | `create()`, `restore()`, `list()` |
| `WebhookService` | `trigger()`, `testWebhook()` |
| `TrashService` | corbeille |
| `AttributionService` | `process()`, `processBatch()` |
| `ClassificationLearningService` | suggestions ML |
| `InvoiceLineItemExtractor` | extraction lignes facture |

---

## Modèles (`app/Models/`)

Classes avec API statique : `Document`, `DocumentVersion`, `Snapshot`, `User`, `UserGroup`, `Role`, `Tag`, `Task`, `Webhook`, `AuditLog`, `DocumentNote`, `StoragePath`, `CustomField`, `SavedSearch`, `MailAccount`, `MailRule`, `LogicalFolder`, `ScheduledTask`, `Workflow`, `WorkflowDefinition`, `WorkflowNode`, `WorkflowConnection`, `WorkflowExecution`, `Setting`, `ClassificationField`, `ExtractionTemplate`, `AttributionRule`, `ClassificationSuggestion`, `InvoiceLineItem`, `ClassificationFieldOption`, `ClassificationTrainingData`, `ClassificationAuditLog`.

---

## Workflow (`app/Workflow/`)

### Nœuds (pattern `execute()`, `getConfigSchema()`)

**Triggers** : `UploadTrigger`, `ScanTrigger`, `ManualTrigger`, `DocumentAddedTrigger`, `TagAddedTrigger`, `ValidationStatusChangedTrigger`

**Actions** : `AddTagAction`, `AssignUserAction`, `AssignToGroupAction`, `SendEmailAction`, `WebhookAction`, `CreateApprovalAction`, `RequestApprovalAction`, `SetValidationStatusAction`

**Conditions** : `TagCondition`, `FieldCondition`, `CorrespondentCondition`, `CategoryCondition`, `AmountCondition`

**Processing** : `OcrProcessor`, `ClassifyProcessor`, `AiExtractProcessor`

**Waits/Timers** : `ApprovalWait`, `DelayTimer`

**Infrastructure** : `NodeExecutorFactory`, `ContextBag`, `ExecutionResult`, `AbstractNodeExecutor`

---

## Apps satellites (`apps/`)

### timetrack (implémenté partiellement)

| Classe | Méthodes |
|--------|----------|
| `DashboardController` | `index` |
| `EntryController` | `index`, `store`, `quickCreate`, `parsePreview`, `update`, `delete` |
| `TimerController` | `status`, `start`, `pause`, `resume`, `stop`, `cancel` |
| `QuickCodeParser` | `parse`, `validate`, `preview` |
| `Client`, `Project`, `Entry`, `Timer`, `Supply` | CRUD / métier |

### invoices / mail

Routes déclarées dans `routes.php` — **contrôleurs absents** (stubs).

---

## Connecteurs (`connectors/`)

| Classe | Méthodes publiques |
|--------|-------------------|
| `WinBizConnector` | `connect`, `disconnect`, `searchArticles`, `getArticle`, `searchClients`, `getBonLivraison`, `getBonsLivraison`, `getFichesTravail`, `testConnection` |

---

## Workers CLI (`app/workers/`)

| Script | Rôle |
|--------|------|
| `queue_worker.php` | Traitement file d'attente |
| `task_worker.php` | Tâches planifiées |
| `folder_crawler.php` | Crawl arborescence |
| `smart_indexer.php` | Indexation intelligente |
| `WorkflowWorker.php` | Exécution workflows async |

---

## Helpers globaux (`app/helpers.php`)

Fonctions procédurales utilitaires (chargées par `index.php`) — voir fichier source pour liste exacte.

---

## Endpoints HTTP (résumé par domaine)

| Domaine | Préfixe route |
|---------|---------------|
| Auth | `/login`, `/logout` |
| Documents web | `/documents/*` |
| Admin | `/admin/*` |
| API documents | `/api/documents/*` |
| API recherche | `/api/search/*`, `/api/semantic-search/*` |
| API dossiers | `/api/folders/*` |
| API workflows | `/api/workflows/*`, `/api/workflow/*` |
| API validation | `/api/validation/*` |
| OnlyOffice | `/api/onlyoffice/*` |
| K-Time | `/time/*` |
| Santé | `/health` |

Liste exhaustive : grep `->get(`, `->post(` dans `index.php` ou voir `docs/API.md`.

---
*Dernière mise à jour : 2026-06-17*
