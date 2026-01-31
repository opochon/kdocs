# Guide de Test Complet - K-Docs

## Instructions

1. Ouvrez votre navigateur et connectez-vous à l'application
2. Ouvrez la console du navigateur (F12) pour voir les erreurs JavaScript
3. Suivez cette liste page par page
4. Notez toutes les erreurs rencontrées
5. Les logs sont automatiquement enregistrés dans `.cursor/debug.log`

## Pages à Tester

### 1. Dashboard
- [ ] `/` - Page d'accueil
- [ ] `/dashboard` - Dashboard
- Vérifier : Statistiques, graphiques, documents récents

### 2. Documents
- [ ] `/documents` - Liste des documents
- [ ] `/documents/upload` - Upload de document
- [ ] `/documents/{id}` - Détail d'un document
- [ ] `/documents/{id}/edit` - Modifier un document
- [ ] `/documents/{id}/download` - Télécharger
- [ ] `/documents/{id}/view` - Visualiser
- [ ] `/documents/{id}/share` - Partager
- [ ] `/documents/{id}/history` - Historique
- Actions : Recherche, tri, pagination, sélection multiple, actions groupées

### 3. Chat IA / Recherche avancée
- [ ] `/chat` - Recherche avancée
- Tester : Recherche en langage naturel, résultats

### 4. Tâches
- [ ] `/tasks` - Liste des tâches
- [ ] `/tasks/create` - Créer une tâche
- Actions : Modifier statut

### 5. Administration - Utilisateurs
- [ ] `/admin/users` - Liste
- [ ] `/admin/users/create` - Créer
- [ ] `/admin/users/{id}/edit` - Modifier
- Actions : Supprimer

### 6. Administration - Paramètres
- [ ] `/admin/settings` - Paramètres système
- Tester : Sauvegarder chaque section (Stockage, OCR, IA, KDrive)

### 7. Administration - Correspondants
- [ ] `/admin/correspondents` - Liste
- [ ] `/admin/correspondents/create` - Créer
- [ ] `/admin/correspondents/{id}/edit` - Modifier
- Actions : Supprimer, recherche

### 8. Administration - Tags
- [ ] `/admin/tags` - Liste
- [ ] `/admin/tags/create` - Créer
- [ ] `/admin/tags/{id}/edit` - Modifier
- Actions : Supprimer, matching algorithms

### 9. Administration - Types de Document
- [ ] `/admin/document-types` - Liste
- [ ] `/admin/document-types/create` - Créer
- [ ] `/admin/document-types/{id}/edit` - Modifier
- Actions : Supprimer, permissions

### 10. Administration - Champs Personnalisés
- [ ] `/admin/custom-fields` - Liste
- [ ] `/admin/custom-fields/create` - Créer
- [ ] `/admin/custom-fields/{id}/edit` - Modifier
- Actions : Supprimer

### 11. Administration - Chemins de Stockage
- [ ] `/admin/storage-paths` - Liste
- [ ] `/admin/storage-paths/create` - Créer
- [ ] `/admin/storage-paths/{id}/edit` - Modifier
- Actions : Supprimer

### 12. Administration - Workflows
- [ ] `/admin/workflows` - Liste
- [ ] `/admin/workflows/new/designer` - Designer
- Actions : Créer, modifier, supprimer, designer

### 13. Administration - Webhooks
- [ ] `/admin/webhooks` - Liste
- [ ] `/admin/webhooks/create` - Créer
- [ ] `/admin/webhooks/{id}/edit` - Modifier
- [ ] `/admin/webhooks/{id}/logs` - Logs
- Actions : Tester, supprimer

### 14. Administration - Journaux d'Audit
- [ ] `/admin/audit-logs` - Liste
- Actions : Filtres

### 15. Administration - Export/Import
- [ ] `/admin/export-import` - Page
- Actions : Export documents, export metadata, import

### 16. Administration - Comptes Mail
- [ ] `/admin/mail-accounts` - Liste
- [ ] `/admin/mail-accounts/create` - Créer
- [ ] `/admin/mail-accounts/{id}/edit` - Modifier
- Actions : Tester connexion, traiter emails, supprimer

### 17. Administration - Tâches Planifiées
- [ ] `/admin/scheduled-tasks` - Liste
- Actions : Exécuter manuellement, traiter queue

## Actions sur les Documents

Pour chaque document :
- [ ] Voir le détail
- [ ] Modifier
- [ ] Télécharger
- [ ] Visualiser (PDF viewer)
- [ ] Partager (générer lien)
- [ ] Voir l'historique
- [ ] Ajouter une note
- [ ] Supprimer une note
- [ ] Classification IA
- [ ] Appliquer suggestions IA
- [ ] Supprimer (trash)
- [ ] Restaurer depuis trash

## Notes

- Toutes les erreurs sont loggées dans `.cursor/debug.log`
- Vérifiez la console du navigateur pour les erreurs JavaScript
- Notez toutes les erreurs rencontrées
