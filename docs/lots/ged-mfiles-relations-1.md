# Lot ged-mfiles-relations-1 — Famille M-Files nº 2 : relations entre objets / documents

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3308).
Branche : `ged/ged-mfiles-relations-1` · point de départ : `origin/v2` (worktree neuf, `fc0a7f1`).

## Objectif

Porter la **famille M-Files nº 2** — relations entre objets, composition parent-enfant, références
croisées — en faisant passer les éléments `mfiles:*` correspondants de `absent` à
`couvert` / `couvert-autrement`, prouvés par des tests et des vecteurs rejoués, sans toucher
`src/Parite/Juge.php`, la liste fermée d'exclusions, ni les statuts/vecteurs Paperless.

Les relations sont de la **métadonnée** : définitions portables dans le fonds (`.ged/relations.json`),
valeurs par document dans le `.meta.json` (le dossier fait foi, portabilité Windows/Linux, aucune table
nouvelle). Écran : voir / ajouter / retirer une relation depuis la fiche document, naviguer vers l'objet
lié. Droits cohérents (lecture exigée des deux extrémités).

## État d'avancement

### Fait (commité)

- `fc0a7f1` — cadrage du secteur `docs/secteurs/relations.md` (chemins, demandes, journal).
- `3ccef5d` — **service `Ged\Socle\Relations`** : définitions de types dans `.ged/relations.json`
  (Référence, Dépendance, Affectation, Sous-objet — les types publiés par la documentation M-Files),
  valeurs dans le `.meta.json`, liens typés bidirectionnels, composition parent-enfant, résolution par
  empreinte, traçabilité des cibles supprimées, journal. Oracle rouge d'abord (10 erreurs « classe
  absente »), puis vert : **10 tests, 66 assertions**, PHPStan niveau 6 sans erreur.
- `8475986` — **vecteurs de comportement M-Files famille 2** (`mfiles-relations-liens` 7,
  `mfiles-relations-composition` 6) rejoués par `MfilesRelationsVecteursTest` contre le vrai service.
- `6886e9f` — **onglet « Relations » de la fiche** (templates/ecrans/document.twig), route
  `POST /documents/{id}/relations` (`lier` / `retirer` / `detacher`), permission `documents:lire`,
  charge CSRF de la sonde ; parcours Playwright `relations.spec.ts` **2/2**.
- `998744a` — **parité M-Files famille 2** : `relationships`, `relationships/count`, `subobjects`,
  `subobjects/count` (couverts) et `demoting-objects` (couvert-autrement) ; **49/112, 43,75 %, 0
  non-prouvé**. Trait `SupportRelations` (n'efface pas `recherche_textes`, dépendance d'autres suites).
- `524324b` — **oracle de porte** `tests/oracles/relations/porte.php` : **7/7 clauses**.
- `0d4ea36` — **droits cohérents** : un lien ne révèle jamais le titre d'une pièce hors des droits ;
  lier / retirer / détacher exige la lecture des documents visés.

### Reste

- Porte qualité ECC (revue du diff) et harness complet `-SansBanc` (ci-dessous).

## Mesures exactes

### Oracle M-Files (juge `tools/parite/juge-mfiles.php`)

| Mesure | Valeur |
| --- | --- |
| Inventaire public M-Files rejoué à l'identique | oui (112 éléments) |
| Couverts (avant lot → après lot) | **44 → 49** |
| Absents (proposés au juge) | 63 |
| Non prouvés | **0** |
| Parité stricte (= hors exclusions) | **39,29 % → 43,75 %** |
| Erreurs du juge | 0 |
| Vecteurs `mfiles-relations-liens` | 7/7 |
| Vecteurs `mfiles-relations-composition` | 6/6 |
| Vecteurs classes (non touchés) | `mfiles-classes-carte` 8/8, `mfiles-classes-listes` 4/4 |
| Vecteurs archivage (non touchés) | `mfiles-versions` 4/4, `mfiles-garantie` 3/3 |

Éléments passés de `absent` à couvert :

