# Secteur RELATIONS — liens typés entre objets, composition parent-enfant, références croisées

Agent : DeepCode (lot `ged-mfiles-relations-1`) · Juge : Claude sur le poste principal.

## Mission

Porter la **famille M-Files nº 2** (`docs/PLAN-GEDV2.md`, `docs/parite/PARITE-MFILES.md`) :

- **relations entre objets** : un document peut être LIÉ à d'autres ; les liens sont TYPÉS (Référence,
  Dépendance, Affectation, Sous-objet — les types publiés par la documentation M-Files) et navigables
  **dans les deux sens** (une fiche montre ses liens sortants ET les liens dont elle est la cible) ;
- **parent-enfant / hiérarchie** : composition « un dossier contient des pièces ; une pièce appartient à
  un dossier », **cohérente avec l'arborescence du fonds**. La relation ne recopie pas le système de
  fichiers : le contenant d'un dossier se lit du CHEMIN (le dossier fait foi) ; la relation `sous-objet`
  complète le FS pour les compositions qui ne sont pas des dossiers ;
- **références croisées** : intégrité — une relation vers un objet **déplacé** se retrouve par son
  empreinte, une relation vers un objet **supprimé** (corbeille ou disparu) reste TRACABLE (dernier
  titre et dernière empreinte connus), jamais un lien cassé en silence.

Les relations sont de la MÉTADONNÉE : les **définitions** sont portables et vivent dans le fonds
(`.ged/relations.json`, via `Registre`, comme la nomenclature et les listes) ; les **valeurs** vivent par
document dans le `.meta.json` à côté du fichier (reversibilité, portabilité Windows/Linux, aucune table
nouvelle : le plafond de 20 tient). Écran : voir / ajouter / retirer une relation depuis la fiche
document, et naviguer vers l'objet lié. Droits cohérents (permission `documents:lire`, comme notes/liens).

## Chemins (seuls modifiables par ce secteur)

- `src/Socle/Relations.php` (neuf) : service des relations typées du fonds.
- Preuves : `tests/php/Socle/RelationsTest.php`, `tests/php/Parite/MfilesRelationsVecteursTest.php`,
  `tests/parite/vecteurs/mfiles-relations-*.json`, `tests/oracles/relations/porte.php`,
  `tests/e2e/relations.spec.ts`, `tests/e2e/preparer-relations.php`.
- Oracle M-Files : `tools/parite/corriger-correspondance-mfiles.php` (section famille 2),
  `docs/parite/correspondance.json`.
- Journal du lot : `docs/lots/ged-mfiles-relations-1.md`.

## Modifications minimales signalées (hors chemins du secteur)

- `templates/ecrans/document.twig` : 7e onglet « Relations » de la fiche (liste typée bidirectionnelle,
  ajout, retrait, navigation vers l'objet lié).
- `src/Ui/Document.php` et `src/Ui/Ecrans.php` : passage des relations et de leurs définitions au gabarit
  (la fiche reste le contrat visuel, aucune autre donnée ne change).
- `src/Ui/Module.php` : route `POST /documents/{id:[0-9]+}/relations` (lier / retirer / détacher).
- `src/Securite/DroitsRoutes.php` : permission `documents:lire` de cette route (comme notes et liens).
- `tools/banc/sonde-securite.php` : charge CSRF défensive de la nouvelle route (refus métier sans écrire).

## Demandes au Socle / à Interface

- `src/Socle/Relations.php` s'appuie sur `DepotFichiers` (racine, chemins, index) et sur `Registre`
  (configuration portable) sans modifier leurs contrats.
- La fiche document est un chemin du secteur Interface ; les additions (onglet Relations) sont isolées,
  testées et signalées ci-dessus. Si Interface reprend la main, aucun contrat existant ne change.

## Journal

- 13.10.2026 — ouverture du lot `ged-mfiles-relations-1` (DeepCode) : relations typées bidirectionnelles,
  composition parent-enfant, intégrité des références croisées, écran fiche et parité M-Files famille 2.
