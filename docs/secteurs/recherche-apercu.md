# Secteur RECHERCHE & APERÇU — visionneuse, miniatures, plein texte, API

Agent : DeepCode · Juge : Codex · Lot en cours : V2-05a

## Mission
Voir un document sans le télécharger (PDF, images, bureautique), vite ; le retrouver par son texte et ses métadonnées.

## Chemins
`src/Apercu/` (dont `src/Apercu/Module.php` : routes du secteur, appelé par `src/Kernel.php` — ne jamais modifier Kernel), `src/Recherche/`, `public/assets/js/visionneuse.js`, `public/assets/vendor/pdfjs/`,
`templates/partiels/visionneuse.twig`, `tools/miniatures.php` (commande hors requête nommée par le lot), `tests/php/Apercu/`, `tests/e2e/apercu.spec.ts`, `tests/corpus/apercu/`

## Lot V2-05a — porte
1. Visionneuse PDF intégrée (pdf.js en local dans `public/assets/vendor/pdfjs/`, aucun CDN) qui remplace le macro
   `papier.page()` là où un vrai fichier existe : pages, zoom, rotation d'affichage, défilement ; clair et sombre.
2. Miniature de première page générée HORS requête (commande `php tools/miniatures.php`), servie par une route qui
   passera par la garde du secteur Droits (déclare la permission `document.voir`).
3. Images (png, jpg, tiff) et bureautique (docx, xlsx, odt via conversion PDF si LibreOffice présent, sinon message
   nommé « aperçu indisponible : LibreOffice absent »).
4. Corpus de test synthétique dans `tests/corpus/apercu/` (générés par script, aucun document réel).
5. Tests : un PDF de 3 pages s'affiche en < 1 s, la page 2 est atteignable au clavier, 0 erreur console.

## Reprendre de GEDv1
`app/Services/ThumbnailGenerator.php` et `OfficeThumbnailService.php` (adaptateurs de rendu, détection des outils).
`SearchService.php` pour V2-05b (FULLTEXT + repli LIKE + extraits), pas pour ce lot.

## Demandes aux autres secteurs
- **Interface** : remplacer `papier.page(...)` par
  `{% import 'partiels/visionneuse.twig' as vis %}{{ vis.moteur(rendu, 'apercu-page') }}` dans
  `templates/ecrans/bibliotheque.twig` et `templates/ecrans/document.twig` quand la pièce a un fichier réel
  (le rendu vient de la route `/documents/{id}/apercu`) ; vous pouvez ensuite déplacer les styles du composant,
  aujourd'hui injectés par `visionneuse.js`, dans `ged.css`. Tant que ce n'est pas fait, l'écran autonome
  `/documents/{id}/apercu` tient la porte.
- **Droits & sécurité** : la garde centrale doit lire l'attribut `permission` posé par `Ged\Apercu\Permission`
  (valeur `document.voir`) et couvrir `/documents/{id}/apercu`, `/documents/{id}/apercu/fichier`,
  `/documents/{id}/fichier`, `/documents/{id}/miniature`. Aucune de ces routes n'est publique.
- **Socle** : dès que le vrai dossier documentaire existe, positionner `STORAGE_ROOT` ; `Ged\Apercu\Source`
  le parcourt déjà (id lu dans `.meta.json`, aucune base). Sans cela, le défaut pointe sur le corpus synthétique.

## Journal V2-05a
Fait :
- `src/Apercu/` : `Source`/`Piece` (le dossier fait foi, id lu dans `.meta.json`, refus des chemins hors racine),
  `Outils` (détection pdftoppm/Ghostscript/ImageMagick/LibreOffice, conversions), `Miniature` (artefact hors requête),
  `Permission` (déclare `document.voir`), `Module` (4 routes enregistrées automatiquement par le Kernel).
- `tools/miniatures.php` : fabrique les vignettes `.apercu/<id>.vignette.png` (et l'aperçu PNG des TIFF, le PDF des
  bureautiques quand LibreOffice est présent) sans jamais les produire pendant une requête.
- `templates/partiels/visionneuse.twig` + `public/assets/js/visionneuse.js` : pages, zoom, rotation d'affichage,
  défilement, navigation clavier (Page suivante/précédente, flèches), clair et sombre, styles par jetons uniquement.
  pdf.js 3.11.174 vendu dans `public/assets/vendor/pdfjs/` (aucun CDN à l'exécution).
- `tests/corpus/apercu/` : corpus 100 % synthétique régénérable (`generer.php`) — PDF 3 pages, PDF 1 page,
  png/jpg/tiff, docx/xlsx/odt — plus ses vignettes.

Preuves :
- PHPUnit : 38 tests, 106 assertions, vert (`tests/php/Apercu/` : Source, contenu des routes, miniature, permission).
- Playwright : 19 tests, vert, dont 7 d'aperçu — PDF de 3 pages affiché en < 1 s (première page), page 2 au clavier,
  0 erreur console, png/jpg/tiff affichés, bureautique nommée, miniature servie en PNG, 404 nommé.
- Captures : `var/captures/apercu-pdf-clair.png`, `var/captures/apercu-pdf-sombre.png`.
- Harness du 29-09 : `0-garde-fous`, `1-unitaire` (38 tests) et `4-ecrans` (19 tests) VERTES ; les couches
  `2-contrat`, `3-banc`, `5-personas`, `6-charge`, `7-securite` restent « pas encore écrite », inchangées.
- Commits : `19052cc` (source et outils), `db1106d` (visionneuse, gabarit, corpus), `8abcee6` (tests).

Reste (hors porte de ce lot) :
- Branchement du macro dans les écrans Bibliothèque / Fiche (demande Interface), pour remplacer `papier.page()`
  in situ plutôt que sur l'écran autonome.
- V2-05b : plein texte (FULLTEXT + repli LIKE + extraits), qui reprendra `SearchService.php` de GEDv1.

ETAT: fini
