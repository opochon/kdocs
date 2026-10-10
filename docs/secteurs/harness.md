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

## Journal PERF-1 — 29-09-2026

**Fait** (les trois défauts nommés par le Journal H-6, corrigés sans changer le comportement)
- **Interface** : `Ged\Ui\ATraiter` ne parcourt plus le fonds. La file des `a_controler` est lue par
  `Ged\Socle\DepotFichiers::listerParStatut()` — requête appuyée sur l'index `k_statut (statut, supprime_le)`,
  bornée par `LIMIT` — et le bilan « classés automatiquement » par `compterParStatut()` (COUNT couvert par le
  même index). `Socle\Schema::assurerIndexStatut()` pose l'index sur les fonds créés avant lui (idempotent).
- **Recherche** : `Ged\Recherche\Service::rechercher()` n'appelle plus `IndexPleinTexte::synchroniser()`. L'index
  se construit HORS requête par `tools/indexer-recherche.php` (déjà présent) et par la tâche de fond nouvelle
  `php tools/tache.php indexation [--limite=200]` (lot court, reprenable).
- **Aperçu** : `Ged\Apercu\Source::parId()` résout l'id par l'index documentaire (`documents.id` →
  `chemin_relatif`, `LIMIT 1`) ; le parcours du dossier n'est plus qu'un REPLI quand l'index ne connaît pas la
  pièce (base absente, fonds reconstruit à la main). Le fichier doit toujours exister : le dossier fait foi.

**Oracle d'abord (rouge → vert)**
- `tests/php/Perf/` : fonds synthétique de 2 000 documents (`SupportFondsPerf`), preuve par le compteur d'accès
  `Handler_read_rnd_next` (~6 lignes pour une requête indexée, ~2 000 pour un parcours) et par le temps comparé au
  parcours complet. Rouge mesuré avant correctif : « À traiter » 2 003 lignes ; aperçu au niveau du parcours ;
  recherche vidée par une synchronisation en requête (fonds éloigné = index marqué absent).
- Tests existants inchangés et verts : `tests/php/Recherche` 19/19 ; `SourceTest` et `MiniatureTest` verts.

**Mesures (10 000 documents, ingestion en fond, 0 erreur, 0 collision, porte FRANCHIE)**
| Phase | p95 lecture | p95 écriture |
|---|---|---|
| 3 utilisateurs | 470 ms (avant 4 371 ms) | 139 ms |
| 10 utilisateurs | 762 ms (avant 5 518 ms) | 113 ms |

Par écran à 10 utilisateurs (p50 / p95) : Bibliothèque 266 / 490 ms ; Recherche 376 / 762 ms (avant 2 940) ;
À traiter 228 / 370 ms (avant 5 518) ; Aperçu 151 / 310 ms (avant 2 058) ; Validation 74 / 113 ms.
Ingestion : 53/53 pièces rangées, 0 travail restant. Preuves : `recette/preuves/charge/charge.{json,md}`.

**Harness complet** (rejoué sur la copie propre du HEAD commité `e7ee3c7` — le vestiaire partagé porte en plus
des travaux non commités d'autres agents, qui ne permettent pas de conclure depuis la racine)
- VERT : `0-garde-fous` ; `1-unitaire` (223 tests, 1 036 assertions) ; `3-banc` (découpe 53/53, classement,
  bout-en-bout) ; `4-ecrans` (29 parcours) ; `7-securite` ; `6-charge` (porte **FRANCHIE**, p95 lecture 762 ms).
- ROUGE **hors PERF-1** : `5-personas` — « pas encore écrite » (couche à venir).
- `2-contrat` était ROUGE pendant le run PERF-1 (`POST /connexion` refus non nommé, défaut Droits & sécurité
  consigné au Journal H-2) ; le correctif Droits `97a9c4d`, arrivé après, le rend VERT : le banc
  `tools/banc/contrat.php` rejoué sur le HEAD final `a73fe86` conclut « toutes les routes déclarées sont couvertes ».
- L'oracle Playwright « recherche » joue désormais le job d'indexation hors requête (comme le planificateur) :
  sans cela, la suppression de la synchronisation en requête vidait l'index des trois parcours de recherche.

**Fichiers touchés** : `src/Socle/DepotFichiers.php`, `src/Socle/Schema.php`, `src/Ui/ATraiter.php`,
`src/Recherche/Service.php`, `src/Apercu/Source.php`, `tools/tache.php`, `tests/php/Perf/`,
`tests/php/Recherche/SupportRecherche.php`, `tests/e2e/recherche.spec.ts`. Rien hors de ces chemins ; le bloc
`Socle\Schema` d'un agent voisin, présent non commité dans le vestiaire, a été laissé tel quel (hors commit).

ETAT: fini

## Jugement H-2, H-6, PERF-1 et V2-07d (Claude Code, 30-09) — portes franchies
Rejoué sur le code commité (copie du juge, 120e8af) : 0 garde-fous, 1 unitaire (224 tests), 2 contrat (toutes les routes
déclarées, API conforme à `docs/api/depots.yaml`), 3 bancs, 6 charge (10 utilisateurs, 10 000 documents : p95 lecture
450 ms au lieu de 5,5 s, écriture 161 ms, 0 erreur, 0 collision, ingestion finie) et 7 sécurité : **VERTES**.
Écrans 28/29 : le seul rouge restant était « première page PDF < 1 s » (1,1 s), mesuré à froid sur une machine chargée.
Décision harness : deux budgets — ouverture à froid < 2 s, ouverture courante < 1 s (test modifié en conséquence).
Couche 5 (personas) : seule couche encore non écrite.

## Journal lot ged-parite-masse (10-10)

- `tools/banc/sonde-securite.php` : 2 charges CSRF défensives pour les routes du lot
  (`POST /bibliotheque/masse` avec `action=inconnue`, `POST /bibliotheque/masse/{id}/tranche` sans tâche).
  La sonde valide que CSRF absent/rejoué est refusé et que la charge métier laisse le fonds intact ; elle
  reste **VERTE**. Fichier partagé signalé aussi dans `docs/secteurs/droits-securite.md`.
- Oracle E2E `tests/e2e/parite-masse.spec.ts` (5 parcours, serveur et fonds isolés) : modification en
  masse par l'écran (cocher, choisir, confirmer, bilan, liste, fiche), sélection « tout le résultat
  filtré » au-delà de la page, et parcours des 4 écrans jamais visités **par le menu** (groupes, rôles,
  partagés avec moi, catalogue des workflows). Préparation : `tests/e2e/preparer-parite-masse.php`,
  comptes documentés dans `tests/e2e/README.md`.
- Le contrat HTTP (`tools/banc/contrat.php`) reste VERT : les deux nouvelles routes sont déclarées et
  refusent nommément une charge invalide (pas de 500).
