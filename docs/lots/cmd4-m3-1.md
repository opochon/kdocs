# Lot cmd4-m3-1 — le moteur CMDv4 (`cmd4_ingest`) disponible sur M3

Agent : DeepCode · Juge : Claude (poste principal) · Branche : `ged/cmd4-m3-1`
Date : 2026-10-11 · Machine : M3 (`DESKTOP-84CUQNE`, Python 3.14.3)

## But

Sur M3, le moteur externe de découpe/édition manquait : `C:\ged\cmd4` n'existait pas et le `.env`
pointait vers un venv absent → **13 tests rouges** (dont `Ingestion\EditeurPdfTest`,
`Ingestion\EditionPiecesTest`, `Socle\InstallateurTest`, `Securite\DeplacementDroitsTest`,
`EcransTest`, `Socle\V212cTest`). Le lot installe le produit CMDv4 et son venv sur M3, puis vérifie
les tests de découpe/édition, la suite complète et PHPStan.

## Écart de source — constat

La mission indiquait `clearmydocs-v3` branche `main`, sous-dossier `cmdv4/product` (commit `0dd7165`).

- **Mesure** : `main` (HEAD `0dd7165`, 01-07-2026) ne contient **aucun** module `cmd4_ingest` sous
  `cmdv4/product` (le `cmdv4/` de main est une autre tranche : CLI `cmd4 run --corpus`, pipeline texte).
- Le moteur appelé par la GED (`python -m cmd4_ingest … ingest <pdf>`) vit dans le **même dépôt**,
  branche `feature/gouvernance-cmd4` (HEAD `2aff63f`, 07-09-2026, donc **plus récente** que `0dd7165`),
  sous `v4/product/cmd4_ingest/`. Sa CLI correspond exactement à `Cmd4Decoupeur` : sous-commande
  `ingest`, `--no-vision`, `--out-dir`, JSON `documents[]` avec `pages`, `text`, `invoice`, `origin`.
- **Décision** : copier `v4/product` (et le paquet frère `v4/cmd4`, requis par `lexique.py`) de
  `feature/gouvernance-cmd4` — source réelle, non inventée — plutôt que `main:cmdv4/product`, qui ne
  satisfait pas le critère `python -m cmd4_ingest --help`. Aucun fichier de code n'est cousu à la main.

Deux dépendances réelles ne sont **pas** déclarées dans `requirements.txt` du produit, et sont donc
installées explicitement :

- `zxing-cpp` (`import zxingcpp`) — lecture des codes-barres par `cmd4_ingest.barcodes`, sans laquelle
  `ingest` s'arrête (`ModuleNotFoundError`).
- Les profils `cmd4/builtin_profiles/{default,legal_ch}.json` attendus à côté de `product/`
  (`parents[2]/cmd4/builtin_profiles`), sinon `FileNotFoundError: profil introuvable`.

## Installation (reproductible)

```bash
# 1. cloner la source réelle (branche qui porte cmd4_ingest)
git clone --depth 1 --branch feature/gouvernance-cmd4 https://github.com/opochon/clearmydocs-v3.git /tmp/clearmydocs-v3

# 2. copier les fichiers SUIVIS seulement (donc ni .venv, ni __pycache__, ni .pytest_cache)
mkdir -p C:/ged/cmd4
git -C /tmp/clearmydocs-v3 archive HEAD v4/product | tar -x -C C:/ged/cmd4   # -> C:/ged/cmd4/v4/product
git -C /tmp/clearmydocs-v3 archive HEAD v4/cmd4    | tar -x -C C:/ged/cmd4   # -> C:/ged/cmd4/v4/cmd4
mv C:/ged/cmd4/v4/product C:/ged/cmd4/product
mv C:/ged/cmd4/v4/cmd4    C:/ged/cmd4/cmd4
rmdir C:/ged/cmd4/v4

# 3. venv + deps
C:/Python314/python.exe -m venv C:/ged/cmd4/product/.venv
C:/ged/cmd4/product/.venv/Scripts/python.exe -m pip install -r C:/ged/cmd4/product/requirements.txt
C:/ged/cmd4/product/.venv/Scripts/python.exe -m pip install zxing-cpp   # absent de requirements.txt

# 4. vérifier
cd C:/ged/cmd4/product && ./.venv/Scripts/python.exe -X utf8 -m cmd4_ingest --help
```

`.env` : `CMD4_PYTHON=C:\ged\cmd4\product\.venv\Scripts\python.exe`, `CMD4_PRODUIT=C:\ged\cmd4\product`
(déjà présents et corrects sur M3 ; **aucune autre clé touchée**).

## Versions

| Élément | Version |
| --- | --- |
| Python | 3.14.3 (`C:\Python314`, venv 3.14.3) |
| pip | 25.3 |
| PyMuPDF | 1.28.2 (`pymupdf`, `pymupdf4llm`, `pymupdf-layout`) |
| zxing-cpp | 3.1.1 |
| Pillow / pytesseract / numpy / onnxruntime | 12.3.0 / 0.3.13 / 2.5.4 / 1.31.0 |
| Tesseract OCR (binaire externe) | `C:\ged\outils\tesseract` (PATH + `TESSDATA_PREFIX`) |

## Preuves (M3)

- Moteur : `python -m cmd4_ingest --help` → code 0 (129 ms).
- Passe réelle de découpe (PDF synthétique 2 pages, `--no-vision --out-dir`) : code 0, **1 document**,
  `pages=[1,2]`, `invoice.is_invoice=true`, `origin` complet, PDF `entree_part001.pdf` écrit ; **1,71 s**.
- `php vendor/bin/phpunit tests/php/Ingestion/EditeurPdfTest.php tests/php/Ingestion/EditionPiecesTest.php` :
  **6 tests, 46 assertions, vert** (33,1 s).
- `php vendor/bin/phpunit` (suite complète) : **600 tests, 3422 assertions, 1 sauté, 0 échec**
  (8 min 29 s) — les 13 rouges d'environnement sont revenus verts.
- `php vendor/bin/phpstan analyse --memory-limit=2G` : **0 erreur**, niveau 6, `phpstan-baseline.neon` vide.

## Porte qualité (ECC)

1. **Analyse statique** : PHPStan niveau 6, 0 erreur, baseline vide (2 lignes d'en-tête). Aucune baseline
   touchée, aucun `@phpstan-ignore`.
2. **Revue du diff** : ce lot n'ajoute que de la documentation (+ `docs/lots/cmd4-m3-1.md`). Aucun code
   PHP, aucun SQL, aucun `catch (Throwable)` — rien à corriger côté GEDv2. Le correctif vit hors dépôt
   (installation du moteur) et est entièrement scripté ci-dessus.
3. **TDD / oracles** : l'oracle rouge est l'environnement absent (6 tests ciblés + 600 de suite, dont 13
   rouges nommés à l'environnement). Après installation : 6/6 puis 600/600. La passe réelle du moteur est
   ajoutée comme oracle fonctionnel de la découpe (les tests PHPUnit utilisent un découpeur de test).
4. **Journal** : chiffres ci-dessus.

ETAT: fini
