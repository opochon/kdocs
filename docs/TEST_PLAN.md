# Plan de Test Complet - K-Docs

## Instructions
Naviguez dans l'application écran par écran et testez chaque fonction/bouton. Les logs seront automatiquement capturés dans `c:\wamp64\www\kdocs\.cursor\debug.log`.

## Écrans à Tester

### 1. Authentification
- [ ] Page de login (`/login`)
- [ ] Connexion avec identifiants valides
- [ ] Connexion avec identifiants invalides
- [ ] Déconnexion (`/logout`)

### 2. Dashboard
- [ ] Page d'accueil (`/` ou `/dashboard`)
- [ ] Vérifier l'affichage des statistiques
- [ ] Vérifier les graphiques (documents par mois, par type, etc.)
- [ ] Vérifier la liste des documents récents

### 3. Documents
- [ ] Liste des documents (`/documents`)
- [ ] Filtrage par dossier filesystem (cliquer sur "Racine" et sous-dossiers)
- [ ] Filtrage par correspondant
- [ ] Filtrage par tag
- [ ] Filtrage par type de document
- [ ] Recherche simple
- [ ] Recherche avancée
- [ ] Tri (par titre, date, etc.)
- [ ] Basculement grille/liste/tableau
- [ ] Sélection multiple de documents
- [ ] Actions groupées (supprimer, ajouter tag, etc.)
- [ ] Upload de document (`/documents/upload`)
- [ ] Visualisation d'un document (`/documents/{id}`)
- [ ] Édition d'un document (`/documents/{id}/edit`)
- [ ] Suppression d'un document (`/documents/{id}/delete`)
- [ ] Restauration d'un document (`/documents/{id}/restore`)
- [ ] Téléchargement d'un document (`/documents/{id}/download`)
- [ ] Partage d'un document (`/documents/{id}/share`)
- [ ] Historique d'un document (`/documents/{id}/history`)

### 4. Administration - Correspondants
- [ ] Liste des correspondants (`/admin/correspondents`)
- [ ] Création d'un correspondant (`/admin/correspondents/create`)
- [ ] Édition d'un correspondant (`/admin/correspondents/{id}/edit`)
- [ ] Suppression d'un correspondant (`/admin/correspondents/{id}/delete`)

### 5. Administration - Tags
- [ ] Liste des tags (`/admin/tags`)
- [ ] Création d'un tag (`/admin/tags/create`)
- [ ] Édition d'un tag (`/admin/tags/{id}/edit`)
- [ ] Suppression d'un tag (`/admin/tags/{id}/delete`)

### 6. Administration - Types de Documents
- [ ] Liste des types (`/admin/document-types`)
- [ ] Création d'un type (`/admin/document-types/create`)
- [ ] Édition d'un type (`/admin/document-types/{id}/edit`)
- [ ] Suppression d'un type (`/admin/document-types/{id}/delete`)

### 7. Administration - Custom Fields
- [ ] Liste des champs personnalisés (`/admin/custom-fields`)
- [ ] Création d'un champ (`/admin/custom-fields/create`)
- [ ] Édition d'un champ (`/admin/custom-fields/{id}/edit`)
- [ ] Suppression d'un champ (`/admin/custom-fields/{id}/delete`)

### 8. Administration - Storage Paths
- [ ] Liste des chemins (`/admin/storage-paths`)
- [ ] Création d'un chemin (`/admin/storage-paths/create`)
- [ ] Édition d'un chemin (`/admin/storage-paths/{id}/edit`)
- [ ] Suppression d'un chemin (`/admin/storage-paths/{id}/delete`)

### 9. Administration - Workflows
- [ ] Liste des workflows (`/admin/workflows`)
- [ ] Création d'un workflow (`/admin/workflows/create`)
- [ ] Édition d'un workflow (`/admin/workflows/{id}/edit`)
- [ ] Suppression d'un workflow (`/admin/workflows/{id}/delete`)

### 10. Administration - Webhooks
- [ ] Liste des webhooks (`/admin/webhooks`)
- [ ] Création d'un webhook (`/admin/webhooks/create`)
- [ ] Édition d'un webhook (`/admin/webhooks/{id}/edit`)
- [ ] Suppression d'un webhook (`/admin/webhooks/{id}/delete`)
- [ ] Voir les logs d'un webhook (`/admin/webhooks/{id}/logs`)

### 11. Administration - Audit Logs
- [ ] Liste des logs d'audit (`/admin/audit-logs`)
- [ ] Filtrage des logs par type/date/utilisateur

### 12. Administration - Utilisateurs
- [ ] Liste des utilisateurs (`/admin/users`)
- [ ] Création d'un utilisateur (`/admin/users/create`)
- [ ] Édition d'un utilisateur (`/admin/users/{id}/edit`)
- [ ] Suppression d'un utilisateur (`/admin/users/{id}/delete`)

### 13. Administration - Paramètres
- [ ] Page des paramètres (`/admin/settings`)
- [ ] Modification des paramètres de stockage
- [ ] Modification des paramètres OCR
- [ ] Modification des paramètres AI
- [ ] Sauvegarde des paramètres (`/admin/settings/save`)

### 14. Administration - Mail Accounts
- [ ] Liste des comptes mail (`/admin/mail-accounts`)
- [ ] Création d'un compte (`/admin/mail-accounts/create`)
- [ ] Édition d'un compte (`/admin/mail-accounts/{id}/edit`)
- [ ] Test de connexion (`/admin/mail-accounts/{id}/test`)
- [ ] Traitement manuel (`/admin/mail-accounts/{id}/process`)
- [ ] Suppression d'un compte (`/admin/mail-accounts/{id}/delete`)

### 15. Administration - Scheduled Tasks
- [ ] Liste des tâches planifiées (`/admin/scheduled-tasks`)
- [ ] Exécution manuelle d'une tâche (`/admin/scheduled-tasks/{id}/run`)
- [ ] Traitement de la file d'attente (`/admin/scheduled-tasks/process-queue`)

### 16. Administration - Export/Import
- [ ] Page d'export/import (`/admin/export-import`)
- [ ] Export de documents (`/admin/export-import/export-documents`)
- [ ] Import de documents (`/admin/export-import/import-documents`)

### 17. Tâches
- [ ] Liste des tâches (`/tasks`)
- [ ] Création d'une tâche (`/tasks/create`)
- [ ] Modification du statut d'une tâche (`/tasks/{id}/status`)

### 18. API REST (via navigateur ou outil de test)
- [ ] GET `/api/documents`
- [ ] GET `/api/documents/{id}`
- [ ] POST `/api/documents`
- [ ] PUT `/api/documents/{id}`
- [ ] DELETE `/api/documents/{id}`
- [ ] GET `/api/tags`
- [ ] POST `/api/tags`
- [ ] GET `/api/correspondents`
- [ ] POST `/api/correspondents`
- [ ] POST `/api/documents/bulk-action`
- [ ] POST `/api/documents/upload`
- [ ] GET `/api/saved-searches`
- [ ] POST `/api/saved-searches`
- [ ] POST `/api/scanner/scan`

## Notes
- Testez chaque bouton, lien et formulaire
- Notez toute erreur JavaScript dans la console du navigateur
- Notez toute erreur PHP affichée à l'écran
- Les logs seront automatiquement capturés dans le fichier de log
