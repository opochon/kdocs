# Lot ged-sim-corpus-1 — espace de simulation réaliste (données synthétiques)

Agent : DeepCode · Juge : Claude (poste principal) · Branche : `ged/ged-sim-corpus-1`

But : doter le juge d'un **espace de simulation volumineux et réaliste** pour mesurer recherche,
classement et parité sans jamais introduire de donnée réelle. Le corpus est un **arbre de dossiers
sur disque** (le dossier fait foi), **régénéré** depuis un générateur à graine fixe ; seuls le
générateur et la **vérité** sont commités.

## Fait (commits)

- `5025b7b` — oracle rouge : `tests/php/Sim/VeriteCorpusTest.php` (contrat de la vérité versionnée :
  volumétrie 150–300 documents, gros PDF, classement attendu complet, requêtes référençant des
  documents connus).
- `1dbcebd` — `tools/sim/generer-corpus.py` + `recette/sim/verite-corpus.json` :
  **237 documents, 1 240 pages, 6 gros PDF (≥ 50 pages), 35 e-mails `.eml` à pièces jointes**,
  graine fixe `20261013`, corpus **reproductible octet pour octet** (PDF `no_new_id` + métadonnées
  figées, `.eml` à frontière MIME et `Message-ID` déterminés). Aucune donnée réelle, organisations
  fictives (SA/Sàrl/GmbH/Commune/Office…), langues fr majoritaire + de/it/en.

## Fait — banc de mesure et branchement harness

- `tools/banc/mesurer-sim-corpus.php` : ingère le corpus dans `gedv2_test` par le VRAI
  `Ged\Socle\DepotFichiers` (dossier fait foi), indexe hors requête, classe par `Ged\Classement\ClasseurGed`
  (règles, sans IA ni réseau) et interroge `Ged\Recherche\Service`, puis compare à
  `recette/sim/verite-corpus.json`. La vérité régénérée doit être **identique** à celle du dépôt, sinon
  le banc s'arrête nommément. Régénération prouvée : `var/sim` supprimé puis reconstruit par le banc.
- `tools/harness.ps1` (couche 3 « banc ») : 4ᵉ banc `sim-corpus` branché (fichier partagé, modification
  minimale signalée).
- Preuves versionnées : `recette/preuves/sim/corpus.{json,md}`.

## Mesures (porte FRANCHIE)

| Mesure | Seuil | Obtenu |
|---|---|---|
| Documents ingérés dans `gedv2_test` | 150–300 | **237** |
| Pages | — | **1 240** |
| Gros PDF (≥ 50 pages) | ≥ 4 | **6** |
| Types justes (règles) | ≥ 90 % | **237/237 (100 %)** |
| Émetteurs justes / vides | — / ≤ 15 % | **237/237 / 0** |
| Dates justes | — | **237/237** |
| Validés faux | 0 | **0** |
| Précision / rappel recherche | ≥ 90 % | **100 % / 100 %** (12 requêtes) |
| Temps max par requête | < 1 000 ms | **6 ms** |
| Durée classement | — | **0,35 s** |
| Durée ingestion (dépôt réel par le Socle) | — | **~35 s** |

Le corpus de simulation porte des marqueurs de type lisibles (courriers, factures, contrats typés) :
c'est voulu pour mesurer la chaîne **à volume** ; le corpus bruité reste `classement-bruite`, hors de
ce lot. La recherche est mesurée sur un fonds déposé avec la **vérité** (classement isolé de la
recherche) : 8 requêtes par mot-clé rare (sous-ensemble fermé), 3 filtres de métadonnées, 1 fragment.

## Porte qualité (ECC)

- **Analyse statique** : `php vendor\bin\phpstan analyse` niveau 6 → **0 erreur** sur `src`
  (baseline vide, inchangée) ; sur les fichiers du lot (`tools/banc/mesurer-sim-corpus.php`,
  `tests/php/Sim/VeriteCorpusTest.php`) → **0 erreur**.
