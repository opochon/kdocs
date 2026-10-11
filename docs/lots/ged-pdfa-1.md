# Lot ged-pdfa-1 — copie d'archivage PDF/A-2b à l'ingestion

Agent : DeepCode · Juge : Claude (poste principal) · Branche : `ged/ged-pdfa-1`

## But

Chaque document **PDF ou image** ingéré reçoit, **à côté de l'original** (jamais modifié, jamais remplacé), une
copie d'archivage **PDF/A-2b**, texte OCR compris quand il existe. C'est cette copie qui sert à la consultation
longue durée ; l'original reste la pièce qui fait foi (empreinte, scellement inchangés). Échec de conversion =
document ingéré quand même, statut d'échec visible, jamais de perte.

## Mécanisme natif retenu (Ghostscript 10.02.1 installé)

Doc officielle `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 14.7 « Creating a PDF/A document »,
p. 315-316** (pages physiques 319-320) : `-dPDFA=2`, `-sColorConversionStrategy=RGB`, un *PDF/A definition
file* (`lib/PDFA_def.ps`) portant l'`ICCProfile` de l'OutputIntent (`iccprofiles/srgb.icc`), et
`PDFACompatibilityPolicy` = 1 (« the feature or operation is ignored, the resulting PDF file will be PDF/A
compliant ») plutôt que 0 (sortie non conforme silencieuse) ou 2 (abandon). Commande :

```
gs -dPDFA=2 -dBATCH -dNOPAUSE -dNOOUTERSAVE -sColorConversionStrategy=RGB -sDEVICE=pdfwrite
   -dPDFACompatibilityPolicy=1 -sOutputFile=<copie> --permit-file-read=<srgb.icc>
   -f <PDFA_def.ps modifié (ICC absolu)> <source>
