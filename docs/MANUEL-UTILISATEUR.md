# K-Docs — manuel d'utilisation

> Relevé effectué le **2026-08-11** sur `http://127.0.0.1:8765/kdocs`, compte `root`,
> base `kdocs` (446 lignes dans `documents`). Chaque écran a été ouvert, parcouru et
> capturé. Les captures sont dans `docs/captures/`.
>
> **Ce manuel décrit ce que le produit fait, pas ce qu'il devrait faire.** Là où
> l'écran est incohérent, l'incohérence est écrite telle quelle, encadrée « ⚠ Ce que
> vous verrez ». Un manuel qui lisse les incohérences apprend à l'utilisateur à se
> méfier de sa documentation.

---

## Avertissement sur les données de cette copie

La bibliothèque est aujourd'hui remplie de fichiers de test : `test_*.pdf`,
`probe_*.pdf`, `MULTI_TEST_*`. Une partie a été produite par les campagnes de
recette antérieures, une partie par les essais de découpe du 10 et 11 août 2026.
Les captures montrent donc une bibliothèque de test, pas un fonds documentaire réel.

---

## 1. Se connecter

**Écran** : `/kdocs/login`

Deux champs — nom d'utilisateur, mot de passe — et un bouton **Se connecter**.
Aucun lien « mot de passe oublié », aucune création de compte : les comptes se
créent depuis Administration → Utilisateurs.

Après connexion, vous arrivez sur le tableau de bord.

---

## 2. Tableau de bord

**Écran** : `/kdocs/` · capture `01-tableau-de-bord.jpg`, `02-tableau-de-bord-bas.jpg`

C'est la page d'accueil. Elle donne quatre compteurs, deux graphiques, le top des
correspondants et les derniers documents entrés.

| Élément | Ce qu'il donne |
|---|---|
| Documents totaux | nombre de documents de la bibliothèque |
| Documents indexés | documents dont le contenu a été extrait et rendu cherchable |
| En attente | documents qui attendent une validation humaine |
| Tâches | tâches de workflow ouvertes |
| Documents par mois | courbe des entrées |
| Répartition par type | camembert par type de document |
| Documents récents | les dernières entrées, cliquables |

### ⚠ Ce que vous verrez

**Les compteurs ne concordent pas entre eux.** Au même instant, sur les mêmes
données, le produit affiche six valeurs différentes pour deux notions :

| Où | Libellé | Valeur |
|---|---|---|
| Barre latérale | Bibliothèque | **159** |
| Tableau de bord | Documents totaux | **159** |
| Bibliothèque, en-tête | Documents | **200** |
| Bibliothèque, vues filtrées | Tous les documents | **36** |
| Hub admin | Documents | **446** |
| Base de données | lignes vivantes | **217** |

Et pour « ce qui attend » :

| Où | Libellé | Valeur |
|---|---|---|
| Tableau de bord | En attente | **123** |
| Barre latérale | À traiter | **385** |
| Page « À traiter » | éléments | **195** |

**Le badge « À traiter » bouge tout seul.** Entre deux captures prises à quelques
secondes d'intervalle, sans aucune action, il est passé de **367** à **385**, et la
pastille de notifications de **99+** à **15**, puis de nouveau à **99+**.

**Méthode de travail conseillée** : ne prenez aucun de ces compteurs pour un
inventaire. Servez-vous-en comme d'un signal « il y a du travail », jamais comme
d'une quantité.

---

## 3. Bibliothèque

**Écran** : `/kdocs/documents` · capture `03-bibliotheque.jpg`, `08-bibliotheque-miniatures.jpg`

C'est l'écran où vous passez le plus de temps. Trois colonnes.

**À gauche, trois façons d'entrer dans le fonds :**

- **Vues filtrées** — des raccourcis par type (Tous les documents, Factures,
  Contrats, Correspondance). Ce ne sont **pas** des dossiers disque : ils sont
  définis en base et rassemblent les documents où qu'ils soient rangés. C'est
  l'équivalent des vues dynamiques d'un ECM.
- **Arborescence disque** — la structure réelle des fichiers indexés. Les dossiers
  techniques (`consume`, `toclassify`) sont masqués.
- **Types** — filtre par type de document.
- **Qualité (SMQ)** — « À quittancer ».

