# Lot miniature-1 — la vignette PDF respecte la taille voulue

Agent : DeepCode · Juge : Claude (poste principal, M2) · Branche : `ged/miniature-1`
Secteur : `recherche-apercu` · Oracle de porte : `MiniatureTest::testGenereUneVignetteDepuisUnPdf` (420 px)

## But

`MiniatureTest::testGenereUneVignetteDepuisUnPdf` repasse au vert sur M2 : la vignette d'un PDF doit rester
**≤ 420 px de côté** quelle que soit la machine. L'assertion n'est pas affaiblie et les autres vignettes
(images, TIFF) ne sont pas touchées.

## Cause — mesurée, pas supposée

Le défaut est dans `Ged\Apercu\Outils::pdfVersPng()` (appelé par `Miniature::generer()`). Deux branches :

| Branche | Option de taille | Effet |
| --- | --- | --- |
| `pdftoppm` (poppler) | `-scale-to <largeur>` | **respecte** la taille : plus grand côté ramené à `largeur` |
| repli `Ghostscript` | `-r150` **figé** | ignore `$largeur` : une A4 sort **1240 × 1754 px** (150 dpi × 8,27 po) |

Sur un poste **sans poppler** (le cas de M2), c'est le repli Ghostscript qui rend la vignette : 1240 px de large,
au-delà de la limite de 420 → test rouge. Reproduit ici en forçant l'absence de `pdftoppm` :
`Failed asserting that 1240 is equal to 420 or is less than 420.`

Deux autres hypothèses écartées par la mesure :

- **cache** : le test écrit dans un dossier temporaire neuf (`sys_get_temp_dir()/gedv2-vignette-*`), donc aucune
  vignette versionnée n'est relue ; et les `.apercu/*.vignette.png` commités font déjà ≤ 400 px (283 × 400) ;
- **version de poppler** : `pdftoppm 26.02.0` (man installé) scale bien le plus grand côté ; le repli, lui,
  produit 1240 pour n'importe quelle version de Ghostscript.

Second défaut trouvé en corrigeant, sur la même branche : `-dPDFFitPage` **plaquait la page sur le format par
défaut du device** (A4/letter) ; une page paysage sortait **redressée en portrait** (283 × 400 au lieu de
400 × 283). Il est retiré : Ghostscript rend alors le `MediaBox` de la page, comme `pdftoppm` par défaut.

## Doc officielle lue (outil installé)

- **pdftoppm 26.02.0** (poppler) — man installé `C:\ged\outils\poppler\share\man\man1\pdftoppm.1` :
  `-scale-to number` « Scales the long side of each page (width for landscape pages, height for portrait pages)
  to fit in scale-to pixels. The size of the short side will be determined by the aspect ratio of the page. » ;
  `-r number` « Specifies the X and Y resolution, in DPI. The default is 150 DPI. » ;
  `-singlefile` « Writes only the first page and does not add digits. »
  Source amont identique : <https://gitlab.freedesktop.org/poppler/poppler/-/raw/master/utils/pdftoppm.1>.
- **GPL Ghostscript 10.02.1** — doc livrée `C:\ged\outils\gs\doc\Ghostscript.pdf`
  (Ghostscript Documentation, Release 10.02.0) :
  - **§ 6.2.1 « Output resolution », p. 47** : `gs -sDEVICE=printer -rXRESxYRES` ; « The **-r** option is also
    useful for controlling the density of pixels when rasterizing to an image file. »
  - **`-dPDFFitPage`, p. 51-52** : « Rather than selecting a PageSize given by the PDF MediaBox … the PDF file
    will be scaled to fit the current device page size (usually the default page size). » → c'est ce qui
    redressait le paysage.
  - `-dFirstPage`/`-dLastPage` : sélection de page (page 1 seule ici).
  Miroir en ligne de la même édition : <https://ghostscript.readthedocs.io/en/latest/Use.html#output-resolution>
  et <https://ghostscript.readthedocs.io/en/latest/Use.html#dpdffitpage>.

## Correction

`src/Apercu/Outils.php` :

1. Nouvelle méthode privée `bornerDimension(string $png, int $max): bool` : si le PNG dépasse la boîte
   `max × max`, il est rééchantillonné (GD `imagecopyresampled`) dans cette boîte, proportions conservées ;
   renvoie `false` (→ message nommé `MSG_CONVERSION`) si le PNG est illisible, jamais un succès silencieux.
2. Les **deux** branches de `pdfVersPng()` passent par `bornerDimension()` avant de rendre `null`. Le chemin
   `pdftoppm` déjà conforme ressort sans réencodage (aucune autre vignette modifiée).
3. Retrait de `-dPDFFitPage` de la commande Ghostscript (voir Cause) : le rendu suit le `MediaBox`.

Le paramètre demandé reste `400` (limite de test `420` inchangée).

## TDD (oracle rouge avant le code)

- Oracle ajouté : `MiniatureTest::testLaVignetteDuRepliGhostscriptRespecteLaTaille` force le repli Ghostscript
  (simule l'absence de `pdftoppm` par le cache d'outils du service, restauré ensuite) puis vérifie les deux
  côtés ≤ 420. **Vu rouge** avant correction : `Failed asserting that 1240 is equal to 420 or is less than 420.`
