# Lot ged-mfiles-permissions-1 — Famille M-Files « permissions dynamiques »

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1,
instance MariaDB locale ; `.env` local aligné sur le port de cette instance pour le worktree).
Branche : `ged/ged-mfiles-permissions-1` · point de départ : `origin/v2` (`fdb1ad2`, familles
archivage/versions, classes, relations et vues déjà fusionnées).

## Objectif

Porter la **famille « permissions dynamiques »** : droits calculés depuis la classe, les métadonnées et
l'état, visibilité conditionnelle à l'affichage et à la recherche, règles nommées et portables, écran de
gestion — sans affaiblir la sécurité en place ni toucher `src/Parite/Juge.php`, la liste fermée
d'exclusions ou les statuts/vecteurs Paperless.

## Etat d'avancement

### Fait (commité)

- `f627620` — cadrage du secteur `docs/secteurs/permissions.md` et présente note.
- `5850063` — moteur `Ged\Permissions` :
  - `Regles` : règles **nommées**, **portables** (`.ged/permissions.json` via `Ged\Socle\Registre`),
    création / désactivation / réactivation, **jamais de suppression** ; validations refusées **nommément** ;
  - `Moteur` : `decision()` (voir / modifier par classe / métadonnée / état), `clauseSql()` (visibilité
    conditionnelle en SQL), `contextePourCompte()` (groupes et rôles résolus en base), `etat()`,
    `circuits()`, `etats()`, `transitions()`, `transitionner()` (axe état, délégué à `Ged\Workflows`) ;
  - intégration **visibilité** dans la liste (`Ged\Socle\EcranBibliotheque`) et la recherche
    (`Ged\Recherche\Service`) : la règle s'ajoute APRÈS l'ACL de dossier, ne fait que restreindre ;
  - oracle : `tests/php/Permissions/MoteurTest.php` (7 tests) + vecteurs ; suite PHPUnit complète verte
    (**955 tests, 18 062 assertions, 1 saute**).
- `12b857b` — écran `/permissions` (`src/Permissions/Ecran.php`, `Module.php`, `templates/ecrans/permissions.twig`),
  entrée de menu Administration (`Ged\Ui\Rail`), routes déclarées (`DroitsRoutes`), une ligne dans
  `Kernel`, charges CSRF de la sonde. `tests/php/Permissions/ModuleTest.php` (3 tests).
- `6ebb4b3` — parité M-Files : section « famille 4 » du correcteur, `correspondance.json` régénéré
  (mfiles couverts **51 → 56**), parcours E2E.
- `e3a65c7` — isolation des vecteurs d'états (nettoyage des circuits de test, états avec `libelle`) et
  parcours E2E sans résidu dans le fonds (règle visant un état unique, fichier de règles restauré).

### Reste

- Harness complet `-SansBanc` (couches 0,1,2,4,5,6,7,8,9,9b) et chiffres exacts ci-dessous.

## Mesures exactes

### Oracle M-Files (juge `tools/parite/juge-mfiles.php`)

| Mesure | Avant lot | Apres lot |
| --- | ---: | ---: |
| Inventaire public M-Files rejoue a l'identique | oui (112) | oui (112) |
| Couverts | 51 | **56** |
| Absents (proposes au juge) | 61 | 56 |
| Non prouves | 0 | 0 |
| Parite stricte (= hors exclusions) | 45,54 % | **50,00 %** |
| Erreurs du juge | 0 | 0 |

Elements passes de `absent` a `couvert` / `couvert-autrement` (section « famille 4 » du correcteur) :

| Element M-Files | Statut | Equivalent GEDv2 | Preuve |
| --- | --- | --- | --- |
| GET `/objects/(type)/(objectid)/(version)/workflowstate` | couvert | service `Ged\Permissions\Moteur` | `MfilesPermissionsVecteursTest::testRejoueLesVecteursDesEtats` |
| PUT `/objects/(type)/(objectid)/(version)/workflowstate` | couvert | service `Ged\Permissions\Moteur` | idem |
| GET `/structure/workflows` | couvert-autrement | service `Ged\Permissions\Moteur` | idem |
| GET `/structure/workflows/(id)/states` | couvert-autrement | service `Ged\Permissions\Moteur` | idem |
| GET `/structure/workflows/(id)/statetransitions` | couvert-autrement | service `Ged\Permissions\Moteur` | idem |

Reference publique : `https://developer.m-files.com/APIs/REST-API/Reference/resources/objects/type/objectid/version/workflowstate/`
et `.../structure/workflows/id/states/`, `.../statetransitions/`.

### Vecteurs (rejoues par PHPUnit)

| Famille | Fichier | Passes / total |
| --- | --- | ---: |
| mfiles-permissions-regles | `tests/parite/vecteurs/mfiles-permissions-regles.json` | 11/11 |
| mfiles-permissions-visibilite | `tests/parite/vecteurs/mfiles-permissions-visibilite.json` | 4/4 |
| mfiles-permissions-etats | `tests/parite/vecteurs/mfiles-permissions-etats.json` | 4/4 |

