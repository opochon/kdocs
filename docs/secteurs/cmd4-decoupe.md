# Secteur CMD4-DÉCOUPE — moteur externe `cmd4_ingest` (ClearMyDocs v4)

Agent : DeepCode · Juge : Claude (poste principal) · Machine de référence : M3

## Mission

Fournir à la GED, sur chaque poste, le moteur externe de découpe/qualification de pièces **CMDv4**
(`cmd4_ingest`, dépôt ClearMyDocs) et l'interpréteur Python que `CMD4_PYTHON` / `CMD4_PRODUIT`
désignent. La GED le consomme comme service (aucun démon obligatoire) :

- `Ged\Ingestion\Cmd4Decoupeur` → `python -X utf8 -m cmd4_ingest [--no-vision] --out-dir <dir> ingest <pdf>`
  (JSON `documents[]` : `pages`, `text`, `invoice`, `origin`) ;
- `Ged\Ingestion\EditeurPdf` → `CMD4_PYTHON agent/editer_pdf.py` (PyMuPDF), utilisé aussi par
  l'installateur et l'écran d'édition de pages.

## Chemins

- `C:\ged\cmd4\product\` — produit CMDv4 (dont le paquet `cmd4_ingest\`) = `CMD4_PRODUIT`.
- `C:\ged\cmd4\product\.venv\` — venv Python = `CMD4_PYTHON`
  (`…\.venv\Scripts\python.exe`). Hors dépôt (non versionné).
- `C:\ged\cmd4\cmd4\` — **paquet moteur frère**, requis : `cmd4_ingest/lexique.py` lit les profils
  `C:\ged\cmd4\cmd4\builtin_profiles\{default,legal_ch}.json`.
- `.env` (local, gitignoré, non versionné) : clés `CMD4_PYTHON`, `CMD4_PRODUIT`,
  `CMD4_VISION_ENABLED`. Aucune autre clé n'est touchée par ce secteur.
- Tesseract OCR (binaire externe, pour l'OCR) : `C:\ged\outils\tesseract` (PATH + `TESSDATA_PREFIX`,
  posés par `C:\ged\env.ps1`).

Ce secteur ne modifie **que** cette fiche et son journal ; aucun fichier de `src/`, `tests/`, `agent/`,
`tools/` ou `templates/`.

## Source et version

- Dépôt : <https://github.com/opochon/clearmydocs-v3>
- `main` (HEAD `0dd7165`, 01-07-2026) **ne porte pas** `cmd4_ingest` sous `cmdv4/product` : le critère
  `python -m cmd4_ingest --help` y est impossible.
- Source retenue : branche **`feature/gouvernance-cmd4`**, HEAD **`2aff63f`** (07-09-2026, plus récente
  que `0dd7165`), sous-dossiers `v4/product` et `v4/cmd4`. C'est la seule source du dépôt dont la CLI
  correspond exactement à `Cmd4Decoupeur` (`ingest`, `--no-vision`, `--out-dir`, `documents[].origin`).
- Écart signalé au juge dans `docs/lots/cmd4-m3-1.md` ; aucune ligne de code cousue à la main.

## Installation (reproductible, hors dépôt)

```bash
git clone --depth 1 --branch feature/gouvernance-cmd4 https://github.com/opochon/clearmydocs-v3.git /tmp/clearmydocs-v3
mkdir -p C:/ged/cmd4
git -C /tmp/clearmydocs-v3 archive HEAD v4/product | tar -x -C C:/ged/cmd4   # -> v4/product
git -C /tmp/clearmydocs-v3 archive HEAD v4/cmd4    | tar -x -C C:/ged/cmd4   # -> v4/cmd4
mv C:/ged/cmd4/v4/product C:/ged/cmd4/product && mv C:/ged/cmd4/v4/cmd4 C:/ged/cmd4/cmd4 && rmdir C:/ged/cmd4/v4
C:/Python314/python.exe -m venv C:/ged/cmd4/product/.venv
C:/ged/cmd4/product/.venv/Scripts/python.exe -m pip install -r C:/ged/cmd4/product/requirements.txt
C:/ged/cmd4/product/.venv/Scripts/python.exe -m pip install zxing-cpp
```

`git archive` ne livre que les fichiers **suivis** : ni `.venv`, ni `__pycache__`, ni `.pytest_cache`.
Doc lue avant d'installer : `venv`/`ensurepip` Python 3.14 (<https://docs.python.org/3.14/library/venv.html>),
pip (<https://pip.pypa.io/en/stable/>), PyMuPDF 1.28.x (roues `cp310-abi3`, `requires_python >=3.10`).

**Dépendances non déclarées par le produit, installées en plus :**

- `zxing-cpp` (`import zxingcpp`) : sans lui `cmd4_ingest ingest` s'arrête sur `ModuleNotFoundError`
  (importé par `cmd4_ingest/barcodes.py`, jamais listé dans `requirements.txt`).
- Le paquet frère `cmd4/` (profils `builtin_profiles`) : sans lui `FileNotFoundError: profil introuvable`.

## Versions (M3, 2026-10-11)

| Élément | Version |
| --- | --- |
| Python / pip | 3.14.3 / 25.3 |
| PyMuPDF | 1.28.2 (`pymupdf`, `pymupdf4llm`, `pymupdf-layout`) |
| zxing-cpp | 3.1.1 |
| Pillow / pytesseract / numpy / onnxruntime | 12.3.0 / 0.3.13 / 2.5.4 / 1.31.0 |
| Tesseract OCR | `C:\ged\outils\tesseract` |

## Commandes et mesures (M3)

```
cd C:/ged/cmd4/product && ./.venv/Scripts/python.exe -X utf8 -m cmd4_ingest --help   # code 0, 129 ms
# passe réelle (PDF synthétique 2 pages) : --no-vision --out-dir <dir> ingest <pdf>
#   code 0, 1 document, pages=[1,2], invoice.is_invoice=true, origin complet, 1,71 s
php vendor/bin/phpunit tests/php/Ingestion/EditeurPdfTest.php tests/php/Ingestion/EditionPiecesTest.php
#   6 tests, 46 assertions, vert (33,1 s)
php vendor/bin/phpunit          # 600 tests, 3422 assertions, 1 sauté, 0 échec (8 min 29 s)
php vendor/bin/phpstan analyse --memory-limit=2G   # 0 erreur, niveau 6, baseline vide
CMD4_VISION_ENABLED=false php tools/banc/mesurer-decoupe.php courrier-100p
#   53 documents / 53 attendus ; 51 exacts, 1 frontière manquée (page 29) → porte NON FRANCHIE (161 s)
```

Bilan : les 13 rouges d'environnement de M3 (`Socle\InstallateurTest`, `Ingestion\EditeurPdfTest`,
`Ingestion\EditionPiecesTest`, `Securite\DeplacementDroitsTest`, `EcransTest`, `Socle\V212cTest` et
l'erreur en cascade `InstallateurTest::testRemplacerFaitUnDumpDabordPuisReposeLeSchema`) sont verts.

## Demandes

- **À l'outillage / au lanceur** : un script d'installation idempotent du moteur (clonage épinglé,
  `git archive`, venv, `requirements.txt` **+ `zxing-cpp`**, copie du paquet frère `cmd4/`) éviterait de
  refaire ces gestes à la main sur un nouveau poste. Non réalisé ici : hors périmètre de ce secteur.
- **À ClearMyDocs (amont)** : déclarer `zxing-cpp` dans `v4/product/requirements.txt`, et penser le
  produit comme un ensemble `product/` + `cmd4/` (les profils ne sont pas dans `product/`).
- **À l'Ingestion (GEDv2)** : `Cmd4Decoupeur` lance `cmd4_ingest` sans transmettre `CMD4_VISION_ENABLED`
  ni `INFOMANIAK_AI_API_KEY` / `INFOMANIAK_AI_API_SECRET` ; le moteur les lit dans `os.environ`, donc la
  vision reste inerte via `.env` seul. Sur `courrier-100p`, la frontière page 29 n'est pas arbitrée et la
  porte V2-02 tombe à 51/53 (contre 53/53 dans la preuve M2). Demande : transmettre l'environnement
  vision au sous-processus, ou fournir le commit exact du moteur utilisé sur M2.

## Journal cmd4-m3-1 — 11-10-2026

- Oracle rouge : sur M3, `C:\ged\cmd4` absent, `.env` pointant vers un venv inexistant → 13 tests rouges
  nommés à l'environnement.
- Fait : produit CMDv4 (`v4/product`) + paquet frère (`v4/cmd4`) extraits par `git archive` depuis
  `feature/gouvernance-cmd4` (HEAD `2aff63f`) ; venv Python 3.14.3 créé ; `requirements.txt` +
  `zxing-cpp` installés ; `python -m cmd4_ingest --help` vert ; passe réelle de découpe verte.
- Fait : `.env` déjà correct sur M3 (`CMD4_PYTHON`, `CMD4_PRODUIT`), aucune autre clé modifiée.
- Vert : tests ciblés 6/6 ; suite 600/600 (1 sauté) ; PHPStan 0 erreur, baseline vide.
- Porte qualité (ECC) : analyse statique 0 erreur ; diff = documentation seule (aucun code PHP/SQL,
  aucun `catch (Throwable)`) ; oracle rouge d'environnement → vert ; chiffres au journal du lot.
- Réserve : porte V2-02 sur `courrier-100p` = 53 documents, 51/53 exacts (frontière page 29), 0 page
  perdue, 0 doublon → NON FRANCHIE, avec et sans vision (`split` rend `vision_calls=0`). Pièces de banc
  retirées (`var/banc/`, ignoré) pour que la suite reste verte ; preuves versionnées restaurées.

ETAT: fini
