# K-Docs - Checklist Tests Interface

## Instructions
Cochez chaque point apres verification manuelle.
Pour les tests automatises, utilisez `tests/integration_tests.php`.

---

## 1. AUTHENTIFICATION

- [ ] **Login**: Formulaire s'affiche correctement
- [ ] **Login**: Connexion avec credentials valides → redirection dashboard
- [ ] **Login**: Connexion avec credentials invalides → message erreur
- [ ] **Logout**: Deconnexion → retour page login
- [ ] **Session**: Page protegee sans session → redirection login

---

## 2. DASHBOARD

- [ ] **Affichage**: Statistiques documents/taches visibles
- [ ] **Widgets**: Documents recents, taches en attente
- [ ] **Navigation**: Liens sidebar fonctionnels

---

## 3. LISTE DOCUMENTS

- [ ] **Affichage**: Liste documents avec miniatures
- [ ] **Tri**: Tri par date, nom, type fonctionne
- [ ] **Filtres**: Filtre par type, correspondant, tags
- [ ] **Recherche**: Recherche textuelle retourne resultats
- [ ] **Pagination**: Navigation pages si >20 documents
- [ ] **Selection**: Cases a cocher pour actions groupees

---

## 4. UPLOAD DOCUMENT

- [ ] **Bouton**: Bouton "Ajouter" visible et fonctionnel
- [ ] **Drag & Drop**: Zone de depot fichiers fonctionne
- [ ] **Multi-fichiers**: Upload plusieurs fichiers simultanement
- [ ] **Types**: PDF, DOCX, images acceptes
- [ ] **Progress**: Barre de progression visible
- [ ] **Succes**: Message confirmation apres upload
- [ ] **Erreur**: Message si fichier rejete (taille, type)

---

## 5. FICHE DOCUMENT

- [ ] **Miniature**: Image apercu visible
- [ ] **Metadonnees**: Titre, date, type affiches
- [ ] **Tags**: Tags affiches et cliquables
- [ ] **Correspondant**: Nom correspondant visible
- [ ] **Actions**: Telecharger, editer, supprimer accessibles
- [ ] **Contenu**: Texte extrait visible (onglet ou section)
- [ ] **OnlyOffice**: Bouton edition si Office configure

---

## 6. EDITION DOCUMENT

- [ ] **Formulaire**: Champs editables charges
- [ ] **Type**: Selection type document
- [ ] **Correspondant**: Autocomplete correspondant
- [ ] **Tags**: Ajout/suppression tags
- [ ] **Champs**: Champs personnalises si configures
- [ ] **Sauvegarde**: Modifications persistees en DB
- [ ] **Validation**: Erreurs formulaire affichees

---

## 7. SUPPRESSION

- [ ] **Confirmation**: Dialogue confirmation avant suppression
- [ ] **Corbeille**: Document deplace vers trash (pas supprime)
- [ ] **Restauration**: Option restaurer depuis trash

---

## 8. ADMINISTRATION

### 8.1 Page Admin
- [ ] **Acces**: /admin accessible (role admin)
- [ ] **Stats**: Compteurs utilisateurs/documents visibles

### 8.2 Gestion Utilisateurs
- [ ] **Liste**: /admin/users affiche utilisateurs
- [ ] **Creation**: Formulaire creation fonctionne
- [ ] **Edition**: Modification utilisateur possible
- [ ] **Suppression**: Suppression avec confirmation

### 8.3 Diagnostic
- [ ] **Acces**: /admin/diagnostic accessible
- [ ] **CASCADE IA**: Status Claude/Ollama/Regles affiches
- [ ] **Outils**: Tesseract, Ghostscript, LibreOffice status
- [ ] **Services**: MySQL, OnlyOffice, Ollama status
- [ ] **Test IA**: Bouton test classification fonctionne

### 8.4 Types Documents
- [ ] **Liste**: Types affiches
- [ ] **CRUD**: Creation/Edition/Suppression

### 8.5 Correspondants
- [ ] **Liste**: Correspondants affiches
- [ ] **CRUD**: Creation/Edition/Suppression

### 8.6 Tags
- [ ] **Liste**: Tags affiches avec couleurs
- [ ] **CRUD**: Creation/Edition/Suppression

---

## 9. RECHERCHE

- [ ] **Barre**: Barre recherche visible header
- [ ] **Resultats**: Resultats pertinents retournes
- [ ] **Highlight**: Termes recherches surlignés
- [ ] **Filtres**: Filtres supplementaires dans resultats
- [ ] **Vide**: Message si aucun resultat

---

## 10. CONSUME (Scanner)

- [ ] **Page**: /admin/consume accessible
- [ ] **Scan**: Bouton scan detecte nouveaux fichiers
- [ ] **Liste**: Documents en attente affiches
- [ ] **Validation**: Validation individuelle fonctionne
- [ ] **Bulk**: Validation en lot disponible

---

## 11. RESPONSIVE

- [ ] **Desktop**: Affichage correct 1920x1080
- [ ] **Tablet**: Affichage correct 768px
- [ ] **Mobile**: Navigation utilisable 375px

---

## 12. PERFORMANCES

- [ ] **Chargement**: Page < 3s
- [ ] **Upload**: Feedback immediat
- [ ] **Recherche**: Resultats < 2s

---

## Resultats

| Section | Tests | Passes | % |
|---------|-------|--------|---|
| Auth | 5 | _ | _ |
| Dashboard | 3 | _ | _ |
| Liste Docs | 6 | _ | _ |
| Upload | 7 | _ | _ |
| Fiche Doc | 7 | _ | _ |
| Edition | 7 | _ | _ |
| Suppression | 3 | _ | _ |
| Admin | 13 | _ | _ |
| Recherche | 5 | _ | _ |
| Consume | 5 | _ | _ |
| Responsive | 3 | _ | _ |
| Performances | 3 | _ | _ |
| **TOTAL** | **67** | **_** | **_** |

---

*Date du test: ____________________*
*Testeur: ____________________*
*Version: 1.0.0*
