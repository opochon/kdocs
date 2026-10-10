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
  (`tests/php/Securite/DeplacementDroitsTest.php`, les 5 refus attendus ne produisaient rien), puis
  `DepotFichiers::exigerDroitDeDepot()` (résolution du dossier d'arrivée par `DroitsDossiers`,
  `autorise(…, 'deposer')`, héritage + refus par défaut, administrateur permis, compte nul = oracles),
  refus nommé `Securite\RefusDepot` (dossier visé + niveau). Le compte de l'appelant est passé
  explicitement par `ATraiter` (unitaire et lot, refus compté avec sa raison), `ModificationMasse`,
  `Workflows\Moteur`/`Ecouteur` et `EditionPieces` (rangement posé AVANT le contenu). Signature étendue
  dans `Contrats\Depot` (modification partagée signalée).
- `955dea6` — **E2E** : `tests/e2e/droits-deplacement.spec.ts` + `preparer-droits-deplacement.php` ; par
  l'écran, la modification en masse affiche le refus d'arrivée pour le document concerné, et aboutit quand
  le droit de dépôt existe.
- `4908fb9` — **revue ECC** : la cause PDO part au journal (pas à l'écran) ; le lot « À traiter »
  n'attrape que les refus métier.
- `1314117` — journal du secteur `docs/secteurs/droits-securite.md`.

## Modifications minimales hors secteur (règle « un agent = un secteur »)

Le contrôle devait vivre dans le service partagé ; les appelants portent le compte de l'appelant.

- `src/Contrats/Depot.php` (contrat partagé) : `mettreAJour` reçoit `?int $compte`, `bool $administrateur`.
- `src/Socle/DepotFichiers.php`, `src/Socle/ModificationMasse.php` : contrôle unique + compte.
- `src/Ui/ATraiter.php`, `src/Ui/Module.php` : session → service.
- `src/Workflows/Moteur.php`, `Ecouteur.php`, `Module.php` : compte jusqu'au service (système = nul).
- `src/Ingestion/EditionPieces.php` : `compte`/`administrateur` ; `appliquer()`/`annuler()` posent le
  rangement avant le contenu.
- `tests/php/Ingestion/PiloteTest.php` : le double `DepotSimule` suit la signature (aucune assertion
  touchée).
- `tests/php/Socle/ModificationMasseTest.php` : le contrôle d'arrivée exige `deposer` sur `Sans_date/…`
  (métadonnées de test sans date) ; le setup accorde ce droit et le nettoyage retire les règles par
  groupe. Aucune assertion affaiblie.

## Preuves exactes

- Oracle rouge d'abord : 5 refus sur 8 tests, puis **`DeplacementDroitsTest` VERT : 9 tests, 82
  assertions** (service/fiche, A traiter unitaire, lot avec refus compté, modification en masse,
  workflow, retouche de pages, et les acceptations avec `deposer` sur l'arrivée).
- PHPUnit complet : **488 tests / 2833 assertions / 1 skipped**, vert.
- `tests/e2e/droits-deplacement.spec.ts` : **2 parcours verts**.
- `EditionPiecesTest` : 1 test / 19 assertions vert ; `ModificationMasseTest` : 4 tests / 90 assertions vert.

## Porte qualité (ECC)

- PHPStan niveau 6 : **0 erreur**, `phpstan-baseline.neon` vide et inchangée.
- `php tools/composer.phar audit --locked --no-interaction` : **aucune vulnérabilité**.
- Revue du diff contre `php-reviewer`, `security-reviewer` et `silent-failure-hunter` : SQL de contrôle
  entièrement paramétré (aucune concaténation d'entrée), fail-closed sur schéma d'ACL indisponible avec
  cause technique journalisée (jamais rendue à l'écran), aucun `catch` avalé — le seul `catch` de lot
  convertit un refus métier en refus nommé rendu au bilan, aucune erreur de programmation masquée,
  `declare(strict_types=1)` sur les fichiers neufs, fonctions courtes. Aucun constat critique ou élevé.

## Harness `-SansBanc` (rejeu propre, registre du 10-10 23:59, 879 s, commit `1314117`)

- `0-garde-fous` VERT.
- `1-unitaire` VERT : **488 tests / 2833 assertions / 1 skipped**.
- `2-contrat` VERT (toutes les routes déclarées couvertes).
- `7-securite` VERT (sonde : aucun contournement accepté).
- `6-charge` VERT : 3 puis 10 utilisateurs, p95 lecture **872 ms**, écriture **295 ms**, 0 erreur,
  ingestion finie.
- `8-statique` VERT : PHPStan **0 erreur** hors baseline, audit sans vulnérabilité.
- `5-personas` VERT : 6/6 personas (4/4, 4/4, 4/4, 4/4, 4/4, 3/3).
- `3-banc` ROUGE admis (`sautée (-SansBanc)`).
- `4-ecrans` : **71 passed / 1 failed**. Le seul rouge est `tests/e2e/budget-apercu.spec.ts` — budget
  « première page du PDF affichée en moins de 1 s », mesuré **1064 à 1140 ms** sous la charge des agents
  (rejoué seul trois fois : deux mesures ~1,07 s puis **un rejeu vert**). C'est le rouge de mesure déjà
  consigné par les lots précédents (« machine chargée, à reprendre en V2-10 au repos »), hors du diff de
  ce lot (le lot ne touche ni l'aperçu ni la visionneuse). Le parcours `droits-deplacement` fait partie
  des 71 verts.

ETAT: fini
