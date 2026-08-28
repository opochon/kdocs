---
description: Le goal des demandes D-GED. Se superpose a /reprendre — meme mecanique, cible fixee sur recette/demandes.json au lieu du secteur le plus rentable.
---

# LIS CECI AVANT TOUT LE RESTE

## Le job

**Fermer les douze demandes d'Olivier.** `recette/demandes.json` (D-GED-01 a
D-GED-12) prime tout `governance/backlog.json` — regle 13 de
`EcosystemK/AGENTS.md`. Aujourd'hui : **7 TROU** (aucun point de mesure ecrit :
D-GED-06 a D-GED-12), **5 NON CABLE** (le point existe, rien ne le rend vert :
SV-02, SV-05, SV-10 a SV-14, portees par D-GED-01 a D-GED-05). Zero demande
fermee (`adoptee_le` n'appartient qu'a Olivier — tu ne le poses jamais).

## Ce qu'on ne porte pas

**Le produit GED**, pas une deuxieme implementation de ce que K-Time fait deja.
D-GED-06 est le garde-fou explicite : si un ecran de rapprochement facture ou
une analyse de facture fournisseur apparait cote GED, c'est une regression —
K-Time est le canal d'introduction et de validation, la GED consomme
`/api/ged/*` (`docs/SPEC-GED-INTEGRATION.md`, cote K-TIME, **lecture seule**).
De meme, D-GED-05 (decision liaison ERP) n'est **pas a toi de trancher** :
c'est un arbitrage produit, il se depose dans `recette/arbitrages.json` avec
2-3 options et un defaut recommande — tu ne codes pas un choix qui n'a pas ete
fait.

## La methode — quatre vagues, dans l'ordre

**Le premier livrable d'une demande en TROU n'est jamais le correctif — c'est
le point.** Une sonde ecrite apres coup, en regardant le code, mesure ce que
le code fait au lieu de ce qui etait demande (regle 4 EcosystemK). C'est
pourquoi T0 vient avant tout le reste, meme si un correctif rapide te saute
aux yeux sur D-GED-09 ou D-GED-11 : ecris le point qui l'aurait vu, PUIS le
correctif dans le meme lot si le point rougit.

**T0 — LE POINT AVANT LE CORRECTIF, SEPT FOIS.** Une sonde `tests/integration/
test_*.php` par demande TROU, sur le modele de `test_split_multidoc.php`
(SV-16) : trois issues distinctes (CORRECTE / ABSENTE / ECHEC), chemin reel,
aucun mock. Chaque sonde ajoutee a `run-harness.mjs` et `governance/
specs.json` si Playwright, un `SV-xx` ou `G-xx` pose dans `recette/
partition.json`. Sortie : les 12 demandes ont un point, zero TROU.
*Ordre suggere, du plus autonome au plus couplé : D-GED-11 (trace de pile,
aucune dependance), D-GED-09 (badge, un seul fichier `helpers.php`),
D-GED-12 (file de validation, lecture seule au depart), D-GED-07/D-GED-10
(meme famille — un seul point peut couvrir les deux), D-GED-08 (dossier
surveille), D-GED-06 (couple a K-Time, donc en dernier de T0).*

**T1 — CE QUI EST DEJA MESURABLE ET PRES DU BUT.** D-GED-04 (SV-10, zero
modification de l'original — la moitie deja prouvee par SV-04 versioning,
`test_versioning_fileserver.php` : verifier si la seconde clause d'A4 est deja
couverte avant d'ecrire une sonde qui existe peut-etre déjà, regle 1). Et le
depot de l'arbitrage D-GED-05 (SV-14) — un fichier `recette/arbitrages.json`
qui n'existe pas encore de tache, pas un choix.

**T2 — LA FACTURE FOURNISSEUR.** D-GED-02 (SV-12, SV-13) puis D-GED-03
(SV-11). La question maitresse d'abord : SV-13, egalite lignes + TVA = total,
verifiable **sans reference externe** — c'est le seul point qui ne depend de
rien d'autre. Puis SV-12 (QR, adressage, coordonnees vendeur, lignes). SV-11
ferme la boucle : classement date/fournisseur, statut paye/non-paye, **appel
reel** contre `KTIME_URL` (regle 4 AGENTS.md — le mock n'est pas une preuve).

**T3 — D-GED-01, LE SOCLE M-FILES.** Le plus gros morceau, en dernier parce
qu'il est déjà en partie câblé (20/29) et que T0-T2 fermeront plusieurs de ses
8 verbes en chemin (lire ⊃ SV-02 croise D-GED-02/03 ; interface claire ⊃ SV-05
est souvent un sous-produit de T0). Sortie : les 8 verbes de
`governance/ATTENDUS-PRODUIT.md` section B (B1-B12) ont chacun leur `SV-xx`,
zero verbe sans point.

## Comment un tour se juge

**En T0** : sur une demande qui passe de TROU a un etat MESURE (CORRECTE,
ABSENTE ou ECHEC — jamais silencieux). **En T1-T3** : sur un `SV-xx` qui passe
NON CABLE → CABLE ET VERT, au sens de `AGENTS.md` — « Fin de lot ». Ne compte
dans aucune vague : reparer une sonde d'un autre secteur, ou tourner sept
lots sans qu'aucune demande D-GED n'ait bouge (le meme piege que le 19-08 cote
K-Time, compteur inchange a 98 apres 3h20).

## Tu ne modifies jamais

Un `SV-xx` : c'est `Config::get` calcule (rappel : `governance/ATTENDUS-
PRODUIT.md` verse par Olivier seul). Un `adoptee_le` dans `recette/
demandes.json` : seul Olivier ferme une demande. Le cote K-TIME du contrat
`/api/ged/*` : lecture seule, ecart note BLOQUE avec la route et le
changement attendu.

## Ou tout est ecrit

| fichier | ce qu'il porte |
|---|---|
| **`team/brief.md`** | Regenere a chaque tour — l'etat calcule des 12 demandes, du socle (câblé X/29), des rouges. Lire en premier, jamais de memoire. |
| `recette/demandes.json` | Les 12 demandes, verbatim d'Olivier, statut, points rattaches |
| `recette/partition.json` | Les `SV-xx`/`G-xx` : sonde, seuil, statut |
| `recette/arbitrages.json` | Les decisions qui n'appartiennent pas a l'agent (D-GED-05 y va) |
| `governance/ATTENDUS-PRODUIT.md` | A1-A4, B1-B12 : l'attendu M-Files, jamais modifiable par un agent |
| `governance/sectors.json` | Proprietaire, fichiers, oracles, dependances par secteur |
| `docs/SPEC-GED-INTEGRATION.md` | Le contrat `/api/ged/*` — le cote GED ; K-TIME tient le sien |
| `AGENTS.md` | Les 5 regles qui coutent cher + pieges connus de ce depot |

## Interdits, campagne ou pas

Zero suppression (`deleted_at`, jamais `DELETE`). Un oracle qui verifie
`hasMethod()` au lieu d'executer le chemin reel. Coder cote K-TIME. Un mock a
la place d'un aller-retour reel `KTIME_URL`. `git add -A`. Poser
`adoptee_le`. Trancher D-GED-05 dans le code au lieu de deposer l'arbitrage.

## Sortie

Le format de `/reprendre` section 5, avec une ligne de plus :

```
GOAL   T<n> — <demande fermee ou point pose>, <TROU->MESURE | NON CABLE->VERT>
```
