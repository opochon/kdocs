# Secteur DEPOT FILESERVER — archivage, versions, réversibilité (« le dossier fait foi »)

Agent : DeepCode (lot pilote `ged-mfiles-oracle-1`) · Juge : Claude sur le poste principal.

## Mission

Porter la famille **archivage / versions** de `docs/ARCHIVAGE-FILESERVER.md`, dictée par Olivier le
13.10.2026, SANS transformer la GED en coffre substitué au système de fichiers :

- **scan initial** avec empreinte sha256 de **tous** les fichiers du dépôt ;
- **veille** qui détecte et trace ce qui a été fait **hors GED** : modification, déplacement,
  suppression, apparition ;
- **versions** dans un dossier masqué `.<nom du fichier>` à côté du fichier (le confort) **doublées**
  d'un **dépôt séparé** adressé par contenu (la garantie), avec la correspondance vers l'emplacement
  d'origine ;
- **réversibilité** vers une gestion de fichiers classique (export propre, sans élément interne) ;
- code **portable Windows/Linux**, écrans, droits, tests. Zéro suppression de donnée.

Ce secteur **ne remplace pas** le stockage de versions du Socle (`.versions/`), qui reste branché à
l'index et à la reconstruction. Il ajoute la couche file-server demandée (dossier masqué `.<fichier>`,
garantie séparée, journal, export). La réconciliation des deux conventions de dossier est une
**demande au juge** (voir « Demandes »).

## Chemins (seuls modifiables par ce secteur)

`src/DepotFileserver/`, `tests/php/DepotFileserver/`, `tests/oracles/depot-fileserver/`,
`tests/e2e/depot-fileserver.spec.ts`, `tests/e2e/preparer-depot-fileserver.php`.

**Oracle M-Files** (même lot) : `tools/parite/extraire-mfiles.py`,
`tools/parite/corriger-correspondance-mfiles.php`, `tools/parite/juge-mfiles.php`,
`src/Parite/` (partagé, non modifié ici), `tests/parite/vecteurs/mfiles-*.json`,
`tests/php/Parite/Mfiles*.php`, `docs/parite/inventaire-mfiles.json`,
`docs/parite/mfiles-rest-api.capture.json`, `docs/parite/correspondance.json`.

## Modifications minimales signalées (hors chemins du secteur)

Comme les lots précédents, quelques lignes partagées ont été posées et sont signalées :

- `src/Kernel.php` : `'DepotFileserver'` ajouté à la liste des modules (une ligne).
- `src/Securite/DroitsRoutes.php` : quatre permissions `application:administrer` pour
  `GET /depot-fileserver` et ses trois mutations.
- `src/Ui/Rail.php` : entrée « Dépôt fichier » sous Administration.
- `templates/ecrans/depot-fileserver.twig` (nouveau) et `templates/layout.twig` (onglet bas
  « Plus » pour le téléphone).
- `tools/banc/sonde-securite.php` : trois charges CSRF défensives (les gestes exigent une
  confirmation explicite, la sonde ne déclenche donc aucun scan ni export).
- `tools/harness.ps1` : couche `9-parite-mfiles` (le juge Paperless reste inchangé).
- `.env.example` : `DEPOT_SEPARE`, `DEPOT_EXPORT`.

## Modèle de données (le dossier fait foi)

- `<dépôt>/.depot-fileserver/empreintes.json` : manifeste `chemin → {empreinte, taille, vu_le, etat}`.
- `<dépôt>/.depot-fileserver/journal.ndjson` : journal des écarts (type, chemin, ancien chemin, empreinte).
- `<dépôt>/.depot-fileserver/dernier-releve.json` : résumé du dernier passage.
- `<dépôt>/<chemin>/.<fichier>/vNNN_<empreinte8>.<ext>` : versions de confort.
- `<DEPOT_SEPARE>/objets/<ab>/<cd>/<sha256>` + `<DEPOT_SEPARE>/correspondance/<sha256>.json` : la garantie.

## Demandes

- **Au juge (vue d'ensemble)** : la famille ajoute un second emplacement de versions (`.<fichier>`)
  à côté de `.versions/<fichier>/` du Socle. Faut-il unifier (adopter `.<fichier>` partout, ou
  brancher la veille sur `.versions/`) ? Décision de vue d'ensemble, hors périmètre de l'agent.
- **Au Socle** : brancher la veille DepôtFileserver sur les mêmes déplacements que l'`Indexeur`
  (aujourd'hui indépendants) éviterait deux passages ; à voir après décision ci-dessus.
- **Au juge** : les 103 opérations `mfiles:*` restées `absent` sont proposées, aucune exclusion
  posée par l'agent.

## Journal `ged-mfiles-oracle-1` — 13.10.2026

Voir `docs/lots/ged-mfiles-oracle-1.md` (chiffres par couche, porte qualité, `ETAT:`).