Ce que les vecteurs prouvent : decision par **classe**, par **valeur de metadonnee**, par **etat** ;
document **hors condition** intact ; regle **desactivee** inoperante ; **refus nomme** d'une regle sans
cible ; **portabilite** (relecture apres `Registre::oublier`, fichier `.ged/permissions.json`) ;
**desactivation sans suppression** ; **visibilite** en liste pour un non-autorise (facture retiree,
contrat conserve, total coherent), pour un autorise, pour un **administrateur**, et en **recherche**
(le document protege sort du resultat et du total) ; **etat effectif**, **transition autorisee**,
**transition refusee nommee** (etat inchange), catalogue des **etats et transitions** nommes.

### Tests

| Mesure | Avant lot | Apres lot |
| --- | ---: | ---: |
| Suite PHPUnit | 942 tests, 17 997 assertions, 1 saute | **955 tests, 18 062 assertions, 1 saute** |
| Dont `Ged\Tests\Permissions\MoteurTest` | - | 7 tests, 21 assertions |
| Dont `Ged\Tests\Permissions\ModuleTest` | - | 3 tests, 18 assertions |
| Dont `MfilesPermissionsVecteursTest` | - | 3 tests, 9 assertions (19 vecteurs) |
| PHPStan niveau 6 | 0 erreur | **0 erreur hors baseline** |

### Harness complet `-SansBanc`

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` — commit `e3a65c7`, duree
**978 s**, registre `recette/registre.json` (2026-10-14T12:15:41). Toutes les couches VERTES sauf
`3-banc`, ROUGE par construction (`-SansBanc`) :

| Couche | Etat | Detail exact |
| --- | --- | --- |
| 0 garde-fous | VERT | vendor et `node_modules/@playwright/test` presents ; `DB_NAME=gedv2_test` impose |
| 1 unitaire | VERT | **955 tests, 18 062 assertions**, 1 saute |
| 3 banc | ROUGE | sautee (`-SansBanc`), par construction |
| 4 ecrans | VERT | **106 parcours passes** (4,5 min) |
| 7 securite | VERT | aucun contournement accepte |
| 2 contrat | VERT | toutes les routes declarees couvertes |
| 6 charge | VERT | p95 lecture **607 ms**, p95 ecriture **255 ms**, 0 erreur, 0 collision |
| 8 statique | VERT | PHPStan niveau 6 : **0 erreur hors baseline** ; `composer audit` : aucune vulnerabilite |
| 5 personas | VERT | administrateur 4/4 (646 ms), auditeur 4/4 (428 ms), comptable 4/4 (594 ms), debutant 4/4 (393 ms), fiduciaire 4/4 (424 ms), secretaire 3/3 (570 ms) |
| 9 parite Paperless | VERT | 780 elements, couverts **737**, strict **94,49 %**, hors exclusions **100,00 %** (inchange) |
| 9b parite M-Files | VERT | 112 elements, couverts **56**, strict **50,00 %**, ecarts 56, inventaire rejoue a l'identique |

## `mfiles:*` restants proposes au juge

Aucune exclusion n'est posee par l'agent. Restent `absent` et **proposes** : le reste des elements de
**structure de workflows** (alias `itemidbyalias`, workflow/état unitaire par id brut, creation de
transitions par alias), les vues/favoris/activite par utilisateur, session/serveur/repositories,
sharedlinks, icones, fichiers/apercus, checked-out, commentaires, etc. — inchanges depuis les lots
precedents. La famille « permissions dynamiques » vise cinq elements REST portes par le service
`Ged\Permissions\Moteur` ; les elements de structure de workflows restent a la famille workflows.

## Porte qualite (ECC)

- **TDD** : oracle ecrit d'abord (`Regles`/`Moteur` et vecteurs rouges — « Class not found »), puis le
  code, puis vert. Chaque etape a son test ; un element sans test vaut 0.
- **Analyse statique** : PHPStan niveau 6, `0 erreur hors baseline` (baseline inchangee).
- **php-reviewer / security-reviewer / silent-failure-hunter** (revue manuelle du diff) :
  - les regles ne font que RESTREINDRE, elles s'ajoutent APRES l'ACL de dossier et la garde centrale ;
  - SQL de visibilite **parametre** (classe liee, cle de champ validee en slug, etat lie), aucune entree
    utilisateur concatenee ; les cles impossibles rendent `1 = 0` (jamais un predicat lache) ;
  - aucun `catch (Throwable)` avale : les refus sont **nommes** (etape, cause, reference), l'ecran 503
    nomme l'absence de schema, les erreurs PDO du contexte laissent un contexte vide => refus par prudence ;
  - aucun secret, aucune donnee reelle (comptes/groupes synthetiques `@gedv2.test`) ;
  - le compte synthetique (id nul) et l'administrateur (`*`) ne sont pas restreints : comportement
    historique conserve, la securite existante n'est jamais affaiblie.
- **Garde-fous** : aucune suppression de donnee utilisateur (`desactiver`/`activer` seulement) ; les
  vecteurs d'etats nettoient leurs circuits ; le parcours E2E restaure le fichier de regles du fonds.

ETAT: fini
