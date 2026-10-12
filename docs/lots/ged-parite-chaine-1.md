# Lot ged-parite-chaine-1 — rattraper les 46 écarts de la chaîne de consommation

Base : `ged-parite-comportement-1` fusionné (`13b94a6`) : correspondance 137/137, dates 25/25, parité stricte
23,97 %. Cible : les 46 éléments `chaine` encore `absent` (classes de la consommation, codes-barres, étapes
preflight/surcharges, gabarit de nom de fichier, registre des parseurs, parseurs, post-traitement OCR).

Méthode oracle d'abord, une famille à la fois : vecteurs extraits en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) → rejeu PHPUnit → rouge → implémentation GEDv2 →
vert → commit. Aucune ligne de code Paperless (GPL-3) n'est reprise : seuls des couples `{entree, sortie}`
sont extraits. Un commit par famille.

## Fait (commits)

- `5077e57` — **oracle** : `tools/parite/extraire-chaine.py` + vecteurs commités avant tout code :
  `chaine-registre.json` (41), `chaine-ocr-text.json` (3), `chaine-codebarres.json` (4),
  `chaine-gabarit.json` (25). Format et outillage de `tools/parite/extraire-paperless.py`.
- `7e198c0` — **registre des parseurs** : `Ged\Ingestion\RegistreParseurs` rejoue 39/41 vecteurs
  (2 vecteurs Django à registre mocké vide, comptés `non_rejouables` et le test échoue si leur nombre
  change). Couvre `get_default_file_extension`, `get_supported_file_extensions`, `is_file_ext_supported`,
  `is_mime_type_supported`, `get_parser_class_for_mime_type`, `run_convert`.
- `c198c80` — **post-traitement OCR** : `Ged\Ingestion\TexteOcr::postTraiter` rejoue les 3 vecteurs
  (oracle lancé rouge avant le code : classe absente).
- `10c8291` — **codes-barres** : `Ged\Ingestion\CodeBarres` rejoue les 4 vecteurs `separate_pages`
  (le nombre de pages de chaque PDF échantillon est lu par l'extracteur) et couvre `is_separator`,
  `is_asn`, `BarcodePlugin.run`.
- `20e3f7a` — **gabarit de nom de fichier** : `Ged\Ingestion\GabaritChemin` rejoue 25/25 vecteurs
  (dates de création/ajout, étiquettes, champs, placeholders, « none », slugify, datetime, nom par
  défaut). L'extracteur a été complété AVANT la mise au vert pour capturer `doc.correspondent = …`
  (le vecteur `slugify` était incomplet) : 1 ligne de vecteur complétée, jamais affaiblie.
- `176aa2a` — **preflight et surcharges** : `ErreurConsommation` (ConsumerError),
  `MessageStatut` (ConsumerStatusShortMessage), `EtapesConsommation` (ConsumerPluginMixin),
  `ControleAvant` (ConsumerPreflightPlugin, `pre_check_asn_value`, `run`), `Surcharges`
  (`apply_overrides`), `GabaritTitre` (`_parse_title_placeholders`).
- `df6fbdc` — **parseurs** : `Ged\Ingestion\ParseurPiece` (métadonnées, pages, texte local, vignette
  déléguée à `Apercu`) ; les parseurs de courriel s'appuient sur `Ged\Jonctions\Courriel`, la chaîne OCR
  sur `Ged\Ingestion\Pilote`.
- `59ab296` — **correspondance** régénérée : 41 éléments passent d'`absent` à couvert/couvert-autrement ;
  `docs/parite/PARITE-PAPERLESS.md` régénéré par le juge, qui gagne une section « Chaîne de consommation
  (phase 3) » (additive).
- `aa06dc8` — PHPStan niveau 6 : 0 erreur hors baseline (baseline vide).

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par le juge)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 187 / 780 | **228 / 780** |
| Parité stricte | 23,97 % | **29,23 %** |
| Parité hors exclusions | 25,55 % | **31,15 %** |
| `chaine` couverts | 0 / 46 (14 déjà couverts / 60) | **55 / 60** (41 nouveaux) |

### Comportement rejoué (phase 3 du rapport)

| Famille | Fichier de vecteurs | Passes | Total | Taux |
| --- | --- | ---: | ---: | ---: |
| codebarres | `tests/parite/vecteurs/chaine-codebarres.json` | 4 | 4 | 100,00 % |
| gabarit | `tests/parite/vecteurs/chaine-gabarit.json` | 25 | 25 | 100,00 % |
| ocr-text | `tests/parite/vecteurs/chaine-ocr-text.json` | 3 | 3 | 100,00 % |
| registre | `tests/parite/vecteurs/chaine-registre.json` | 39 | 41 | 95,12 % |