- Second oracle : `MiniatureTest::testLeRepliGhostscriptConserveLORientationDUnePagePaysage` (PDF paysage
  synthétique construit par le test, sans toucher au corpus) vérifie « paysage reste paysage » ; il échoue si
  `-dPDFFitPage` revient.

## Mesures — 4 PDF, 2 moteurs (après correction)

| Document | pdftoppm (défaut) | repli Ghostscript |
| --- | --- | --- |
| 201 — facture A4 portrait | 283 × 400 | 283 × 400 |
| 204 — contrat A4 portrait, 3 pages | 283 × 400 | 283 × 400 |
| paysage 842 × 595 pt | 400 × 283 | 400 × 283 |
| carré 200 × 200 pt | 400 × 400 | 400 × 400 |

Avant correction, le repli Ghostscript rendait 204 en **1240 × 1754** (et le paysage en 283 × 400 redressé).
Après correction, les deux moteurs donnent **exactement** les mêmes dimensions sur les quatre documents.

## Vérifications

- `php vendor/bin/phpunit tests/php/Apercu` : **24 tests, 70 assertions, vert**.
- `php vendor/bin/phpunit` (suite complète) :
  - base sans le lot (`git stash`) : **598 tests**, 3 343 assertions, **1 erreur + 12 échecs**, 1 sauté ;
  - avec le lot : **600 tests**, 3 359 assertions, **1 erreur + 12 échecs**, 1 sauté.
  - **+2 tests, +16 assertions, 0 nouvel échec.** Les 13 rouges sont identiques avant/après et tous dus à
    l'environnement de ce worktree : le moteur Python d'ingestion est **absent** (`C:\ged\cmd4` n'existe pas ;
    messages nommés « La retouche de page est indisponible : moteur PDF absent (vérifiez PyMuPDF dans
    CMD4_PYTHON) »). Concernés : `Socle\InstallateurTest` (4), `Ingestion\EditeurPdfTest` (5),
    `Ingestion\EditionPiecesTest` (1), `Securite\DeplacementDroitsTest` (1), `EcransTest` (1),
    `Socle\V212cTest` (1) + l'erreur en cascade `InstallateurTest::testRemplacerFaitUnDumpDabordPuisReposeLeSchema`.
    Aucun test d'`Apercu`/`Recherche` n'est rouge. Sur le poste du juge (moteur présent) la suite est à
    **600 tests, 0 échec**.
- `php vendor/bin/phpstan analyse --memory-limit=2G --no-progress` : **0 erreur** niveau 6, `phpstan-baseline.neon`
  **vide**.

## Commandes lancées

```
pdftoppm -v ; gswin64c --version
php vendor/bin/phpunit --filter testGenereUneVignetteDepuisUnPdf        # vert (poppler présent) — masque le défaut
php vendor/bin/phpunit --filter testLaVignetteDuRepliGhostscriptRespecteLaTaille   # ROUGE 1240 avant correction
php var/verifier-vignettes.php                                          # matrice 4 PDF × 2 moteurs (script hors dépôt)
php vendor/bin/phpunit tests/php/Apercu
php vendor/bin/phpunit
php vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

## Porte qualité (ECC)

- **Analyse statique** : PHPStan niveau 6, **0 erreur**, baseline vide (jamais grossie) ; aucun `@phpstan-ignore`.
- **TDD** : oracle rouge commité avec le code (vue rouge 1240 px) ; assertions existantes inchangées (420 px).
- **Revue du diff (php-reviewer)** : `declare(strict_types=1)`, méthode privée typée (`string, int): bool`),
  < 30 lignes, pas de nombres magiques neufs (la boîte vient du paramètre), aucune `use` ajoutée.
- **Revue du diff (security-reviewer)** : aucune entrée utilisateur nouvelle ; le shell reste `escapeshellarg`
  (inchangé) ; pas de SQL ; `imagecreatefrompng` ne lit qu'un PNG produit à l'instant par le moteur de rendu,
  sous la racine documentaire ; échec → message nommé, aucune donnée exposée.
- **Revue du diff (silent-failure-hunter)** : c'est le cœur de la correction — l'ancien code rendait `null`
  (succès) dès que le fichier existait, même avec un rendu hors taille ou illisible. Désormais la taille est
  contrôlée et un PNG illisible rend `MSG_CONVERSION`. Les `@getimagesize`/`@imagecreatefrompng` sont suivis
  d'un contrôle de retour nommé, jamais ignorés. Observation (hors périmètre, préexistante) : `executer()`
  ignore le code de sortie des outils ; non modifié ici.
- **Duplication assumée** : `ecrirePdfPaysage()` dans le test réécrit un PDF minimal plutôt que d'inclure
  `tests/corpus/apercu/generer.php` (script qui régénère le corpus et sort) : le corpus et ses binaires ne sont
  pas touchés.

## Reste / hors périmètre

- Aucun ajout de document paysage permanent au corpus (couvert par un PDF synthétique du test).
- Le rappel d'`executer()` (code de sortie ignoré) reste à traiter dans un lot d'outillage du secteur.

ETAT: fini
