# Lot ged-semblables — parité Paperless : vrais « documents semblables »

Juge : Claude (poste principal). Agent : DeepCode. Branche : `ged/ged-semblables`.

## Contexte (constat du juge, 10.10)

Le lien « Documents semblables » de la Recherche (`templates/ecrans/recherche.twig`) ouvre seulement la
fiche du document (`/bibliotheque?doc=<id>`) : ce n'est pas une recherche de documents semblables,
contrairement à Paperless (`more like this`).

## Méthode

1. Lire la doc MariaDB 11.5 du plein texte (MATCH … AGAINST, mode naturel, `WITH QUERY EXPANSION`, mots
   vides, longueur minimale) et l'index existant (`src/Recherche/IndexPleinTexte`, table `recherche_textes`,
   FULLTEXT MyISAM `ft_recherche`, `ft_min_word_len = 4`).
2. Oracle d'abord (rouge) : un corpus synthétique de trois factures d'un même émetteur et une lettre sans
   rapport ; « semblables » d'une facture doit rendre les deux autres factures en tête et pas la lettre.
3. Moteur dans `src/Recherche/` (termes significatifs du document, requête dans l'index, document lui-même
   exclu, droits `DroitsDossiers::predicatLecture` DANS la requête) ; budget < 1 s sur 10 000 documents.
4. Écran : le lien mène à une liste de semblables, testée E2E par clic réel.

## Fait (commits)

- `d8c99a4` — oracle rouge : `tests/oracles/recherche/semblables.json`, `tests/php/Recherche/SemblablesTest.php`, cette note.
- (en cours) moteur : `src/Recherche/Semblables.php`, `TermesSemblables.php`, `Pertinence.php` (extrait de `Service`).

## En cours

- Moteur vert ; l'écran (mode `?semblable=`) reste à brancher.

## Reste

- Écran : `Ecran::donnees` mode `?semblable=`, gabarit (lien + en-tête), tests Ecran + E2E par clic.
- Budget 10 000 documents < 1 s.
- Porte qualité ECC et harness `-SansBanc`.

## Mesures exactes

- PHPUnit ciblé `tests/php/Recherche` : **23 tests, 128 assertions, vert** (19 existants + 4 semblables).
- PHPStan niveau 6 : **0 erreur** hors baseline (baseline vide, inchangée).

ETAT: en cours