| Élément M-Files | Statut | Équivalent GEDv2 | Preuve |
| --- | --- | --- | --- |
| GET `…/relationships` | couvert | service `Ged\Socle\Relations` | `RelationsTest::testUnLienTypeEstNavigableDansLesDeuxSens` |
| GET `…/relationships/count` | couvert | service `Ged\Socle\Relations` | `RelationsTest::testCompterLesRelationsDesDeuxSens` |
| GET `…/subobjects` | couvert | service `Ged\Socle\Relations` | `RelationsTest::testCompositionParentEnfantEtDetachement` |
| GET `…/subobjects/count` | couvert | service `Ged\Socle\Relations` | `RelationsTest::testCompositionParentEnfantEtDetachement` |
| PUT `/objects/demotemultiobjects` | couvert-autrement | service `Ged\Socle\Relations` | `MfilesRelationsVecteursTest::testRejoueLesVecteursDeLaCompositionEtDeLIntegrite` |

### Tests

| Mesure | Valeur |
| --- | --- |
| Suite PHPUnit complète | **937 tests, 17 975 assertions, 1 sauté** (avant le lot : 925 / 17 889) |
| Dont `RelationsTest` | 10 tests, 66 assertions |
| Dont `MfilesRelationsVecteursTest` | 2 tests, 20 assertions (13 vecteurs) |
| Oracle de porte | `php tests/oracles/relations/porte.php` → **7/7 clauses** |
| Parcours Playwright `relations.spec.ts` | **2/2** |
| PHPStan niveau 6 | **0 erreur** (baseline vide inchangée) |

### Harness complet `-SansBanc`

_(renseigné après le passage vert — voir ci-dessous.)_

## `mfiles:*` restants proposés au juge (63, statut `absent`)

Aucune exclusion n'est posée par l'agent. Les éléments de la famille relations restants sont laissés
`absent` et **proposés** :

| Élément | Raison |
| --- | --- |
| GET `…/history` (Historique d'un objet) | Non revendiqué à ce lot : l'historique GEDv2 (versions, déplacements, journal de relations) existe, mais l'endpoint M-Files couvre tout l'objet et un autre lot l'a rattaché à d'autres familles. Laissé `absent`, proposé au juge. |
| `POST …/files`, `…/preview`, titres, commentaires, checked-out, workflowstate | Hors famille relations (fichiers, aperçus, édition, workflow). |

Le reste des 63 `mfiles:*` absents est inchangé depuis le lot `ged-mfiles-classes-1` (session, serveur,
repositories, favorites, sharedlinks, workflows, `views/path`, icônes…) ; aucun n'a été exclu par
l'agent.

## Porte qualité (ECC)

- **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur**, baseline
  vide inchangée ; `composer audit` sans vulnérabilité (couche 8 du harness).
- **Revue `php-reviewer`** : `declare(strict_types=1)` partout ; méthodes courtes ; écriture des
  `.meta.json` atomique (temporaire puis renommage, comme `Registre`) ; aucune donnée réelle ; le
  service ne déplace ni ne supprime jamais un fichier.
- **Revue `security-reviewer`** : la route exige `documents:lire` et passe par la garde CSRF centrale ;
  la lecture des deux extrémités d'un lien est vérifiée (`DroitsDossiers`) — un lien ne révèle jamais le
  titre d'une pièce hors des droits ; `lignePar()` n'interpole qu'une **colonne whitelistée**
  (`empreinte`/`chemin_relatif`), la valeur est paramétrée ; `cheminAbsolu()` refuse `..` et les chemins
  absolus (pas d'évasion du fonds) ; Twig échappe automatiquement ; aucun secret.
- **Revue `silent-failure-hunter`** : chaque erreur se nomme (deux documents distincts, type inconnu ou
  en veille, cible introuvable, doublon, hors droits, lien inconnu) ; la base muette est **tracée** par
  `error_log` avant le repli sur le dossier ; aucune `catch` vide ; un `GET` n'écrit jamais
  (`assurer()` n'écrit que sur mutation, la graine est rendue en mémoire à la lecture).
- **TDD** : les oracles (`RelationsTest`, vecteurs, porte) ont été écrits avant le code et vus rouges.

## Lignes hors secteur (signalées)

- `src/Ui/Document.php`, `src/Ui/Ecrans.php`, `src/Ui/Module.php` : onglet Relations de la fiche, route
  `POST /documents/{id}/relations`, filtrage des liens hors droits.
- `src/Securite/DroitsRoutes.php` : permission `documents:lire` de la route et son résolveur par chemin.
- `templates/ecrans/document.twig` : 7e onglet « Relations ».
- `tools/banc/sonde-securite.php` : charge CSRF défensive de la route.
- `tools/parite/corriger-correspondance-mfiles.php` : section famille 2 (ids → couvert).

Toutes sont notées dans `docs/secteurs/relations.md` (« Modifications minimales signalées »).

ETAT: en cours
