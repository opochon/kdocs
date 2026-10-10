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
- `ad98bcb` — moteur : `src/Recherche/Semblables.php`, `TermesSemblables.php`, `Pertinence.php` (extrait de `Service`).
- (en cours) écran : `Ecran::donnees` mode `?semblable=`, gabarit (lien + en-tête), tests Ecran + E2E par clic.

## En cours

- Écran branché et testé (PHPUnit + E2E vert) ; reste le budget 10 000 documents.

## Reste

- Budget 10 000 documents < 1 s (mesure dédiée).
- Porte qualité ECC et harness `-SansBanc`.

## Mesures exactes

- PHPUnit ciblé `tests/php/Recherche` : **23 tests, 128 assertions, vert** (moteur seul) puis **26 tests, 144 assertions, vert** (avec l'écran).
- Playwright `tests/e2e/recherche.spec.ts` (projet `parcours`) : **4 passed** dont le clic « Documents semblables » (8,5 s).
- PHPStan niveau 6 : **0 erreur** hors baseline (baseline vide, inchangée).

## Décision de mode (doc MariaDB 11.5)

Le mode naturel de MyISAM écarte les mots présents dans 50 % des lignes et `WITH QUERY EXPANSION`
construit son expansion depuis un top de lignes : sur un fonds de factures qui partagent « facture »,
la liste dériverait. `Semblables` interroge donc le même index FULLTEXT (`ft_recherche`) en BOOLEAN MODE
(pas de seuil de 50 %), avec les termes significatifs du document (`ft_min_word_len = 4`, mots vides
filtrés localement), l'émetteur en bonus, et un partage minimal de deux termes pour écarter un mot
fourre-tout isolé.

ETAT: en cours


