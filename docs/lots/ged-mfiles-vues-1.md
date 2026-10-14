# Lot ged-mfiles-vues-1 — Famille M-Files nº 3 : vues dynamiques (listes filtrees par classe/metadonnees, vues enregistrees)

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3308).
Branche : `ged/ged-mfiles-vues-1` · point de depart : `origin/v2` (`ae5a2a6`, familles archivage/versions,
classes/metadonnees/listes et relations deja fusionnees).

## Objectif

Porter la **famille M-Files nº 3** — vues dynamiques par classe et metadonnees, vues enregistrees /
nommees, vues recherchees — en faisant passer les elements `mfiles:*` correspondants de `absent` a
`couvert`, prouves par des tests et des vecteurs rejoues, sans toucher `src/Parite/Juge.php`, la liste
fermee d'exclusions, ni les statuts/vecteurs Paperless.

## Etat d'avancement

### Fait (commite)

- `5bfab65` — cadrage du secteur `docs/secteurs/vues.md` (chemins, demandes, journal) et note de lot.
- `b433f6c` — **service `Vues::contenu()` / `Vues::compter()`** : applique les filtres compiles d'une
  vue enregistree (classe + valeurs de metadonnees + periode) a une liste de documents du fonds
  (`Fonds::tous()`), compte le contenu sans second parcours. Refus **nomme** d'une vue de recherche
  (son texte se rejoue par le moteur de Recherche, jamais un second moteur) et d'une vue inconnue.
  Oracle rouge d'abord, puis vert : **`mfiles-vues-filtres` 6/6**, **`mfiles-vues-recherchees` 3/3**
  (`MfilesVuesVecteursTest`), `VuesTest` +3 tests. PHPStan niveau 6 : 0 erreur.
