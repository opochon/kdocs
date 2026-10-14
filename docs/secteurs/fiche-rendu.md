# Secteur FICHE & RENDU — fiche document, aperçu/vignette, titre, historique, commentaires, check-out

Agent : DeepCode (lot `ged-mfiles-fiche-1`) · Juge : Claude sur le poste principal.

## Mission

Porter la **famille M-Files nº 5** (`docs/PLAN-GEDV2.md`, `docs/parite/PARITE-MFILES.md`) : tout ce qui
fait la **fiche d'un objet** et son **rendu**, aujourd'hui `absent` du registre :

- **aperçu / vignette d'un objet et d'un fichier** (`preview`, `thumbnail`) : l'objet et le fichier se
  consultent par la **visionneuse existante** (`templates/partiels/visionneuse.twig`) et sa **vignette
  dérivée** (jamais la source, régénérable) ;
- **titre d'objet et de fichier** (`title`) : le titre de l'objet vit dans le `.meta.json` (le dossier
  fait foi) ; le **nom du fichier** est un acte distinct (renommage réel, tracé, réversible par
  l'historique) ;
- **historique des versions** (`history`) : versions archivées et déplacements d'un objet, lus du fonds ;
- **commentaires** (`comments`) : les notes de la fiche (collaboration), ajoutées et relues ;
- **statut de verrouillage / check-out** (`checkedout`) : un compte peut **verrouiller** un objet en
  cours d'édition et le **libérer** ; l'état est de la métadonnée (`.meta.json`), donc reconstructible.

Aucune exclusion n'est posée par l'agent : un élément sans équivalent reste `absent` et est **proposé**
au juge.

## Chemins (seuls modifiables par ce secteur)

- `src/Fiche/Historique.php` (neuf) : historique d'un objet (versions + déplacements + événements), lu du fonds.
- `src/Fiche/Nom.php` (neuf) : renommage du fichier d'un objet (fichier, `.meta.json` et `.versions/`
  suivent ; index et trace mis à jour ; jamais de suppression).
- `src/Fiche/Verrou.php` (neuf) : verrouillage / check-out d'un objet, état dans le `.meta.json`, tracé.
- `src/Fiche/Module.php` (neuf) : routes de la fiche (titre, nom de fichier, verrou) — `data-testid` côté gabarit.
- Preuves : `tests/php/Fiche/*`, `tests/php/Parite/MfilesFicheVecteursTest.php` (si vecteurs),
  `tests/parite/vecteurs/mfiles-fiche-*.json` (si vecteurs).
- Oracle M-Files : `tools/parite/corriger-correspondance-mfiles.php` (section famille 5),
  `docs/parite/correspondance.json`.
- Journal du lot : `docs/lots/ged-mfiles-fiche-1.md`.

## Modifications minimales signalées (hors chemins du secteur)

- `src/Kernel.php` : une ligne, le module `Fiche` (même mécanisme que `DepotFileserver` et `Permissions` ;
  signalé ici comme l'exige le contrat).
- `src/Ui/Document.php` : la fiche passe le verrou et le nom de fichier au gabarit, et délègue son
  historique à `Ged\Fiche\Historique` (aucune autre donnée ne change).
- `src/Ui/Ecrans.php` : valeurs neutres du verrou pour le repli de maquette (jamais un écran muet).
- `src/Securite/DroitsRoutes.php` : permissions et niveau ACL des routes de la fiche.
- `templates/ecrans/document.twig` : barre d'actions « Fiche & rendu » (renommer le titre, renommer le
  fichier, verrouiller / libérer) et état du verrou, chaque élément actionnable portant un `data-testid`.
- `tools/banc/sonde-securite.php` : charge CSRF défensive des nouvelles routes (refus métier sans écrire).

## Demandes au Socle / à Interface

- `Ged\Socle\DepotFichiers` reste seul à ranger, indexer et archiver : `Fiche\Nom` réutilise
  `Socle\Fichiers::deplacerAvecAnnexes` et la table des déplacements, sans dupliquer le dépôt.
- `Ged\Socle\Collaboration` reste seul à porter notes et événements : `Fiche\Verrou` y trace ses gestes
  et n'écrit aucune table nouvelle.
- La fiche document est un chemin du secteur Interface ; les additions sont isolées, testées et signalées
  ci-dessus. Si Interface reprend la main, aucun contrat existant ne change.

## Journal

- 14.10.2026 — ouverture du lot `ged-mfiles-fiche-1` (DeepCode) : aperçu/vignette, titre objet/fichier,
  historique des versions, commentaires, verrouillage / check-out, parité M-Files famille 5.
