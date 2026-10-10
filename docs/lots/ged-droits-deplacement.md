# Lot ged-droits-deplacement — F10 : un changement de métadonnées n'ouvre jamais un dossier sans droit de dépôt

Secteur porteur : Droits & Sécurité. Juge : Claude (poste principal). Agent : DeepCode.

## Mission rappelée

Un changement de métadonnées (`type`, `émetteur`, `date`, `dossier` explicite) recalculait le rangement
et DÉPLAÇAIT le fichier. Les droits étaient vérifiés sur le dossier de DÉPART (`modifier`), jamais sur le
dossier d'ARRIVÉE : un manager avec `modifier` sur A pouvait faire entrer un document dans B (par la
fiche/service, « À traiter », la modification en masse, un workflow) sans y avoir aucun droit.

Règle appliquée : toute écriture qui change le CHEMIN exige le niveau `deposer` sur le dossier de droits
d'ARRIVÉE (héritage du parent compris, refus par défaut sans règle) ; administrateur toujours permis. Le
contrôle vit à UN seul endroit, dans le service, AVANT le premier octet déplacé ; le compte de l'appelant
est passé explicitement. Un refus est nommé (dossier visé, niveau manquant) et ne bouge rien.

## Fait (commits)

- `9fd64e7` — **cœur + oracles** : oracle rouge d'abord
  (`tests/php/Securite/DeplacementDroitsTest.php`, 5 refus attendus non produits), puis
  `DepotFichiers::mettreAJour` exige `deposer` sur le dossier d'arrivée via `exigerDroitDeDepot` (héritage,
  refus par défaut, administrateur permis, compte nul = oracles), refus nommé `Securite\RefusDepot`
  (dossier visé + niveau). Le compte de l'appelant est passé explicitement par `ATraiter` (unitaire et lot,
  refus compté avec sa raison), `ModificationMasse`, `Workflows\Moteur`/`Ecouteur` et `EditionPieces`.
  `EditionPieces` pose le rangement AVANT le contenu : un refus ne remplace rien et n'archive pas.
  Signature étendue dans `Contrats\Depot` (modification partagée signalée).
- (commit E2E) `tests/e2e/droits-deplacement.spec.ts` + `preparer-droits-deplacement.php` : par l'écran,
  la modification en masse affiche le refus d'arrivée pour le document concerné, et aboutit quand le droit
  de dépôt existe.

## Modifications minimales hors secteur (règle « un agent = un secteur »)

Le contrôle devait vivre dans le service partagé ; les appelants portent le compte de l'appelant.

- `src/Contrats/Depot.php` (contrat partagé, d'ordinaire réservé à la vue d'ensemble) : `mettreAJour`
  reçoit `?int $compte = null, bool $administrateur = false`. Signalé au journal du secteur.
- `src/Socle/DepotFichiers.php`, `src/Socle/ModificationMasse.php` : contrôle unique et passage du compte.
- `src/Ui/ATraiter.php`, `src/Ui/Module.php` : session → service (`compte`, `administrateur`).
- `src/Workflows/Moteur.php`, `Ecouteur.php`, `Module.php` : le compte suit jusqu'au service ; un événement
  système garde le compte nul.
- `src/Ingestion/EditionPieces.php` : constructeur `compte`/`administrateur` ; `appliquer()` et `annuler()`
  posent le rangement avant le contenu. Signalé dans `docs/secteurs/ingestion.md` (journal).
- `tests/php/Ingestion/PiloteTest.php` : le double `DepotSimule` suit la signature du contrat (aucune
  assertion touchée).
- `tests/php/Socle/ModificationMasseTest.php` : le contrôle d'arrivée exige `deposer` sur le dossier
  d'arrivée `Sans_date/…` (métadonnées de test sans date) ; le setup accorde ce droit et le nettoyage
  retire les règles par groupe. Aucune assertion affaiblie.

## Reste

- Porte qualité ECC et `harness.ps1 -SansBanc` vert sur les couches touchées.
- Journal du secteur `docs/secteurs/droits-securite.md` à compléter (fait au moment du lot).

## Mesures exactes

- Oracle rouge d'abord : 5 refus sur 8 tests, puis **`DeplacementDroitsTest` VERT : 9 tests, 82 assertions**.
- PHPUnit complet : **488 tests / 2833 assertions / 1 skipped**, vert.
- PHPStan niveau 6 : **0 erreur**, baseline vide inchangée.
- `tests/e2e/droits-deplacement.spec.ts` : **2 parcours verts** (21,5 s).
- `EditionPiecesTest` : 1 test / 19 assertions, vert après réordonnancement.
- `ModificationMasseTest` : 4 tests / 90 assertions, vert après adaptation du jeu de droits.
