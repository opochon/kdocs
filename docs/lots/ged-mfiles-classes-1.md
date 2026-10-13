# Lot ged-mfiles-classes-1 — Famille M-Files nº 1 : classes d'objets, métadonnées dynamiques, listes de valeurs nommées

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3308).
Branche : `ged/ged-mfiles-classes-1` · point de départ : `b120847` (v2).

## Objectif

Faire passer de `absent` à `couvert` / `couvert-autrement` les éléments `mfiles:*` de la première
famille structurante du modèle M-Files, prouvés par des vecteurs rejoués et des tests, sans toucher
`src/Parite/Juge.php`, la liste fermée d'exclusions, ni les vecteurs/statuts Paperless.

## État d'avancement

### Fait (commité)

- `0bd9ea4` — cadrage du secteur `docs/secteurs/classes-metadonnees.md` (chemins, demandes, journal).
- `ad199ae` — **listes de valeurs nommées** `Ged\Socle\Listes` dans le fonds (`.ged/listes.json` via
  `Registre`) : graine livrée (Statut, Type de pièce, Exercice), CRUD liste/valeur, **retrait daté et
  attribué** (jamais d'effacement), alias, lecture inclusive. 9 tests.
- `ae6ad32` — **propriétés obligatoires et référençant une liste nommée** : `Champs` porte
  `obligatoire` et `liste` (les options viennent alors de la liste), `parId`, `validerDefinition` ;
  `pourType`/`parCle`/`tous` résolvent la liste. 5 tests ajoutés à `ChampsTest`.
- `709d4f2` — **carte dynamique** `Ged\ClassesMetadata\Carte` : carte d'une classe ou d'un document,
  validation (type, obligatoire, liste nommée), résolution d'alias de structure, valeurs de propriété,
  pose par lot, documents d'une classe. 8 tests.
- `d6862d0` — **définition portable et réversible** `Ged\ClassesMetadata\Definition` : export JSON
  versionné, import idempotent, aller-retour à l'identique. 4 tests.
- `a044d71` — **écrans et routes** : `metadonnees.twig` (listes nommées, valeurs tracées, carte de la
  classe choisie, champ obligatoire/liste, export-import de définition), marque « obligatoire » dans
  `bibliotheque.twig` ; routes `POST /metadonnees/liste`, `/metadonnees/liste/valeur`,
  `/metadonnees/definition` (droits, sonde) ; parcours Playwright `classes-metadonnees.spec.ts` 4/4.
- `118c995` — **parité M-Files famille 1** : vecteurs `mfiles-classes-carte` (8) et
  `mfiles-classes-listes` (4) rejoués par `MfilesClassesVecteursTest`, correspondance régénérée
  (**44/112 couverts, 39,29 %**, 0 non-prouvé).
- `1ef0714` — **oracle de porte** `tests/oracles/classes-metadonnees/porte.php` : 7 clauses.
- `6393800` — E2E plus stable (ligne réelle du document, attente large sous charge machine).

### En cours

- Rejeu du harness complet de fin de lot (couche 4 instable sous charge machine : les 4 parcours en
  échec au premier passage repassent 13/13 en isolation).

### Reste

- Décision du juge sur les 68 `mfiles:*` encore `absent` (dont les icônes de classes/types, sans
  équivalent raisonnable en GED, PROPOSÉES à l'exclusion — jamais posées par l'agent).

## Mesures exactes

### Oracle M-Files (juge `tools/parite/juge-mfiles.php`)

| Mesure | Valeur |
| --- | --- |
| Inventaire public M-Files rejoué à l'identique | oui (112 éléments) |
| Couverts (avant lot → après lot) | **9 → 44** |
| Absents (proposés au juge) | 68 |
| Non prouvés | 0 |
| Parité stricte (= hors exclusions) | **8,04 % → 39,29 %** |
| Erreurs du juge | 0 |
| Vecteurs `mfiles-classes-carte` | 8/8 |
| Vecteurs `mfiles-classes-listes` | 4/4 |
| Vecteurs archivage existants (non touchés) | `mfiles-versions` 4/4, `mfiles-garantie` 3/3 |

### Tests

| Mesure | Valeur |
| --- | --- |
| Suite PHPUnit complète | 919 tests, 13 376 assertions, 1 sauté (890 / 13 212 avant le lot) |
| Dont nouveaux tests de la famille | 29 (Listes 9, Champs +5, Carte 8, Definition 4, vecteurs 2, écran +1) |
| Oracle de porte | `php tests/oracles/classes-metadonnees/porte.php` → **7/7 clauses** |
| Parcours E2E `classes-metadonnees.spec.ts` | 4/4 |
| PHPStan niveau 6 | 0 erreur hors baseline (baseline vide, inchangée) |

### Couche 4 du harness — stabilité

Le premier passage complet du harness a vu 4 parcours E2E en échec (dont 3 hors de ce lot :
`a-traiter` 20 s dépassées, `asn` et `collaboration` attentes d'URL 5 s). Rejoués en isolation :
**13/13 verts en 48 s**. Ces échecs sont des effets de charge machine (plusieurs `node`/`php`
d'autres ateliers tournaient en parallèle), pas des défauts produit : le parcours
`classes-metadonnees` et les trois voisins repassent sans changement de code.

## `mfiles:*` restants proposés au juge (68, statut `absent`)

Aucune exclusion n'est posée par l'agent. Répartition par ressource publique M-Files :

| Ressource | Restants | Famille visée |
| --- | ---: | --- |
| `objects/(type)/(objectid)/(version)/…` (fichiers, relations, sous-objets, checked-out, commentaires, historique, aperçus, titre, état de workflow) | 22 | 2 relations, 3/7 recherche, 6 archivage |
| `structure/workflows`, états et transitions | 11 | 4 workflows d'état |
| `session`, `server`, `repositories`, `vaults` | 17 | hors périmètre GED (identité/serveur) — proposé au juge |
| `favorites`, `recentlyaccessedbyme`, `sharedlinks` | 10 | 7 recherche/partage |
| `views/path` (comptage, objets) | 2 | 3 vues dynamiques |
| `files`, `objects/automaticmetadata`, `objects/demoting-objects` | 3 | ingestion / 2 relations |
| icônes de classes/types (`structure/classes-id-icon`, `structure/objecttypes-type-icon`) et `refreshstatus` | 3 | aucun équivalent raisonnable en GED — proposé à l'exclusion |

## Porte qualité (ECC)

- **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur**, baseline
  vide inchangée ; `composer audit` : aucune vulnérabilité.
- **Revue `php-reviewer`** : `declare(strict_types=1)` partout ; méthodes courtes ; résolution d'options
  par le service `Listes` (aucune copie en ligne) ; écritures atomiques et sous verrou du `Registre`
  réutilisées ; aucun SQL concaténé (`Carte::documentsDeClasse` prépare sa requête).
- **Revue `security-reviewer`** : les trois nouvelles routes exigent `application:administrer` et
  passent par la garde CSRF centrale ; le chemin du fichier de définition est construit à partir de la
  racine documentaire (aucune entrée utilisateur) ; aucune donnée réelle, aucun nom de client ;
  le fonds de test vit sous `var/`. `ui` : Twig échappe automatiquement, chaque élément actionnable
  porte un `data-testid`.
- **Revue `silent-failure-hunter`** : toute erreur est nommée (liste inconnue, valeur hors liste,
  propriété obligatoire vide, champ déjà pris) ; un GET n'écrit jamais ; aucune `catch (Throwable)`
  silencieuse ; une valeur retirée reste lisible et affichée sur le document qui la porte.
- **TDD** : les oracles des listes, des propriétés, de la carte et de la définition ont été écrits
  avant le code et vus rouges (5 échecs/erreurs sur `ChampsTest`, 8 erreurs sur `CarteTest`,
  4 sur `DefinitionTest`), puis verts.

ETAT: en cours
