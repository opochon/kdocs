# HARNESS — la maturité se mesure, elle ne se déclare pas

Agent : Claude Code · Juge : chaque secteur le rejoue · Commande : `powershell -File tools\harness.ps1`

## Règles
- Une couche absente vaut ROUGE. Une ligne de matrice sans test qui l'exécute vaut 0.
- Le registre `recette/registre.json` n'est écrit que par le harness, jamais à la main.
- Les tests n'écrivent que dans `gedv2_test` et un fonds de test (`phpunit.xml` et `playwright.config.ts` imposent
  `DB_NAME`, `STORAGE_ROOT`, `APP_ENV`, et le port 8871) : un `.env` local ne peut pas les détourner.
- Aucun appel réseau dans les couches 1 et 4 (IA, IMAP, K-Time simulés) ; le réseau réel n'est touché qu'en couche 3.

## Couches
| # | Couche | Ce qu'elle prouve | État 29-09 |
|---|---|---|---|
| 0 | garde-fous | dépendances, base de test imposée, aucune donnée réelle, aucun `DELETE` hors liste | VERT |
| 1 | unitaire | PHPUnit, chaque secteur (contrats, pilote, IMAP contre faux serveur, aperçu…) | VERT |
| 2 | contrat | chaque implémentation d'un `Ged\Contrats\*` passe la même suite de contrat | à écrire |
| 3 | banc | corpus + vérité : découpe (`mesurer-decoupe.php`, 53/53), classement, rejeu complet sur base vierge | à brancher (outil prêt) |
| 4 | écrans | Playwright : parcours clavier, clair/sombre, 0 erreur console, captures | VERT |
| 5 | personas | parcours métier bout à bout (comptable, secrétariat, direction) | à écrire |
| 6 | charge | 10 000 documents, 3 puis 10 utilisateurs (2-3 écrivains), tâche d'ingestion en fond, p95 bornés | branchée — ROUGE mesurée (p95 lecture 5,5 s) |
| 7 | sécurité | droits refusés par défaut, CSRF, en-têtes, jetons, pas de fuite de secret | à écrire |

## Lot suivant (Claude Code)
1. Couche 3 : `harness.ps1` lance `mesurer-decoupe.php` (si le moteur est présent, sinon ROUGE nommé « moteur absent »).
2. Couche 2 : suite de contrat `Depot` (dépôt → `.meta.json` → version 1 → index reconstructible) jouée contre
   `Ged\Socle\DepotFichiers` dès qu'il existe.
3. Couche 0 : refuser tout test qui touche une base autre que `gedv2_test`.

## Jugement sur le code commité (29-09)
Le vestiaire est partagé par plusieurs agents : un travail non commité peut casser le harness des autres. Le juge rejoue
donc depuis une copie de travail séparée, sur HEAD :
`git worktree add --detach ..\GEDv2-juge HEAD` (`vendor` COPIÉ puis `php tools/composer.phar dump-autoload` — une jonction ferait charger le `src/` du vestiaire, vu le 29-09 —, jonction vers `node_modules`, `.env` copié), puis
`$env:GED_PORT_TESTS='8872'; powershell -File ..\GEDv2-juge\tools\harness.ps1`. Le verrou de `gedv2_test` vit dans le
dossier temporaire du système : juge et agents s'attendent au lieu de s'effacer les tables.

## Modifications minimales signalées
- V2-03-écran (Interface) : la sonde itère `DroitsRoutes::declarees()` et exige une charge CSRF défensive par mutation.
  L'écran « À traiter » ajoutant `POST /a-traiter/{id:[0-9]+}/valider`, la charge correspondante a été posée dans
  `tools/banc/sonde-securite.php` (chemin de la couche 7). Une seule entrée ajoutée, aucune règle modifiée.

## Journal H-6 — couche « 6-charge » — 29-09-2026

**Fait**
- `tools/banc/charge.php` : fonds synthétique de 10 000 documents préparé dans `var/charge/` (jamais dans
  `tests/corpus`), base `gedv2_test` sous le verrou partagé. Charge jouée en deux phases, 3 puis 10 utilisateurs
  simultanés, dont 2 puis 3 écrivains qui valident dans « À traiter » ; tous ouvrent la bibliothèque, cherchent et
  ouvrent un aperçu (`/bibliotheque`, `/recherche?q=…`, `/a-traiter`, `/documents/{id}/apercu`,
  `POST /a-traiter/{id}/valider`).
