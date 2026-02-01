# K-Docs Smoke Test Report

**Date**: 2026-01-28T12:24:39.258046
**URL**: http://localhost/kdocs

## Résumé

| Métrique | Valeur |
|----------|--------|
| Pages testées | 21 |
| ✅ Passées | 15 |
| ❌ Échouées | 6 |
| ⚠️ Warnings | 0 |

## Détail par page

### ✅ Login

- **URL**: `/login`
- **Temps de chargement**: 187ms
- **Screenshot**: `page__login_122446.png`

### ✅ Dashboard

- **URL**: `/`
- **Temps de chargement**: 108ms
- **Screenshot**: `page___122447.png`

### ❌ Documents

- **URL**: `/documents`
- **Temps de chargement**: 180ms
- **Screenshot**: `page__documents_122448.png`
- **Erreurs**:
  - Console SEVERE: http://localhost/kdocs/documents/48/thumbnail - Failed to load resource: the server responded with a status of 404 (Not Found)

### ✅ Recherche avancée

- **URL**: `/chat`
- **Temps de chargement**: 191ms
- **Screenshot**: `page__chat_122455.png`

### ❌ Indexation

- **URL**: `/indexation`
- **Temps de chargement**: 36ms
- **Screenshot**: `page__indexation_122457.png`
- **Erreurs**:
  - Console SEVERE: http://localhost/kdocs/indexation - Failed to load resource: the server responded with a status of 404 (Not Found)
  - Page contient: 'exception'
  - Page contient: '404 not found'

### ✅ Mes Tâches

- **URL**: `/tasks`
- **Temps de chargement**: 135ms
- **Screenshot**: `page__tasks_122458.png`

### ✅ Fichiers à valider

- **URL**: `/admin/consume`
- **Temps de chargement**: 171ms
- **Screenshot**: `page__admin_consume_122459.png`

### ✅ Étiquettes

- **URL**: `/admin/tags`
- **Temps de chargement**: 172ms
- **Screenshot**: `page__admin_tags_122501.png`

### ✅ Correspondants

- **URL**: `/admin/correspondents`
- **Temps de chargement**: 83ms
- **Screenshot**: `page__admin_correspondents_122513.png`

### ✅ Types de document

- **URL**: `/admin/document-types`
- **Temps de chargement**: 159ms
- **Screenshot**: `page__admin_document-types_122514.png`

### ✅ Champs personnalisés

- **URL**: `/admin/custom-fields`
- **Temps de chargement**: 153ms
- **Screenshot**: `page__admin_custom-fields_122515.png`

### ✅ Chemins de stockage

- **URL**: `/admin/storage-paths`
- **Temps de chargement**: 84ms
- **Screenshot**: `page__admin_storage-paths_122517.png`

### ✅ Workflows

- **URL**: `/admin/workflows`
- **Temps de chargement**: 657ms
- **Screenshot**: `page__admin_workflows_122518.png`

### ✅ Webhooks

- **URL**: `/admin/webhooks`
- **Temps de chargement**: 87ms
- **Screenshot**: `page__admin_webhooks_122519.png`

### ❌ Journaux

- **URL**: `/admin/logs`
- **Temps de chargement**: 34ms
- **Screenshot**: `page__admin_logs_122521.png`
- **Erreurs**:
  - Console SEVERE: http://localhost/kdocs/admin/logs - Failed to load resource: the server responded with a status of 404 (Not Found)
  - Page contient: 'exception'
  - Page contient: '404 not found'

### ❌ Export/Import

- **URL**: `/admin/export`
- **Temps de chargement**: 24ms
- **Screenshot**: `page__admin_export_122522.png`
- **Erreurs**:
  - Console SEVERE: http://localhost/kdocs/admin/export - Failed to load resource: the server responded with a status of 404 (Not Found)
  - Page contient: 'exception'
  - Page contient: '404 not found'

### ✅ Paramètres

- **URL**: `/admin/settings`
- **Temps de chargement**: 123ms
- **Screenshot**: `page__admin_settings_122523.png`

### ❌ Statistiques API

- **URL**: `/admin/api-stats`
- **Temps de chargement**: 32ms
- **Screenshot**: `page__admin_api-stats_122524.png`
- **Erreurs**:
  - Console SEVERE: http://localhost/kdocs/admin/api-stats - Failed to load resource: the server responded with a status of 404 (Not Found)
  - Page contient: 'exception'
  - Page contient: '404 not found'

### ✅ Utilisateurs

- **URL**: `/admin/users`
- **Temps de chargement**: 86ms
- **Screenshot**: `page__admin_users_122525.png`

### ❌ Groupes

- **URL**: `/admin/groups`
- **Temps de chargement**: 41ms
- **Screenshot**: `page__admin_groups_122526.png`
- **Erreurs**:
  - Console SEVERE: http://localhost/kdocs/admin/groups - Failed to load resource: the server responded with a status of 404 (Not Found)
  - Page contient: 'exception'
  - Page contient: '404 not found'

### ✅ Health Check

- **URL**: `/health`
- **Temps de chargement**: 124ms
- **Screenshot**: `page__health_122527.png`

## Tests API

- ✅ Health (`/health`)
- ✅ Documents API (`/api/documents?per_page=1`)
- ✅ Tags API (`/api/tags`)
- ✅ Correspondents API (`/api/correspondents`)
