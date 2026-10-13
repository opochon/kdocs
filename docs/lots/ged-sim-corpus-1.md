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

## Reste

- Porte qualité ECC (PHPStan niveau 6, revue du diff) et harness `-SansBanc` vert.

ETAT: en cours