```

Le `PDFA_def.ps` livré est **relu** (aucune ligne Ghostscript recopiée dans le dépôt) puis réécrit avec le
chemin absolu du profil ICC (sinon il dépend du répertoire courant) ; `--permit-file-read` est requis par le
mode SAFER de Ghostscript 10.

## Fait (commits)

- `e83765a` — oracle rouge : `tests/php/Ingestion/CopieArchivageTest.php` (11 tests, 81 assertions) + support ;
  vu rouge « Class Ged\Ingestion\CopieArchivage not found ».
- `40ec37f` — service vert : `ConversionPdfA` (mécanisme natif, sortie atomique, raisons nommées),
  `EnveloppeImage` (image → PDF d'une page), `ControlePdfA` (contrôle PARTIEL), `CopieArchivage`
  (convention `.pdfa/`, bloc meta/flanc scellé, idempotent), `RattrapagePdfA` (reprenable, écart).
- `647f376` — branchement ingestion réel : `Pilote::ranger()` produit la copie après le dépôt ;
  `PilotePdfATest` (2 tests) prouve le branchement et « échec de copie ⇒ document ingéré ».
- `74d600c` — écrans : fiche document (état + téléchargement `GET /documents/{id}/pdfa`), écran Application
  (lancer / tranche / progression / écart), `tools/tache.php pdfa` ; `PdfaEcranTest` (3 tests) et
  `TachePdfATest` (1 test). Corrections : « chiffré » réservé au vrai mot de passe ; un échec n'est plus pris
  pour une copie déplacée.
- `ff6ba40` — fiche secteur `docs/secteurs/ingestion.md` (convention, note Ghostscript, contrôle partiel,
  budget) et E2E `tests/e2e/pdfa.spec.ts` + `preparer-pdfa.php` (2/2 vert).
- `78cf2b2` — sonde sécurité : charges défensives CSRF pour les deux routes `pdfa-rattrapage` (couche 7
  rouge corrigée) ; délai E2E explicite (90 s) pour la vraie conversion sous charge.
- `8a032c5` — durcissement revue ECC : la définition `PDFA_def.ps` temporaire est créée dans le `try` (une
  panne rend une raison nommée) ; l'enveloppe PDF d'une image va dans le dossier temporaire du système,
  jamais dans le fonds.

## Reste

- Rien du périmètre fonctionnel. `veraPDF` (validation de recette réelle, absente du poste : pas de Java)
  reste à rejouer par le juge sur les copies d'un vrai fonds.

## Convention de la copie (jamais l'original)

- `<dossier>/.pdfa/<fichier>` (PDF) ou `<dossier>/.pdfa/<fichier>.pdf` (image) : dossier en point, ignoré par
  l'indexeur et le reconstructeur (pas de faux document).
- État dans le `.meta.json` : `pdfa: { statut, niveau: "2b", chemin, empreinte_sha256, taille,
  empreinte_origine, le, raison }`. Document **scellé** : `.meta.json` non réécrit (il fait partie du sceau),
  état dans `.pdfa/<fichier>.pdfa.json` ; la copie reste permise et le sceau reste valide (oracle
  `Verificateur`).
- Échec → `statut: echec` + `raison`, aucune copie partielle (temporaire puis renommage), document conservé.

## Contrôle partiel

`ControlePdfA` : XMP `pdfaid:part=2`/`conformance=B`, OutputIntent `/GTS_PDFA1` + `/DestOutputProfile`,
absence de chiffrement, polices incorporées (`/FontFile` dès qu'une `/BaseFont` est déclarée). **Contrôle de
surface** (pas de flux d'objets compressé, pas de règle fine) : **veraPDF reste la validation de recette**.

## Mesures exactes

- PDF texte 10 pages A4 → **0,297 s**, soit **0,030 s/page** (~30 ms) ; copie 8 635 octets.
- Image JPEG 793×1123 (1 page) → **0,302 s** ; copie 37 827 octets.
- Oracle PHPUnit PDF/A (4 fichiers) : **17 tests / 121 assertions** verts
  (`CopieArchivageTest` 11, `PilotePdfATest` 2, `TachePdfATest` 1, `PdfaEcranTest` 3).
- E2E seul : `npx playwright test tests/e2e/pdfa.spec.ts --project=parcours` → **2/2** (15,4 s).
- PHPStan niveau 6 : 0 erreur, baseline inchangée (vide).

## Porte qualité (ECC)

- Analyse statique : `phpstan analyse --memory-limit=2G` niveau 6 **OK, 0 erreur**, `phpstan-baseline.neon`
  vide (jamais grossi) ; `composer audit` : aucune vulnérabilité.
- TDD : oracle rouge commité avant le code (11 tests vus rouges « class not found ») ; aucune assertion
  affaiblie par la suite.
- Revue du diff (php-reviewer, security-reviewer, silent-failure-hunter) :
  - **Silent-failure** : chaque échec de conversion rend une raison nommée (chiffré / corrompu / ICC / format) ;
    les `catch (Throwable)` du chemin fichier sont tracés par `error_log` et ne masquent jamais un document
    (échec de copie ⇒ document ingéré). `lancer()` refuse une seconde tâche concurrente.
  - **Sécurité** : la copie est écrite sous la racine du fonds, jamais hors ; chemin relatif validé par le
    Socle ; la route de téléchargement passe par le droit `document.voir` (même garde que l'original) ;
    `--permit-file-read` est limité au profil ICC ; aucune donnée d'entrée concaténée en SQL ; les nouvelles
    routes POST ont leur charge CSRF défensive dans la sonde offensive (couche 7 verte).
  - **PHP** : `declare(strict_types=1)`, classes `final`, méthodes courtes, types explicites, aucune fonction
    de plus de ~40 lignes.
- Deux constats de revue corrigés : la détection « PDF chiffré » captait à tort « Couldn't initialise file »
  (un PDF corrompu était annoncé chiffré), réduite à « requires a password » ; la définition PDF/A temporaire
  et l'enveloppe d'image sont désormais créées dans des temporaires propres (jamais dans le fonds).

## Preuves du harness `-SansBanc` (commit `8a032c5`, 14:04, 1 169 s)

| Couche | Verdict | Détail |
| --- | --- | --- |
| 0 garde-fous | **VERT** | vendor + node_modules, `DB_NAME=gedv2_test` |
| 1 unitaire | **VERT** | 598 tests, 3 406 assertions, 1 sauté |
| 2 contrat | **VERT** | toutes les routes déclarées couvertes |
| 3 banc | ROUGE | **sautée par `-SansBanc`** (pas de banc dans ce lot) |
| 4 écrans | ROUGE | 79/80 : mon `pdfa.spec.ts` **2/2** ; seul `budget-apercu.spec.ts` échoue (1 048 ms > 1 000 ms) sous charge — la couche rejouée seule sur machine calme est **80/80** (voir `var/harness/4-ecrans-rejeu.txt`) |
| 5 personas | **VERT** | 6 personas, 4/4 et 3/3 |
| 6 charge | **VERT** | p95 lecture 1 102 ms, écriture 384 ms, 0 erreur, 0 collision |
| 7 sécurité | **VERT** | sonde offensive : aucun contournement accepté |
| 8 statique | **VERT** | phpstan 0 erreur hors baseline, `composer audit` propre |

- Le seul échec hors `-SansBanc` est le budget d'affichage de l'aperçu PDF (48 ms au-dessus du seuil), mesuré
  pendant que d'autres lots tournaient sur la même machine : rejouée seule, la couche 4 est 80/80.
- **Environnement** : `tools/composer.phar` (gitignoré) manquait dans ce worktree ; copié depuis le dépôt
  principal pour la couche 8. Le port 8 872 était tenu par un processus étranger (accès refusé) ; la couche 4
  a été rejouée avec `GED_PORT_BUDGETS=8892`.

ETAT: fini