**Au centre**, la grille des documents : une vignette, le nom du fichier, la date,
et une pastille d'état (**À classer**, **Validé**).

**En haut à droite** : un champ de recherche acceptant `AND`, `OR` et les guillemets
pour une phrase exacte, un tri (Date ↑↓, Titre A-Z / Z-A, Uploader), une bascule
grille/liste, et le bouton **Uploader**.

### ⚠ Ce que vous verrez

**Les vignettes mettent longtemps à apparaître.** Elles finissent par s'afficher :
vérifié le 2026-08-11 sur le réseau, 12 miniatures testées une par une rendent
`HTTP 200`, `image/png`, contenu non vide. Mais tant que la page n'a pas fini de
charger, les cartes restent des rectangles blancs et vous n'identifiez un document
que par son nom de fichier.

> **Correction.** La première version de ce manuel affirmait « 50 balises image, 0
> chargée » et concluait que les vignettes étaient cassées. C'était faux : la mesure
> avait été prise sur une page encore en cours de chargement. La lenteur est réelle,
> la casse ne l'était pas.

**Toutes les dates affichées sont identiques** — 11/08/2026 sur la première page —
alors que les documents ont des dates propres. C'est la date d'entrée en base, pas
la date du document.

**La page fige le navigateur.** Le serveur répond en 287 ms, mais la page reste
occupée plusieurs secondes et les clics n'aboutissent plus. Sur cette copie, un
onglet est devenu totalement insensible et a dû être abandonné.

**« Tous les documents » affiche 36** alors que la bibliothèque en annonce 200 et le
bandeau latéral 159. La vue filtrée « Tous » ne montre pas tout.

---

## 4. Recherche

**Écran** : `/kdocs/search` · capture `05-recherche.jpg`

Recherche plein texte sur la bibliothèque, avec trois filtres : type de document,
correspondant, périmètre. Un bouton **Assistant IA** en haut ouvre une recherche en
langue naturelle.

La recherche porte sur le titre, le contenu OCR et le correspondant.

**Méthode de travail** : c'est l'écran le plus fiable du produit. La recherche plein
texte est couverte par un contrôle automatisé vert et fonctionne sans dépendance
externe.

### ⚠ Ce que vous verrez

Une recherche qui échoue en base rend **zéro résultat**, sans message d'erreur : le
produit avale l'erreur SQL. « Aucun résultat » et « la recherche est cassée » se
ressemblent à l'écran. Si un terme dont vous êtes certain ne rend rien, ne concluez
pas que le document est absent.

---

## 5. À traiter

**Écran** : `/kdocs/mes-taches` · capture `09-a-traiter.jpg`

Liste des éléments qui demandent une action, en cinq onglets : **Toutes**,
**À valider**, **À classer**, **Workflows**, **Notes**.

Chaque ligne porte le nom du document, la nature de l'action (Classification), un
libellé (« Document en attente de classification »), la date de création, et deux
boutons : **Voir** et un bouton de commentaire.

### ⚠ Ce que vous verrez

**Le compteur de la barre latérale ne correspond pas à la page.** La barre affiche
**385**, la page annonce **195 élément(s)**, et l'onglet « À valider » n'affiche
aucun compteur alors que « À classer » en affiche 195.

**Cet écran est un doublon, et il vous éloigne du bon.** Le classement se fait en
§6, sur `/admin/consume` : la liste des documents à classer, chacun visible avec son
aperçu et classable sur place. `mes-taches` refait le même travail dans une autre
liste, en moins bien — il faut y cliquer « Voir » document par document. Et ce
bouton tombe en 404 : le lien est construit sans le préfixe `/kdocs`
(`TaskUnifiedService.php`, lignes 182-183).

Le bandeau « À traiter » de la barre latérale, badge 385, mène ici. Il devrait mener
à l'écran de classement.

> **Correction.** La première version de ce manuel présentait `mes-taches` et
> `/admin/consume` comme deux écrans légitimes aux rôles distincts. C'est faux :
> c'est un doublon, et l'écran de classement est `/admin/consume`. L'erreur venait
> de moi, pas du produit.

---

## 6. Valider et classer les documents entrants

**Écran** : `/kdocs/admin/consume` · capture `04-validation-documents.jpg`

**C'est l'écran de travail quotidien**, celui où vous traitez ce qui entre. Pour
chaque document en attente, un bloc avec :

