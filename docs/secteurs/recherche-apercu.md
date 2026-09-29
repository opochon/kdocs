# Secteur RECHERCHE & APERÇU — visionneuse, miniatures, plein texte, API

Agent : DeepCode · Juge : Codex · Lot en cours : V2-05a

## Mission
Voir un document sans le télécharger (PDF, images, bureautique), vite ; le retrouver par son texte et ses métadonnées.

## Chemins
`src/Apercu/` (dont `src/Apercu/Module.php` : routes du secteur, appelé par `src/Kernel.php` — ne jamais modifier Kernel), `src/Recherche/`, `public/assets/js/visionneuse.js`, `public/assets/vendor/pdfjs/`,
`templates/partiels/visionneuse.twig`, `tests/php/Apercu/`, `tests/e2e/apercu.spec.ts`, `tests/corpus/apercu/`

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
