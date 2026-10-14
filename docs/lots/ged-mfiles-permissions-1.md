# Lot ged-mfiles-permissions-1 — Famille M-Files « permissions dynamiques »

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1,
port de test local ; `.env` local aligné sur l'instance MariaDB de la machine).
Branche : `ged/ged-mfiles-permissions-1` · point de départ : `origin/v2` (`fdb1ad2`, familles
archivage/versions, classes, relations et vues déjà fusionnées).

## Objectif

Porter la **famille « permissions dynamiques »** : droits calculés depuis la classe, les métadonnées et
l'état, visibilité conditionnelle à l'affichage et à la recherche, règles nommées et portables, écran de
gestion — sans affaiblir la sécurité en place ni toucher `src/Parite/Juge.php`, la liste fermée
d'exclusions ou les statuts/vecteurs Paperless.

## Etat d'avancement

### Fait (commité)

- Cadrage du secteur `docs/secteurs/permissions.md` et présente note.

### En cours

- _(rien)_

### Reste

- Oracle rouge, service, intégration liste/recherche, écran, parité, e2e, harness.

## Mesures exactes

_(remplies au fil du lot)_

ETAT: en cours