- **à gauche** un aperçu du document, son nom, sa taille, sa date d'entrée, la
  **méthode** de classification retenue et la **confiance** en pourcentage ;
- **à droite** un formulaire : Titre, Correspondant, Type de document (obligatoire),
  Date du document (obligatoire), Montant, Tags ;
- **Emplacement de stockage** : soit le chemin suggéré automatiquement au format
  `Année/Type/Fournisseur` — le dossier est créé tout seul — soit un chemin
  personnalisé ;
- trois actions : **✓ Valider et classer**, **🤖 Analyser avec l'IA**,
  **👁️ Voir le document**.

En haut : le mode courant (`auto`), l'état du moteur IA, une bascule **OCR / IA**,
un interrupteur « Utiliser l'IA pour les documents complexes », le nombre de
fichiers en attente d'import, et deux boutons **Scanner** / **Re-scanner**.

**Méthode de travail** : déposez vos fichiers dans le dossier surveillé, cliquez
**Scanner**, puis descendez la file en validant. Un document au-dessus du seuil de
certitude arrive déjà pré-rempli ; en dessous, la proposition est affichée mais rien
n'est appliqué — c'est à vous de trancher.

### ⚠ Ce que vous verrez

**La page charge la file entière d'un coup.** Mesuré sur cette copie :

```
385 formulaires complets · 1 540 listes déroulantes · 8 462 options
36 824 nœuds · 6 682 Ko de HTML
```

Le navigateur met plusieurs dizaines de secondes à rendre la page, et elle grossit
à chaque document entrant. Il n'y a ni pagination, ni recherche, ni traitement par
lot : pour atteindre le 300ᵉ document, il faut faire défiler 299 formulaires.

**La file n'a jamais été vidée.** Le plus ancien document en attente date du
**25 janvier 2026**, et **un seul** document a été validé dans toute la vie du
produit.

**Plus de la moitié de la file est composée de documents supprimés.** La requête qui
alimente cet écran ne filtre pas la corbeille : sur 367 lignes relevées, **190
étaient des documents supprimés**. Vous validez des documents qui sont à la
corbeille.

**Trois réglages d'IA se contredisent sur le même bandeau** : « ✓ IA disponible »,
« OCR activé », et « Utiliser l'IA pour les documents complexes : Désactivé ».
Aucun texte n'explique lequel gouverne.

**Le menu change complètement.** En arrivant ici, la navigation de gauche est
remplacée par celle de l'Administration (Hub admin, Diagnostic, Indexation, K-Time,
Référentiels, Système). Valider une facture n'est pas un acte d'administration, mais
l'écran vous y place.

---

## 7. Importer un document

**Écran** : `/kdocs/documents/upload`

Formulaire simple : le fichier (PDF, DOC, DOCX, JPG, PNG, TXT), puis Titre, Type de
document, Correspondant, Date, Montant, Devise (CHF, EUR, USD). Deux boutons :
**Annuler**, **Uploader**.

**Méthode de travail** : l'import unitaire sert aux pièces isolées. Pour un volume,
passez par le dossier surveillé et le bouton **Scanner** de l'écran de validation.

---

## 8. Découpe automatique d'un PDF multi-documents

Il n'y a **pas d'écran dédié** : la découpe se déclenche seule à l'ingestion.