Les 2 vecteurs « registre » non rejoués sont `test_get_parser_class_0_parsers` et
`test_get_parser_class_no_valid_parser` : ils vident le registre Django par un mock
(`document_consumer_declaration.send`) et ne décrivent pas GEDv2 (qui n'a pas de registre par signaux).

### PHPUnit

696 tests, 9 950 assertions, 1 sauté (préexistant), 0 échec. Nouveaux : `ChaineRegistreTest` (3),
`ChaineOcrTest` (2), `ChaineCodeBarresTest` (3), `ChaineGabaritTest` (5), `ChainePreflightTest` (9),
`ChaineParseursTest` (4).

## Restants (5 éléments `chaine`, écarts assumés, raison + référence)

| Id | Raison | Lot |
| --- | --- | --- |
| `chaine:parseur:TextDocumentParser.get_thumbnail` | GEDv2 ne fabrique pas de vignette texte : l'aperçu est le texte lui-même (`Apercu\Piece`, `Apercu\Source`). | V2-apercu-texte |
| `chaine:parseur:TikaDocumentParser.extract_metadata` | Conversion bureautique (LibreOffice → PDF via `Apercu\Outils::versPdf`) non prouvée par un test. | V2-bureautique |
| `chaine:parseur:TikaDocumentParser.get_thumbnail` | Vignette bureautique non prouvée (LibreOffice → PDF → PNG). | V2-bureautique |
| `chaine:parseur:TikaDocumentParser.parse` | Parse bureautique délégué à LibreOffice/Gotenberg, non prouvé. | V2-bureautique |
| `chaine:parseur:documents.get_default_thumbnail` | GEDv2 nomme l'absence (`Apercu\Outils::MSG_FORMAT`), jamais une image générique de remplacement. | V2-apercu-defaut |

Références Paperless : `src/documents/parsers.py`, `src/paperless_tika/parsers.py`, `src/paperless_text/parsers.py`.

## Vecteurs propres à Django / Python / Celery (listés, non contournés)

- **Codes-barres, lecture PDF → image → pyzbar/zxing** (`test_barcodes.py`, `BarcodePlugin.detect`,
  `read_barcodes_*`) : dépend de `pdf2image`, `pikepdf`, `pyzbar`/`zxingcpp`. GEDv2 lit ses codes-barres
  par un moteur externe ; seules la classification et la découpe sont rejouées.
- **`test_consumer.py`** (preflight, surcharges, `_parse_title_placeholders`, `apply_overrides`,
  `pre_check_asn_value`, `run`) : tests d'intégration Django/Celery (base, `tasks.consume_file`, mocks de
  `ProgressManager`). Pas de couple `{entree, sortie}` pur ; les comportements sont prouvés par les tests
  GEDv2 de `ChainePreflightTest`.
- **Parseurs Python** (`RasterisedDocumentParser` Tesseract/ocrmypdf, `TikaDocumentParser` Gotenberg,
  `TextDocumentParser` lecture de fichier) : OCR et conversions externes, hors PHPUnit. Couverts
  autrement quand un équivalent GEDv2 est prouvé, sinon listés ci-dessus.
- **Gabarit Jinja de Paperless** (`{% if %}`, `{% elif %}`, arithmétique, `or`, `localize_date`
  multilingue, `get_cf_value` conditionnel) : GEDv2 n'embarque pas de moteur de gabarit généraliste.
  Ces cas de `TestFilenameGeneration` (`test_placeholder_matches_none_variants_and_false`,
  `test_complex_template_strings`, `test_template_with_security`, `test_template_with_custom_fields`)
  et `TestPathDateLocalization` ne sont pas extraits. Le rendu placeholder-only (25 vecteurs) est couvert.

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → 0 erreur, niveau 6,
  baseline vide inchangée.
- **TDD** : vecteurs commités avant tout code (`5077e57`) ; pour l'OCR et les codes-barres, le rejeu a été
  lancé rouge (classe absente) avant le service. Aucun test affaibli, aucun vecteur modifié après coup
  (le seul changement de vecteur est une entrée COMPLÉTÉE avant la mise au vert).
- **Revue du diff** (grilles php-reviewer / security-reviewer / silent-failure-hunter) :
  `declare(strict_types=1)` partout, classes `final` (sauf `EtapesConsommation`, base assumée),
  méthodes courtes et typées, aucun SQL, aucun secret, aucun `shell_exec` introduit. Aucune exception
  avalée : `ErreurConsommation` nomme fichier + cause ; `ParseurPiece::vignette` rend un message NOMMÉ
  d'`Outils` au lieu d'un échec muet ; `GabaritChemin::rendre` renvoie `null` sur placeholder inconnu,
  l'appelant retombe sur le nom par défaut. Le seul `@` ajouté est `@file_get_contents` dans
  `ParseurPiece::nombreDePages`, sur un fichier dont l'existence est vérifiée juste avant : un PDF illisible
  rend `null`, jamais une exception.
- **Réserve** : `ParseurPiece::nombreDePages` compte les objets `/Type /Page` du PDF ; c'est exact sur les
  PDF testés (1 et 100 pages) mais n'ouvre pas les PDF à arbre de pages compressé (`/ObjStm`). Les PDF
  chiffrés (test Paperless) ne sont pas couverts.

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

Rejeu du 2026-10-12 (13 m 13 s). `tools/composer.phar` (outil gitignoré, absent de ce worktree) restauré
depuis la copie de référence avant le rejeu : sans lui la couche 8 est ROUGE à tort (« Could not open
input file »).

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 696 tests, 9 950 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | VERT | 80 passés (3,9 m) |
| 7-sécurité | VERT | aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture 674 ms, p95 écriture 244 ms, 0 erreur, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 331–686 ms) |
| 9-parité | VERT | 780 éléments, 228 couverts, strict 29,23 %, hors exclusions 31,15 %, 0 non prouvé |

La couche 3 étant sautée par `-SansBanc`, aucun banc n'a été rejoué : ce lot ne touche ni la découpe, ni le
classement, ni l'ingestion (uniquement `src/Ingestion` en ajouts et `tools/parite`). Les oracles de
classement et d'ingestion restent verts dans la couche 1 (`OracleCourrier100pTest`,
`OracleClassementBruiteTest`, `OracleClassementRegressionTest`, `PiloteTest` : 0 échec).

ETAT: fini
