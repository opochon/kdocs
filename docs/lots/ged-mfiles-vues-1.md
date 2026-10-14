# Lot ged-mfiles-vues-1 — Famille M-Files nº 3 : vues dynamiques (listes filtrees par classe/metadonnees, vues enregistrees)

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3308).
Branche : `ged/ged-mfiles-vues-1` · point de depart : `origin/v2` (`ae5a2a6`, familles archivage/versions,
classes/metadonnees/listes et relations deja fusionnees).

## Objectif

Porter la **famille M-Files nº 3** — vues dynamiques par classe et metadonnees, vues enregistrees /
nommees, vues recherchees — en faisant passer les elements `mfiles:*` correspondants de `absent` a
`couvert` / `couvert-autrement`, prouves par des tests et des vecteurs rejoues, sans toucher
`src/Parite/Juge.php`, la liste fermee d'exclusions, ni les statuts/vecteurs Paperless.

## Etat d'avancement

### Fait (commite)

- _(rien encore)_

### En cours

- Cadrage : `docs/secteurs/vues.md` (chemins, demandes, journal).

### Reste

- Oracle rouge des vecteurs `mfiles-vues-*.json` + `MfilesVuesVecteursTest`.
- `Vues::contenu()` / `Vues::compter()` + tests unitaires.
- Parite M-Files famille 3 (`views/path/items/count`, `views/path/objects`).
- Harness `-SansBanc` vert + porte qualite.

## Mesures exactes

| Mesure (avant lot) | Valeur |
| --- | --- |
| Suite PHPUnit | 937 tests, 17 975 assertions, 1 saute |
| Parite M-Files (juge `tools/parite/juge-mfiles.php`) | 112 elements, couverts **49**, strict **43,75 %**, ecarts 63, VERT |

ETAT: en cours
