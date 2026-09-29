# Secteur RECHERCHE & APERÇU — visionneuse, miniatures, plein texte, API

Agent : DeepCode · Juge : Codex · Lot en cours : V2-05b

## Mission
Voir un document sans le télécharger (PDF, images, bureautique), vite ; le retrouver par son texte et ses métadonnées.

## Chemins
`src/Apercu/` (dont `src/Apercu/Module.php` : routes du secteur, appelé par `src/Kernel.php` — ne jamais modifier Kernel), `src/Recherche/`, `public/assets/js/visionneuse.js`, `public/assets/vendor/pdfjs/`,
`templates/partiels/visionneuse.twig`, `tools/miniatures.php` (commande hors requête nommée par le lot), `tests/php/Apercu/`, `tests/e2e/apercu.spec.ts`, `tests/corpus/apercu/`

Chemins ajoutés au lot V2-05b : `tests/php/Recherche/`, `tests/oracles/recherche/`, `tests/e2e/recherche.spec.ts`,
`tools/indexer-recherche.php` (indexation hors requête). Branchement minimal demandé à Interface (3 lignes, repli maquette conservé) :
`src/Ui/Ecrans.php::recherche()` et `templates/ecrans/recherche.twig` (mêmes classes et mêmes `data-testid`).


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
- Commits : `19052cc` (source et outils), `db1106d` (visionneuse, gabarit, corpus), `8abcee6` (tests),
  `e0b903f` (journal), `3aa7b54` (binaires figés hors conversion CRLF).

Reste (hors porte de ce lot) :
- Branchement du macro dans les écrans Bibliothèque / Fiche (demande Interface), pour remplacer `papier.page()`
  in situ plutôt que sur l'écran autonome.
- V2-05b : plein texte (FULLTEXT + repli LIKE + extraits), qui reprendra `SearchService.php` de GEDv1.

ETAT: fini

## Journal V2-05b (29-09)

**Fait, clause par clause**
- **Index FULLTEXT** : `src/Recherche/Schema.php` pose `recherche_textes` (titre + contenu, index `ft_recherche`),
  sans jamais modifier la table `documents` du Socle. L'index est un cache DÉRIVÉ, reconstructible depuis le dossier ;
  il est en MyISAM parce que le FULLTEXT InnoDB de ce serveur ne tokenise rien (`innodb_ft_min_token_size = 0`) et
  que MyISAM, seul, répond. `assurerMoteur` / `assurerColonneAbsence` / `assurerIndexPleinTexte` rendent
  l'installation idempotente et rattrapent une table ancienne.
- **Alimentation** : `SourceRecherche` lit le fonds (`.meta.json` : `texte`/`ocr`, ou le contenu d'un fichier texte) ;
  `IndexPleinTexte` synchronise par lots bornés (reprise au prochain appel) ; `tools/indexer-recherche.php` fait le
  gros du travail hors requête (`--tout`, `--vider`, `--limite=`). Une ligne dont le fichier a disparu est marquée
  absente, jamais supprimée (règle « zéro suppression »).
- **Repli LIKE** : déclenché quand l'index est absent, quand la requête n'a aucun terme exploitable, quand le terme
  fait moins de quatre lettres (non indexé par MyISAM) ou quand un fragment interne ne peut pas être trouvé par
  FULLTEXT (`ntrat` trouve les deux contrats). Le moteur retombe aussi sur LIKE si FULLTEXT ne rend rien.
- **Extraits surlignés** : `Extraits` découpe titre et corps en segments marqués/non marqués ; le gabarit n'affiche
  que `<mark>`, jamais du HTML produit (aucune injection).
- **Filtres au même périmètre** : type, émetteur, date, montant sont posés dans la MÊME clause que le texte ; le total
  et la liste partagent donc exactement le même WHERE.
- **Erreurs SQL nommées** : `ErreurSql` (étape, cause lisible, référence) ; l'écran affiche
  `data-testid="recherche-erreur"`, jamais une liste vide muette.
- **Écran `/recherche`** : branché sur les vrais résultats du fonds, gabarit et système visuel conservés (mêmes
  classes, mêmes `data-testid`) ; compte réel, durée, filtres réels, état vide nommé.

**Preuves**
- Oracle de porte : `tests/oracles/recherche/recherche.json` rejoué par `OracleRechercheTest` — 11 requêtes,
  36 assertions, 100 % des attendus EN TÊTE dans l'ordre annoncé, mode fulltext/like vérifié, chaque requête < 1 s.
  Vu ROUGE d'abord (classe absente), puis vert.
- PHPUnit Recherche : 19 tests, 102 assertions (`SchemaTest`, `ServiceTest`, `ExtraitsTest`, `EcranTest`).
- Playwright : 25 tests verts (dont 4 `recherche.spec.ts`) — documents réels, terme surligné, filtre type,
  absence dite, écran < 1 s, 0 erreur console ; le Ctrl+K des maquettes surligne « maintenance » sur les vrais
  résultats.
- Harness `-SansBanc` du 29-09 : `0-garde-fous` VERT, `4-ecrans` VERT (25) ; `3-banc` sautée comme demandé ;
  `1-unitaire` ROUGE (153 tests, 2 erreurs) **à cause d'un test Droits non commité d'un agent concurrent**
  (`tests/php/Securite/DroitsBaseTest.php` réclame `Ged\Securite\Schema`, pas encore écrit — hors secteur).
  En excluant ce fichier : 151 tests verts. Sur HEAD commité, ce fichier n'existe pas et la couche repasse verte.
- Commits : `9461072` (moteur + oracle), `f6a52d8` (index sans suppression), `a516d7e` (écran),
  `f358dd2` (tests, outil, e2e).

**Demandes**
- **Interface** : `Ged\Ui\Ecrans::recherche()` contient le branchement minimal (3 lignes, repli maquette conservé) ;
  à reprendre comme point de contrat si d'autres écrans sont branchés. Aucune autre attente.
- **Droits & sécurité** : aucune modification de `DroitsRoutes` — `GET /recherche` y déclare déjà `recherche:lire`.
  Le secteur n'ajoute aucune route (l'écran est servi par le Kernel, comme les autres).
- **Socle** : `DepotFichiers` n'écrit pas l'`id` dans le `.meta.json` ; l'index le retrouve par `chemin_relatif`.
  Si l'id doit un jour survivre à une reconstruction, le poser au dépôt serait plus direct.

**Reste hors porte** : recherche sémantique/vectorielle, vues enregistrées, API de recherche (« documents
semblables »), qui viendront dans les lots V2-05 suivants.

ETAT: fini


## Jugement V2-05b (Claude Code, 29-09) — porte franchie
Harness complet rejoué après les commits des trois agents : unitaire 155/155, bancs verts (découpe, classement, F1 de bout en bout), écrans 25/25. Oracle `recherche.json` : 11 requêtes, attendus en tête, < 1 s. Réserve : l'index est en MyISAM (cache dérivé, reconstructible : acceptable) ; l'explication « le FULLTEXT InnoDB ne tokenise rien » est à vérifier sur le serveur de production avant d'en faire une règle.
ETAT: fini (jugé)
