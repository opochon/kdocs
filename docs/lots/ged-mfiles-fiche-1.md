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

- _(en cours)_ cadrage du secteur `docs/secteurs/fiche-rendu.md` (chemins, demandes, journal).

### Reste

- Historique des versions : service `Ged\Fiche\Historique`, délégation depuis `Ui\Document`, test.
- Titre objet / nom de fichier : service `Ged\Fiche\Nom` (renommage réel) et oracles de couverture.
- Check-out : service `Ged\Fiche\Verrou`, état dans le `.meta.json`, test.
- Interface : barre d'actions de la fiche, routes `Ged\Fiche\Module`, permissions, ligne Kernel.
- Parité M-Files famille 5 ; porte qualité ECC ; harness `-SansBanc`.

## Mesures exactes

_(complétées au fil du lot)_

ETAT: bloqué (lot en cours)