Un PDF contenant plusieurs documents distincts est analysé page par page. S'il
contient N documents, il produit N documents séparés, chacun avec son fichier, et le
PDF d'origine passe en état `split`. Chaque morceau conserve le lien vers son
parent et la méthode qui l'a produit : `ai` quand le moteur d'analyse a répondu,
`rules` quand il a fallu se replier sur les règles de rupture (page quasi vide,
changement d'en-tête, nouveau numéro de facture).

Les morceaux arrivent ensuite dans la file de validation, où ceux qui dépassent le
seuil de certitude sont déjà classés.

### ⚠ Ce que vous verrez

**Les morceaux n'apparaissent pas dans la bibliothèque** tant que personne ne les a
validés. Ils existent, ils sont classés, mais l'écran principal ne les montre pas.
Sur un cycle complet mesuré — 1 PDF, 3 morceaux — **1 document sur 4 était visible**
dans la liste standard.

---

## 9. Administration

**Écran** : `/kdocs/admin` · capture `06-hub-admin.jpg`

Quatre compteurs (Utilisateurs, Documents, Types, Correspondants) et douze cartes :

| Carte | Ce qu'elle gère |
|---|---|
| Paramètres | configuration générale et IA |
| Utilisateurs | comptes et rôles |
| Tags | étiquettes documentaires |
| Types de documents | typologie et classification |
| Workflows | circuits de validation |
| Correspondants | contacts et fournisseurs |
| Diagnostic | IA, ingestion et services |
| Indexation | workers et file d'attente |
| Fichiers à valider | dossier surveillé |
| Snapshots | sauvegardes de configuration |
| Règles d'attribution | classification automatique |
| Audit | journal des actions |

La colonne de gauche ajoute : Champs personnalisés, Champs de classification,
Webhooks, Journaux, Export/Import, Statistiques API, et un raccourci **K-Time**.

### ⚠ Ce que vous verrez

**Le compteur « Documents » de cette page inclut la corbeille** — 446 contre 217
documents vivants. C'est la seule page qui compte les documents supprimés, sans le
dire.

---

## 10. Ce que le produit ne fait pas encore

Écrit ici pour éviter de le chercher.

- **Le dossier surveillé n'est pas réglable.** Les Paramètres exposent le type de
  stockage, le chemin de base et les extensions autorisées — mais **pas** le dossier
  d'arrivée des scans. Il est en dur : `storage/consume`, sous la racine du produit.
  Il ne peut être ni déplacé ailleurs, ni dédoublé.
- **La fréquence de passage n'est pas réglable.** L'écran des tâches planifiées
  **affiche** la périodicité en texte, sans champ pour la modifier. Le seul bouton
  est « Exécuter », à la main.
- **Cet écran n'est atteignable par aucun menu.** La route `/admin/scheduled-tasks`
  existe, mais ni la barre latérale ni le hub n'y renvoient. Il faut connaître l'URL.
- **Aucune tâche planifiée ne s'exécute.** Les quatre tâches déclarées (indexation
  du disque toutes les 6 h, nettoyage de corbeille, vérification des e-mails,
  génération des vignettes) ont toutes `dernier lancement = jamais`. Tout se
  déclenche à la main, par les boutons **Scanner** et **Indexation**.
- **Les versions de documents** existent en base mais aucun écran ne les expose.
- **Les permissions par dossier** sont vérifiées côté serveur, mais aucune règle
  n'est configurée et aucun écran ne permet d'en poser : tout est ouvert.
- **Une page inexistante affiche une trace technique complète** — chemins du
  serveur, arborescence `vendor/` (capture `07-404-trace-de-pile.jpg`). À couper
  avant toute mise à disposition hors du poste de développement.

---

## Récapitulatif des incohérences relevées

| # | Écran | Constat |
|---|---|---|
| 1 | Partout | six valeurs pour « combien de documents » : 36, 159, 200, 217, 446 |
| 2 | Partout | trois valeurs pour « ce qui attend » : 123, 195, 385 |
| 3 | Tableau de bord | le badge « À traiter » change seul (367 → 385) |
| 4 | ~~Bibliothèque~~ | ~~50 vignettes, 0 affichée~~ — **retiré** : mesure prise sur une page en cours de chargement, les vignettes rendent bien `200 image/png`. Reste la lenteur, ligne 6. |
| 5 | Bibliothèque | toutes les dates identiques (date d'entrée, pas date du document) |
| 6 | Bibliothèque | la page fige le navigateur ; un onglet a dû être abandonné |
| 7 | Bibliothèque | « Tous les documents » n'en montre que 36 |
| 8 | À traiter | le lien du bandeau ne mène pas à la file de validation |
| 9 | À traiter | badge 385, page 195 |
| 10 | Validation | 385 formulaires dans une page, 6,7 Mo de HTML |
| 11 | Validation | 190 documents supprimés sur 367 dans la file |
| 12 | Validation | trois réglages d'IA contradictoires sur le même bandeau |
| 13 | Validation | la navigation bascule en mode Administration |
| 14 | Découpe | les morceaux restent invisibles dans la bibliothèque |
| 15 | Hub admin | le compteur inclut la corbeille sans le dire |
| 16 | 404 | trace de pile complète exposée |
| 17 | Recherche | une erreur SQL rend « zéro résultat » sans message |