- **Revue du diff** (grilles `php-reviewer`, `security-reviewer`, `silent-failure-hunter`) :
  - php-reviewer : `declare(strict_types=1)`, fonctions typées et courtes, seuils en constantes
    nommées, aucun SQL assemblé depuis une entrée (liste de tables littérale, bornée à `gedv2_test`).
  - security-reviewer : aucune entrée utilisateur ; `proc_open` en tableau (pas de shell) ; chemins
    issus de la vérité versionnée ; écritures limitées à `gedv2_test` et `var/sim` ; aucune donnée réelle.
  - silent-failure-hunter : verrou, échec de génération, échec de dépôt, fonds mal vidé, vérité
    désynchronisée → tous nommés et bloquants. Constat MEDIUM assumé : `@rmdir`/`@unlink` restent
    dans la purge du fonds régénérable, mais chaque échec est **compté** et **bloque** la mesure
    (le `@` ne masque plus d'erreur) — motif : dossier de test régénérable.
- **TDD** : oracle d'abord — `VeriteCorpusTest` vu ROUGE (6 échecs, fichiers absents), puis vert
  (6 tests / 4 509 assertions) après génération.
- **Modifications hors secteur signalées** :
  - `tools/harness.ps1` : 4ᵉ banc de la couche 3 (`sim-corpus`).
  - `tests/php/Securite/BancIsolationTest.php` : le nouveau banc PDO est déclaré dans la liste fermée
    (l'oracle exige qu'il impose et vérifie `gedv2_test`) — c'est ce qui laissait la couche 1 rouge.

## Harness

Environnement du poste : `node_modules` du dépôt partagé vide (Playwright introuvable) et
`tools/composer.phar` absent du worktree → restaurés hors dépôt (`npm ci` depuis le cache, copie de
`composer.phar`) ; ce ne sont pas des fichiers suivis.

- **Harness complet, machine chargée (autre agent K-Time actif), commit `cafb18f`** —
  couche 0 **verte**, 1 **verte** (869 tests / 16 060 assertions), **3 verte** (découpe 53/53,
  classement, bout-en-bout F1, **sim-corpus 237 documents, P/R 100 %, 6 ms**), 2 **verte**,
  6 **verte** (p95 lecture 1 026 ms), 7 **verte**, 8 **verte** (PHPStan 0, audit propre),
  9 **verte** (parité hors exclusions 100,00 %).
- Deux rouges **hors périmètre du lot**, dus à la concurrence des ports et à la charge :
  - `4-ecrans` : `edition-pages.spec.ts` en timeout à 90 s sous charge ; **rejoué seul sur ports
    dédiés : 2/2 vert** ;
  - `5-personas` : « 8871 est déjà utilisé » (serveurs d'un autre agent sur les ports partagés) ;
    **rejoué seul sur ports dédiés : 23/23 vert**, 6 manifestes régénérés.
- **Passe de porte `harness.ps1 -SansBanc`, ports dédiés, commit `cafb18f`** (42 min, machine chargée) :
  couches 0, 1, 2, 6, 7, 8, 9 **VERTES** (1 : 869 tests / 16 060 assertions ; 6 : p95 lecture 749 ms ;
  8 : PHPStan 0, audit propre ; 9 : hors exclusions 100,00 %). `3-banc` est ROUGE **par construction**
  (`-SansBanc` la saute), mais elle est **VERTE au harness complet** : le banc `sim-corpus` y rend
  237 documents, types 237/237, recherche P/R 100 % en 6 ms.
- Couches 4 et 5 rouges **sous la seule charge** de la machine (deux chantiers K-Time et l'agent voisin
  tournaient) : les specs en échec (`edition-pages` ; gestes personas) passent **isolément sur ports
  dédiés** — 4 → **2/2**, 5 → **23/23** avec 6 manifestes régénérés.
- Règle du juge appliquée : les couches 4 et 6 ne font foi que sur machine calme. Les deux rouges
  constatés sont **étrangers au lot** (aucun chemin d'Interface touché) et rejoués verts isolément.

ETAT: fini


