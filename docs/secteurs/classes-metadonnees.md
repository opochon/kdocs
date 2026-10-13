# Secteur CLASSES & MÉTADONNÉES — classes d'objets, carte dynamique, listes de valeurs nommées

Agent : DeepCode (lot `ged-mfiles-classes-1`) · Juge : Claude sur le poste principal.

## Mission

Porter la **famille M-Files nº 1** (`docs/PLAN-GEDV2.md`, `docs/parite/PARITE-MFILES.md`) :

- **classes d'objets** : un document porte une classe (le « type » de la nomenclature) qui détermine
  sa carte de métadonnées ; la définition est de la **configuration portable** écrite dans le fonds
  (`.ged/nomenclature.json`, `.ged/listes.json`), donc versionnable et reconstruisible — pas de coffre
  propriétaire ;
- **métadonnées dynamiques** : la carte affichée/saisie dépend de la classe ; les VALEURS vivent dans
  le `.meta.json` à côté du document (le dossier fait foi) ; validation par type, obligatoire/optionnel,
  et appartenance à une liste nommée ;
- **listes de valeurs nommées** : vocabulaires contrôlés réutilisables (Statut, Type de pièce, Exercice)
  qu'une propriété référence ; CRUD des listes et des valeurs, **intégrité** : une valeur retirée reste
  traçable (jamais effacée du dossier).

## Chemins (seuls modifiables par ce secteur)

`src/ClassesMetadata/` (carte dynamique, résolution d'alias, définition portable, cartes de masse).

Métadonnées du fonds, **partagées avec le Socle** et étendues par ce lot (additions rétro-compatibles,
les oracles V2-04 restent verts) :

`src/Socle/Nomenclature.php`, `src/Socle/Champs.php`, `src/Socle/Listes.php` (neuf),
`src/Socle/EcranMetadonnees.php`, `templates/ecrans/metadonnees.twig`,
`templates/ecrans/bibliotheque.twig` (marque des propriétés obligatoires seulement).

Preuves : `tests/php/ClassesMetadata/`, `tests/php/Socle/ListesTest.php`,
`tests/php/Socle/ChampsTest.php` (additions), `tests/oracles/classes-metadonnees/`,
`tests/parite/vecteurs/mfiles-classes-*.json`, `tests/php/Parite/MfilesClasses*.php`,
`tests/e2e/classes-metadonnees.spec.ts`, `tests/e2e/preparer-classes-metadonnees.php`.

Oracle M-Files (mêmes conventions que le lot `ged-mfiles-oracle-1`) :
`tools/parite/corriger-correspondance-mfiles.php`, `docs/parite/correspondance.json`,
`tools/parite/juge-mfiles.php`.

## Modifications minimales signalées (hors chemins du secteur)

- `src/Socle/Module.php` : routes `POST /metadonnees/liste` et `POST /metadonnees/liste/valeur`
  (gestion des listes nommées et de leurs valeurs).
- `src/Securite/DroitsRoutes.php` : permissions `application:administrer` pour ces trois routes.
- `tools/banc/sonde-securite.php` : charges défensives correspondantes (refus métier sans écriture).

## Demandes au Socle

Les fichiers `Nomenclature`, `Champs`, `EcranMetadonnees` et le gabarit `metadonnees.twig` sont des
chemins du Socle. Ce lot les étend (propriété `obligatoire`, référence de propriété à une liste nommée,
écran de gestion des listes) sans changer les contrats existants : `NomenclatureTest` et `ChampsTest`
restent verts. Si le Socle veut reprendre la main, ces additions sont isolées et testées.

## Journal

- 13.10.2026 — ouverture du lot `ged-mfiles-classes-1` (DeepCode) : listes nommées, propriétés
  obligatoires/référençant une liste, carte dynamique et couverture de parité M-Files famille 1.
- 13.10.2026 — livré : `Ged\Socle\Listes` (listes nommées dans `.ged/listes.json`, retrait daté et
  attribué, jamais d'effacement), `Champs` étendu (`obligatoire`, référence `liste`), `Ged\ClassesMetadata\Carte`
  (carte par classe/document, alias, lots, validation) et `Definition` (export/import portable et
  réversible). Écrans : gestion des listes et carte dynamique dans `metadonnees.twig`, marque
  « obligatoire » dans la bibliothèque. Parité M-Files : 44/112 couverts (39,29 %), 0 non-prouvé,
  vecteurs `mfiles-classes-carte` 8/8 et `mfiles-classes-listes` 4/4.

