# Lot ged-edition-serveur — côté serveur de l'édition depuis le poste (F13)

Branche `ged/ged-edition-serveur` (worktree `C:\ged\wt\ged-edition-serveur`), juge : Claude sur le poste
principal. Cahier des charges : `docs/secteurs/edition-poste.md`.

Mission : ouvrir un document de la GED depuis le poste (`kdocs://ouvrir/<id>`), téléverser une nouvelle
version avec la version de base (conflit signalé, jamais d'écrasement), signaler ouverture et fermeture,
déposer et récupérer une copie de secours hors du fonds, afficher « ouvert par X sur POSTE depuis HH:MM ».
Aucune nouvelle table (plafond 21), aucune DDL hors `tools/installer.php`.

## Fait

- Lecture du cahier des charges, du plan, de la porte qualité ECC, du code existant (Jonctions, Socle,
  Ui, Droits, harness, patrons PHPUnit/Playwright).
- Note d'environnement : dans ce worktree, `vendor` était une **jonction** vers `C:\ged\GEDv2\vendor` ;
  l'autoloader Composer résolvait donc `C:\ged\GEDv2\src` et les tests chargeaient le dépôt principal.
  Correction locale (hors dépôt, `vendor` est gitignoré) : la jonction a été remplacée par une copie,
  l'autoloader pointe désormais sur `C:\ged\wt\ged-edition-serveur\src`.
- `docs/secteurs/edition-poste.md` : rubriques « Chemins » (périmètre du lot) et « Demandes »
  (contrat HTTP de l'agent local) ajoutées.

## En cours

- Oracle PHPUnit du secteur `Edition` (rouge d'abord).

## Reste

- Implémentation `src/Edition/`, UI (bouton, ouvert par, copies de secours), E2E, harness, porte qualité.

## Mesures

- Base : `gedv2_test` (127.0.0.1:3308). `php vendor/bin/phpunit tests/php/Jonctions/DepotApiTest.php` :
  OK, 7 tests / 50 assertions (état de départ du worktree).
