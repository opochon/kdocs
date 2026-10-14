# Lot ged-mfiles-fiche-1 — Famille M-Files nº 5 : fiche document et son rendu

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3307).
Branche : `ged/ged-mfiles-fiche-1` · point de départ : `origin/v2`.

## Objectif

Porter la **famille M-Files nº 5** — aperçu/vignette d'un objet et d'un fichier, titre d'objet et de
fichier, historique des versions, commentaires, statut de verrouillage / check-out — en faisant passer
les éléments `mfiles:*` correspondants de `absent` à `couvert` / `couvert-autrement`, prouvés par des
tests rejoués, sans toucher `src/Parite/Juge.php`, la liste fermée d'exclusions, ni les statuts/vecteurs
Paperless.

Les cinq familles visées (11 éléments `mfiles:rest:*`) :

| Famille | Éléments M-Files |
| --- | --- |
| Aperçu / vignette | GET `…/preview` (objet), GET `…/files/{file}/preview` (fichier) |
| Titre | GET/PUT `…/title` (objet), GET/PUT `…/files/{file}/title` (fichier) |
| Historique | GET `…/history` |
| Commentaires | GET/PUT `…/comments` |
| Check-out | GET/PUT `…/checkedout` |

Les icônes de classe/type (`/structure/classes/{id}/icon`, `/structure/objecttypes/{type}/icon`) ne sont
**pas** des vignettes d'objet ou de fichier : laissées `absent`, **proposées** au juge (aucune exclusion
posée par l'agent).

## État d'avancement

### Fait (commité)

- `7cdf144` — cadrage du secteur `docs/secteurs/fiche-rendu.md` (chemins, demandes, journal).
- **Historique des versions** : service `Ged\Fiche\Historique` (versions archivées + déplacements, du plus
  récent au plus ancien, lus du fonds ; base muette tracée, liste vide jamais 500) ; `Ui\Document::historique`
  lui délègue. Oracle rouge d'abord (2 erreurs « classe absente »), puis vert : **2 tests, 10 assertions**.
- **Titre d'objet / nom de fichier** : service `Ged\Fiche\Nom` (lire le nom rangé, renommer le fichier
  réel — fichier, `.meta.json` et `.versions/` suivent, index et trace `renommage`, titre d'objet intact,
  extension conservée, nom vide refusé, droits `modifier` exigés) ; oracles `NomTest` (3 tests) et
  `TitreTest` (2 tests : le titre d'objet se lit/écrit dans le `.meta.json` sans renommer le fichier).
- **Verrouillage / check-out** : service `Ged\Fiche\Verrou` (état dans le `.meta.json`, délai de 30 min
  prolongeable, expiration qui libère, refus par un autre compte, libération forcée administrateur,
  gestes tracés dans `document_collaboration`). Oracle rouge d'abord (3 erreurs « classe absente »),
  puis vert : **3 tests, 19 assertions**.
- **Interface de la fiche & rendu** : routes `Ged\Fiche\Module` (`POST /documents/{id}/titre`,
  `/nom-fichier`, `/verrou`), permission `documents:modifier` et niveau ACL, une ligne Kernel signalée,
  `Ui\Document`/`Ui\Ecrans` passent le nom de fichier et l'état du verrou, barre d'actions de la fiche
  avec `data-testid`, charge CSRF défensive de la sonde. `ModuleTest` : **5 tests, 26 assertions**.

### Reste

- Parité M-Files famille 5 ; porte qualité ECC ; harness `-SansBanc`.

## Mesures exactes

_(complétées au fil du lot)_

ETAT: bloqué (lot en cours)