- Vraie concurrence sous Windows : 10 serveurs `php -S` isolés (un par utilisateur) pilotés par `curl_multi`,
  autour de la même base MariaDB et du même fonds. Sessions et CSRF par utilisateur (jeton rotatif suivi).
- Ingestion concurrente : `php tools/tache.php ingestion --budget=300 --tours=200` sur le lot `courrier-100p`,
  lancée avant la charge et suivie jusqu'à sa fin. Preuves publiées :
  `recette/preuves/charge/charge.json` + `charge.md`.
- Branchement minimal de `tools/harness.ps1` : la couche 6 est mesurée (la ligne « pas encore écrite » est
  remplacée), aucune autre règle modifiée.

**Mesures (10 000 documents, ingestion en fond, 0 erreur, 0 collision)**

| Phase | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|
| 3 utilisateurs (2 écrivains) | 4 371 ms | 2 863 ms | 0 |
| 10 utilisateurs (3 écrivains) | 5 518 ms | 2 592 ms | 0 |

Par écran à 10 utilisateurs (p50 / p95) : Bibliothèque 172 / 627 ms ; Recherche 1 936 / 2 940 ms ;
À traiter 5 195 / 5 518 ms ; Aperçu 1 833 / 2 058 ms ; Validation 988 / 2 592 ms.

Collisions : 100 validations attendues, 100 valides (fichier + index), 0 perdue, 0 doublée, 0 verrou mort.
Ingestion : finie en 137 s, 53/53 pièces rangées, 0 travail restant, 0 erreur, 53 documents déposés par le Socle.

**Porte : NON FRANCHIE.** Tiennent : écriture (p95 < 3 s), 0 erreur 500/CSRF, 0 collision, ingestion finie.
Ne tiennent pas : p95 lecture (seuil 1,5 s) pour `/a-traiter`, `/recherche` et `/documents/{id}/apercu`, qui
parcourent tout le fonds à chaque requête. Défauts nommés et consignés en Demandes :
- Interface : `Ged\Ui\ATraiter::documents()` (`DepotFichiers::lister()` + `is_file` par document) ;
- Recherche & aperçu : `IndexPleinTexte::synchroniser()` à chaque recherche, `Apercu\Source::parId()` → `toutes()` ;
- Ingestion : `tache.php` s'arrête au premier tour à vide alors que `SourceDossier` exige deux relevés, et le lot
  parent ne quitte jamais l'état `decoupe` (compté « en cours de lecture » à tort).

ETAT: bloqué: p95 lecture mesuré à 5,5 s à 10 000 documents (seuil 1,5 s) — trois défauts sectoriels nommés et inscrits en Demandes (Interface, Recherche & aperçu, Ingestion) ; écritures, collisions et ingestion tiennent.

## Journal H-2 — 29-09-2026

- Oracles abstraits ajoutés sous `tests/contrats/`, inclus dans PHPUnit : `Depot` (`DepotFichiers`), `Classeur`
  (`ClasseurGed`) et `Ingestion\Source` (`SourceDossier`, `SourceAdresse`, `SourceApi`). Ils vérifient respectivement
  fonds auto-descriptif + `.meta.json` + v1 + index, preuve obligatoire et refus de « Autre », et rejeu sans doublon.
- `tools/banc/contrat.php` rejoue ces oracles puis démarre le serveur PHP de test, appelle chaque route de
  `DroitsRoutes` avec un compte synthétique lorsque la session est requise, et contrôle les refus nommés. Les réponses
  202/200 et les erreurs JSON de l'API d'entrée sont confrontées aux schémas de `docs/api/depots.yaml`.
- Preuves : `php vendor/bin/phpunit tests/contrats` vert (8 tests, 49 assertions) ; banc HTTP rejoué sur serveur réel :
  toutes les routes et l'API tiennent, sauf `POST /connexion` sans CSRF (403 mais refus sans `etape`/`cause`). Défaut
  consigné dans `docs/secteurs/droits-securite.md`, rubrique Demandes ; non masqué, donc couche 2 ROUGE tant qu'il vit.
- Le banc impose `DB_NAME=gedv2_test`, utilise le verrou de `tests/bootstrap.php`, et ne supprime aucune table ; ses
  fichiers, sessions et serveur restent temporaires.

ETAT: fini