- `58134c6` — **parite M-Files famille 3** : les trois elements REST `views` passent en `couvert`,
  section dediee de `tools/parite/corriger-correspondance-mfiles.php`, trait `SupportVues` (ne supprime
  pas `recherche_textes`, dependance d'autres suites). Juge M-Files **51/112, 45,54 %, 0 non-prouve**.
- `1ddde88` — note d'avancement.
- Dernier commit — **note finale, journal de secteur, registre et preuves du harness vert**.

### Reste

- _(aucun)_

## Mesures exactes

### Oracle M-Files (juge `tools/parite/juge-mfiles.php`)

| Mesure | Avant lot | Apres lot |
| --- | ---: | ---: |
| Inventaire public M-Files rejoue a l'identique | oui (112) | oui (112) |
| Couverts | 49 | **51** |
| Absents (proposes au juge) | 63 | 61 |
| Non prouves | 0 | **0** |
| Parite stricte (= hors exclusions) | 43,75 % | **45,54 %** |
| Erreurs du juge | 0 | 0 |

Elements passes de `absent` a `couvert` :

| Element M-Files | Statut | Equivalent GEDv2 | Preuve |
| --- | --- | --- | --- |
| GET `/views/(path)/items` (View contents) | couvert | service `Ged\Socle\Vues` | `MfilesVuesVecteursTest::testRejoueLesVecteursDesFiltresEtDuContenu` |
| GET `/views/(path)/items/count` (View contents count) | couvert | service `Ged\Socle\Vues` | `VuesTest::testLeContenuEtLeCompteDuneVueEnregistree` |
| GET `/views/(path)/objects` (Search view contents) | couvert | service `Ged\Socle\Vues` + moteur `Ged\Recherche` | `MfilesVuesVecteursTest::testRejoueLesVecteursDesVuesRecherchees` |

Reference publique (inventaire, deja versionne) :
`https://developer.m-files.com/APIs/REST-API/Reference/resources/views/path/items/`,
`.../views/path/items/count/`, `.../views/path/objects/`.

### Vecteurs

| Famille | Fichier | Passes / total |
| --- | --- | ---: |
| mfiles-vues-filtres | `tests/parite/vecteurs/mfiles-vues-filtres.json` | 6/6 |
| mfiles-vues-recherchees | `tests/parite/vecteurs/mfiles-vues-recherchees.json` | 3/3 |

Ce que les vecteurs prouvent : contenu filtre par **classe**, par **valeur de metadonnee** (nombre,
operateur `egal`), par **classe + periode + metadonnee**, refus nomme d'un champ inconnu, relecture
d'une vue nommee (slug, tri, presence de `.ged/vues.json`), **comptage sans deplacement** de fichier,
criteres de **vue recherchee** conserves dans le fonds, contenu de la vue recherchee **egal au moteur
de Recherche** (meme ordre, meme compteur), et **portabilite** du registre apres `Registre::oublier()`.

### Tests

| Mesure | Avant lot | Apres lot |
| --- | ---: | ---: |
| Suite PHPUnit | 937 tests, 17 975 assertions, 1 saute | **942 tests, 17 997 assertions, 1 saute** |
| Dont `VuesTest` | 14 tests, 87 assertions | 17 tests, 109 assertions (partage avec les vecteurs) |
| Dont `MfilesVuesVecteursTest` | - | 2 tests, 15 assertions (9 vecteurs) |
| PHPStan niveau 6 | 0 erreur | **0 erreur** |
| Playwright cible `vues.spec.ts` + `bibliotheque.spec.ts` | - | **3/3 vert** |

### Harness complet `-SansBanc` (2e passage, vert)

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` — couche 3 ROUGE par
construction (`-SansBanc`), toutes les autres couches VERTES (`recette/registre.json`, commit
`1ddde88`, duree **972 s**) :

| Couche | Etat | Detail exact |
| --- | --- | --- |
| 0 garde-fous | VERT | vendor et `node_modules/@playwright/test` presents ; `DB_NAME=gedv2_test` impose |
| 1 unitaire | VERT | **942 tests, 17 997 assertions**, 1 saute |
| 3 banc | ROUGE | sautee (`-SansBanc`), par construction |
| 4 ecrans | VERT | **105 parcours passes** (4,7 min) |
| 7 securite | VERT | aucun contournement accepte |
| 2 contrat | VERT | toutes les routes declarees couvertes |
| 6 charge | VERT | p95 lecture **620 ms**, p95 ecriture **289 ms**, 0 erreur, 0 collision |
| 8 statique | VERT | PHPStan niveau 6 : **0 erreur** ; `composer audit` : aucune vulnerabilite |
| 5 personas | VERT | administrateur 4/4, auditeur 4/4, comptable 4/4, debutant 4/4, fiduciaire 4/4, secretaire 3/3 |
| 9 parite Paperless | VERT | 780 elements, couverts **737**, strict **94,49 %**, hors exclusions **100,00 %** (inchange) |
| 9b parite M-Files | VERT | 112 elements, couverts **51**, strict **45,54 %**, ecarts 61, inventaire rejoue a l'identique |

Note d'environnement : un premier passage du harness a fait tomber **hors secteur** un parcours
Playwright (`edition-pages.spec.ts`, 90 s depassees) ; rejoue seul **2/2 vert** puis harness complet
rejoue **105/105 vert** sur machine calme. `tools/composer.phar` (ignore par git) a ete copie depuis
`C:\ged\GEDv2\tools\composer.phar` pour la couche 8 ; `node_modules` partage (C:\ged\GEDv2) a ete
reinstalle par `npm ci` (aucune modification du depot).

## `mfiles:*` restants proposes au juge (61, statut `absent`)

Aucune exclusion n'est posee par l'agent. Restent `absent` et **proposes** :

| Element / theme | Raison |
| --- | --- |
| **Regroupement/regroupement par metadonnee** | Aucun element M-Files REST mesure ne le porte ; a proposer au juge (le regroupement d'affichage existe chez M-Files, mais hors inventaire REST versionne). |
| **Vues personnelles par utilisateur** | Les vues du fonds sont partagees et tracees par `auteur` (configuration versionnee, pas de coffre proprietaire) ; le cloisonnement fin par utilisateur n'est pas un element M-Files REST de cette famille. |
| `GET`/`POST`/`DELETE /favorites*` | Epinglage d'objets par utilisateur, distinct d'une vue. |
| `GET`/`POST /recentlyaccessedbyme` | Activite par utilisateur, non revendiquee par ce lot. |
| Le reste des absents | Inchange depuis les lots precedents (session, serveur, repositories, sharedlinks, workflows, icones, fichiers/apercus, checked-out, commentaires, workflowstate…). Aucun n'a ete exclu par l'agent. |

## Porte qualite (ECC)

- **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur**, baseline
  vide inchangee ; `composer audit` sans vulnerabilite (couche 8 du harness).
- **Revue `php-reviewer`** : `declare(strict_types=1)` ; classes `final` ; signatures typees
  (`int $id`, `array $docs` avec PHPDoc `list<array<string,mixed>>`) ; methodes courtes ; `contenu()`
  reutilise `filtrerDocs()` au lieu d'un second moteur ; aucune duplication de la compilation des
  filtres (elle vit dans `compiler()`, appelee a l'enregistrement).
- **Revue `security-reviewer`** : aucun SQL, aucune commande, aucun fichier ecrit par les nouvelles
  methodes ; l'identifiant de vue vient d'un entier, les filtres deja compiles ne portent aucune entree
  utilisateur brute ; les messages d'erreur ne revelent que le numero de vue ; Twig echappe
  automatiquement (aucun gabarit modifie).
- **Revue `silent-failure-hunter`** : une vue inconnue et une vue de recherche sont refusees par une
  erreur **nommee** (jamais un contenu silencieusement partiel) ; aucun `catch` n'avale d'erreur ;
  les vecteurs comparent la sortie obtenue a la sortie attendue et rejettent tout ecart.
- **TDD** : l'oracle (`MfilesVuesVecteursTest` + vecteurs) a ete ecrit avant `Vues::contenu()` et vu
  rouge (`Call to undefined method`), puis vert ; les tests unitaires de `VuesTest` couvrent les deux
  branches de refus.

## Lignes hors secteur (signalees)

- `tools/parite/corriger-correspondance-mfiles.php`, `docs/parite/correspondance.json`,
  `docs/parite/PARITE-MFILES.md` : oracle M-Files (meme convention que les lots `ged-mfiles-*`
  precedents ; aucun statut/vecteur Paperless touche, verifie : 780 entrees identiques).
- `recette/registre.json`, `recette/preuves/*` : mesures du harness.

ETAT: fini
