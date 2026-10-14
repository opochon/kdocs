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

### En cours

- Harness complet `-SansBanc` sur les couches touchees.

### Reste

- Harness complet vert + report exact des chiffres + porte qualite ECC + note finale `ETAT: fini`.

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

### Vecteurs

| Famille | Fichier | Passes / total |
| --- | --- | ---: |
| mfiles-vues-filtres | `tests/parite/vecteurs/mfiles-vues-filtres.json` | 6/6 |
| mfiles-vues-recherchees | `tests/parite/vecteurs/mfiles-vues-recherchees.json` | 3/3 |

### Tests

| Mesure | Avant lot | Apres lot |
| --- | ---: | ---: |
| Suite PHPUnit | 937 tests, 17 975 assertions, 1 saute | **942 tests, 17 997 assertions, 1 saute** |
| Dont `VuesTest` | 14 tests, 87 assertions | 17 tests, 109 assertions (partage avec les vecteurs) |
| Dont `MfilesVuesVecteursTest` | - | 2 tests, 15 assertions (9 vecteurs) |
| Playwright cible `vues.spec.ts` + `bibliotheque.spec.ts` | - | **3/3 vert** |
| PHPStan niveau 6 | 0 erreur | **0 erreur** |

## `mfiles:*` restants proposes au juge (61, statut `absent`)

Aucune exclusion n'est posee par l'agent. Restent `absent` et **proposes** :

- **Regroupement/regroupement par metadonnee** : aucun element M-Files REST mesure ne le porte ; reste
  `absent` et propose au juge (aucune exclusion).
- **Vues personnelles par utilisateur** : les vues du fonds sont partagees et tracees par `auteur`
  (configuration versionnee, pas de coffre proprietaire) ; le cloisonnement fin par utilisateur n'est pas
  un element M-Files REST de cette famille. Reste `absent` et propose.
- **Favoris** (`GET/POST/DELETE /favorites*`) : epinglage d'objets par utilisateur, distinct d'une vue.
  Reste `absent` et propose.
- `GET /recentlyaccessedbyme`, `POST /recentlyaccessedbyme` : activite par utilisateur, non revendiquee
  par ce lot. Reste `absent` et propose.
- Le reste des 61 `mfiles:*` absents est inchange depuis les lots precedents (session, serveur,
  repositories, sharedlinks, workflows, icones, fichiers/apercus, checked-out, commentaires,
  workflowstate…) ; aucun n'a ete exclu par l'agent.

## Porte qualite (ECC) — a completer apres le harness

- Analyse statique, revue `php-reviewer` / `security-reviewer` / `silent-failure-hunter`, TDD.

ETAT: en cours
