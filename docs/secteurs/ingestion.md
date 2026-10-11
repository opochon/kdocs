# Secteur INGESTION — dossier, adresse, agent, découpe, lecture

Agent : Claude Code · Juge : GLM · Lot en cours : V2-02

## Mission
Deux entrées, légères : **un dossier d'ingestion** et **une adresse d'ingestion** (boîte IMAP relevée, pièces jointes
vers la même chaîne). ClearMyMails reste le pont lourd (Gmail, Microsoft 365…) — secteur Jonctions.
Chaîne : dépôt → découpe (`cmd4_ingest`, moteur partagé avec CMD v4, dépôt ClearMyDocs) → lecture → secteur Classement →
secteur Socle (rangement). Le dossier d'ingestion n'est jamais montré aux utilisateurs : seulement le bilan.

## Chemins
`src/Ingestion/`, `agent/` (agent d'ingestion Python, appel sortant), `tests/php/Ingestion/`, `tests/corpus/`, `tools/banc/`

Lot ged-pdfa-1 : pour porter la copie d'archivage jusqu'à l'écran et le rattrapage, ce lot touche aussi
`src/Ui/` (données et routes), `templates/ecrans/`, `src/Apercu/Module.php` (téléchargement), `src/Securite/DroitsRoutes.php`
(permissions), `tools/tache.php` et `tests/e2e/` — ajouts minimaux signalés dans le journal du lot.

## Lot V2-02 — porte
1. Lot `courrier-100p` (repris de GEDv1 avec sa vérité) : 53/53 exacts, 0 page perdue, 0 doublon.
2. Chaque nouveau lot du corpus (photos, 90°/180°, versos blancs, QR multipages, DE/IT/EN, pages mélangées, bureautique,
   300 pages, PDF protégé) a sa `verite.json` et passe la même porte.
3. Adresse d'ingestion : un courriel de test avec 3 pièces jointes → 3 documents, 0 perdu, 0 doublon au second relevé.
4. Tout en jobs courts avec point de reprise ; une tâche tuée reprend sans rien perdre.

## Reprendre de GEDv1
`app/Services/Ingest/Cmd4IngestClient.php` (stderr en fichier : l'interblocage du 27-09), `PDFSplitterService::compterPagesNatif`,
`tools/banc/rejouer-ingestion.php` et `mesurer-decoupe.py` (mesure contre vérité, pages blanches neutres),
`tests/corpus-banc/courrier-100p/`, la reprise des jobs abandonnés (`TaskService::processClassificationQueue`).

## Journal V2-02
- Fait : dossier surveillé (fichier stable sur deux relevés, déplacement atomique), travaux en base avec verrous courts
  reprenables et 3 essais, découpe par `cmd4_ingest`, rangement via `Contrats\Depot` + `Contrats\Classeur`.
- Fait : adresse d'ingestion. Client IMAP PHP pur (`Imap\ImapBoite` : l'extension imap n'existe plus en PHP 8.4),
  connexion différée, pièces utiles seulement (ni logos de signature, ni vCard), un message traité une fois
  (`ingestion_messages`), expéditeurs autorisés optionnels, message lu puis rangé dans `GED-traites` / `GED-refuses`
  (MOVE, ou COPY si le serveur n'a pas MOVE) — jamais EXPUNGE ni `\Deleted`.
- Fait : `php tools/tache.php ingestion [--budget=20] [--tours=N]` (verrou fichier, une ligne JSON par tour).
  Une porte en panne devient une alerte, les autres continuent ; sans dépôt (Socle), les pièces prêtes attendent.
- Preuves : `tests/php/Ingestion` 13 tests (dont client IMAP réel contre `tests/outils/faux-imap.php`).
- Reste : porte 1-2 (corpus `courrier-100p` + vérité, mesure 53/53 via `Cmd4Decoupeur`), branchement du dépôt Socle.

## Demandes
- Au Socle : `new Ged\Socle\DepotFichiers(Config $config)` — c'est ainsi que `tools/tache.php` le construit.
- À l'Ingestion (V2-08a) : ajouter `Ged\Jonctions\SourceApi` à `Ged\Ingestion\Fabrique::sources()` — `new SourceApi(Fabrique::zoneTravail($config) . '/api', new Travaux($pdo), $pdo)` — pour que les fichiers déposés par l'API et non encore enregistrés (reprise après panne) entrent dans la chaîne ; aucun autre changement demandé.
- 29-09 : demande Jonctions faite — `Ged\Jonctions\SourceApi` ajoutée à `Fabrique::sources()` (reprise des dépôts API non enregistrés).

## Demandes du HARNESS (lot H-6 « 6-charge », 29-09-2026)

Mesure : `php tools/tache.php ingestion --budget=300 --tours=200` sur le lot `courrier-100p`, pendant une charge
de 10 000 documents (`tools/banc/charge.php`). Preuves : `recette/preuves/charge/charge.{json,md}`.

- **Une invocation unique n'ingère pas un fichier fraîchement déposé** : `SourceDossier::relever()` exige DEUX
  relevés (fichier stable) avant de déplacer/prendre un fichier, alors que `tools/tache.php` s'arrête au premier
  tour sans travail (`recus + decoupes + ranges === 0`). Résultat : lancée une fois juste après un dépôt, la
  tâche sort sans rien faire (c'est ce qui s'est produit au premier rejeu de la couche 6) ; il faut un second
  appel. Le banc amorce donc l'état du dossier (`etat-dossier.json`) pour qu'une seule invocation fasse le
  travail. Attendu : un tour de « chauffe » interne, ou une stabilité constatée dans la même invocation.
- **Le lot parent ne quitte jamais l'état `decoupe`** : une fois les pièces produites et rangées, la ligne du lot
  (`ingestion_travaux.parent_id = 0`) reste à `decoupe` (le pilote n'avance que les pièces). Comme
  `Travaux::bilan()` et `Ged\Ui\ATraiter::enCours()` comptent les états `recu|decoupe|pret`, un lot **fini**
  reste compté « en cours de lecture » indéfiniment. Attendu : passer le lot à `range`/`fini` quand toutes ses
  pièces sont rangées.

Preuve positive du reste de la chaîne : avec l'amorçage, la tâche finit en 137 s, **53/53 pièces rangées**,
0 travail restant, 0 erreur, 53 documents déposés par le Socle — soit exactement le lot attendu.
- 29-09 : demandes du banc de charge H-6 faites — `tache.php` s'arrête après deux tours à vide (le dossier ne prend un fichier qu'au second relevé) ; un lot dont toutes les pièces sont rangées passe en « range » (`Travaux::clore`) et n'est plus compté « en cours de lecture ».

## Journal R1-chaine

- Oracle rouge : `PiloteTest` couvre désormais la fabrique (classeur et `CMD4_VISION_ENABLED`), les champs proposés
  dans le `.meta.json` et l'événement `document_depose` après dépôt.
- Fait : `tools/tache.php ingestion` construit le `ClasseurGed` de production (Infomaniak seulement avec les clés
  actives, sinon `ClientIaNul`), transmet Vision au découpeur, puis synchronise l'index plein texte par lot borné.
- Fait : le Pilote avertit `Workflows\Ecouteur` après chaque dépôt réussi ; une panne Workflow reste isolée par le
  contrat non levant de l'écouteur.
- Fait : F1 compare chaque `.meta.json` aux types et émetteurs de `verite.json`, rejette tout validé faux et offre
  `--avec-ia` lorsque `INFOMANIAK_AI_*` est activé.
- Vert : `php vendor/bin/phpunit tests/php/Ingestion/PiloteTest.php --testdox` — 11 tests, 44 assertions ; F1 sans IA
  — 53/53 pièces, 0 type faux, 0 émetteur faux, 0 validé faux (105,7 s).
- Rejeu : `powershell -File tools/harness.ps1 -SansBanc` — unitaire 284/284, contrats et charge verts ; les deux
  rouges d'écrans restent hors secteur, et les couches banc/personas sont volontairement rouges avec `-SansBanc`.

ETAT: fini

## Journal V2-12a — édition de pages dans À traiter — 02-10-2026

**Oracle d’abord**
- `tests/php/Ingestion/EditeurPdfTest.php` a d’abord échoué (`EditeurPdf` absent), puis prouve une vraie
  rotation dans le PDF, une scission 3/3, une fusion 6 et l’extraction d’une page. Les octets sont donc
  remplacés, pas seulement l’aperçu.
- `tests/php/Ingestion/EditionPiecesTest.php` construit un lot synthétique de six pages et rejoue rotation,
  scission, fusion, corbeille de page, annulation de la dernière opération puis annulations de fusion et
  scission. Après chaque frontière, les pages vivantes sont exactement `1..6`, sans doublon.

**Fait**
- `EditeurPdf` délègue à `agent/editer_pdf.py` (PyPDF/pypdf) et produit les fichiers temporairement puis
  atomiquement. Le PDF du lot de travail n’est jamais ouvert en écriture.
- `EditionPieces` refuse une pièce hors `a_controler`, scellée, non-PDF ou à la corbeille. Il utilise
  `DepotFichiers::remplacerContenu()` : la version antérieure est archivée, la proposition est recalculée
  par le Classeur existant et le statut reste à contrôler.
- Scinder crée une seconde pièce, Fusionner conserve l’ordre courant puis choisi et met la source à la
  corbeille récupérable ; Retirer dépose la page isolée avant de la mettre à la corbeille. Les listes de
  pages d’origine sont conservées dans `origine.pages_liste` et contrôlées avant chaque fin de geste.
- Les instantanés et pile d’annulation vivent sous `.ged/editions-pages/<id>/` ; chaque document reçoit un
  `historique_pages` dans son `.meta.json`. L’annulation replace les octets/métadonnées et met à la corbeille
  la pièce ajoutée par une scission, donc elle ne réintroduit jamais un doublon vivant.
- Dépendance d’exploitation à installer dans **le même environnement Python que `CMD4_PYTHON`** : `pypdf`
  (testé en 6.16.1).

**Preuves de clôture**
- `php vendor/bin/phpunit tests/php/Ingestion/EditeurPdfTest.php tests/php/Ingestion/EditionPiecesTest.php --no-coverage` : 2 tests, 28 assertions, vert.
- Après le commit partagé `b1d98eb` (cinq routes `documents:traiter` et charges CSRF),
  `npx.cmd playwright test tests/e2e/edition-pages.spec.ts --project=parcours --reporter=line` : **2/2**
  (parcours bureau complet et contrôles tactiles téléphone).
- `harness.ps1 -SansBanc` : unitaire, sécurité, contrat et charge verts ; les deux rouges d’écrans restants
  sont des collisions de chemins des oracles mobile et À traiter dans la base partagée, hors V2-12a.

ETAT: fini

### Jugement R1-chaine (juge, 01-10 11:06, copie propre sur 0c8217f)

- Harness complet : 0, 1 (284 tests), 2, 3 (53/53, F1 franchie), 4 (35/35), 6 (p95 lecture 1 142 ms) et 7 verts.
- Recette réelle rejouée (instance `GEDv2-recette`, vrai scan de 103 pages, fonds vierge) : la tâche d'ingestion découpe AVEC la vision, classe (IA Infomaniak), range 48 pièces en 7 min, indexe d'elle-même (48 textes, recherche immédiate) et rattache les droits ; l'aperçu s'ouvre pour un compte réel. Le branchement demandé est fait.
- Ce que la recette révèle, hors du périmètre de ce lot : sur du vrai papier, l'émetteur vient d'une ligne d'en-tête OCR bruitée et la date est souvent fausse, et 19 pièces sur 48 sont validées seules dont une quinzaine fausses. Renvoyé au secteur Classement en lot R2-classement (porte sur un corpus bruité synthétique).
- Verdict : **R1-chaine franchi** (branchement) ; qualité du classement réel suivie en R2.

## Journal R2b — déduplication Vision (01-10-2026)

- `Pilote` dédoublonne les numéros de page rendus par le moteur avant de créer les travaux : une pièce entièrement redondante est ignorée ; les pages inédites d’une sortie partiellement redondante restent présentes. La protection est donc côté GEDv2, même si ClearMyDocs produit le doublon.
- Oracle rouge puis vert : `PiloteTest::testUnePageRendueDeuxFoisParLaVisionNECreeQuUnePiece` simule deux PDF distincts tous deux marqués page 86 et prouve qu’une seule pièce est déposée.
- `tools/banc/mesurer-decoupe.php` reprend `CMD4_VISION_ENABLED` par défaut : la porte 0 page perdue / 0 page double joue aussi en Vision lorsqu’elle est configurée (l’option `--vision` reste explicite).
- Rejeu réel `php tools/banc/mesurer-decoupe.php courrier-100p --vision` : 53/53 découpes exactes, 0 page perdue, 0 page en double, porte franchie.

ETAT: fini

## Journal R2c — recouvrement Vision (01-10-2026)

- Le Pilote choisit désormais le détenteur de chaque page AVANT de créer les travaux : pour un recouvrement, le texte qui contient explicitement le marqueur de page l’emporte ; à égalité, la première sortie reste déterministe.
- Oracle synthétique : A=`[85,86]` sans marqueur 86, B=`[86]` avec « Page 86 » ; le même Pilote que `tools/tache.php` range 85 et 86 une fois chacun.
- À reprendre par Ingestion : `harness.ps1 -SansBanc` du 01-10 expire sa charge à 37/53 pièces après `tools/tache.php ingestion --budget=300 --tours=200` (16 travaux non finis, zéro erreur). Aucun paramètre de reprise ou de budget n’a été modifié par R2c.

ETAT: bloqué — charge du harness incomplète, hors correction de frontière Vision

## Journal R2g — invariant de découpe (02-10-2026)

**Fait**
- Après chaque sortie de découpe, `Pilote` vérifie l'inventaire disjoint des pages contre le total du PDF source (`pdfinfo`, avec repli de test). Une frontière dupliquée est toujours attribuée une seule fois ; une page absente est rattachée déterministement à la pièce qui prolonge la séquence, gauche puis droite.
- La réparation écrit une alerte nommée `invariant de découpe réparé` dans le bilan du tic. Si aucun rattachement sûr n'est possible, la tâche échoue explicitement : elle ne dépose pas un lot incomplet.

**Preuves**
- Oracle synthétique `PiloteTest::testInvariantDeDecoupeRepareUnePageVisionDoubleEtUnePageSauteeEtAvertit` : sortie Vision `[1,2]` + `[2,4]` sur un PDF de 4 pages ; dépôt final `1-3`, `4`, une seule occurrence de chaque page et alerte publiée.
- `php vendor/bin/phpunit tests/php/Ingestion` : vert dans la suite R2g.

ETAT: fini

## Journal V2-12b — moteur PDF configuré — 02-10-2026

**Oracle d'abord**
- `EditeurPdfTest` exécute le script entier avec `CMD4_PYTHON` obtenu par `Config` depuis `.env` ; tout
  import absent du venv configuré rend le diagnostic rouge. Les fabriques de PDF et les vérifications de
  pages de `EditionPiecesTest` utilisent désormais ce même interpréteur, jamais le Python système.
- Un second oracle fait tourner l'installateur avec un Python introuvable et attend le refus nommé ; un
  troisième vérifie qu'un `ModuleNotFoundError` ne devient jamais une trace envoyée à l'utilisateur.

**Fait**
- `agent/editer_pdf.py` remplace `pypdf` par PyMuPDF (`fitz`) : rotation, scission, fusion et retrait
  travaillent toujours sur un nouveau PDF atomique. Le venv de CMD4 configuré contient PyMuPDF 1.28.0 ;
  aucune dépendance n'a été installée dans ce venv externe.
- `EditeurPdf::diagnostic()` vérifie réellement ce moteur par le Python configuré. L'installateur refuse
  avant toute écriture si le moteur manque et nomme « La retouche de page est indisponible : moteur PDF
  absent » ; le diagnostic Application restitue le même état.
- L'erreur métier garde les causes de geste compréhensibles. Une cause technique PDF est journalisée côté
  serveur avec sa référence et remplacée côté écran par le message français sûr, sans stderr ni trace.

**Preuves**
- Ciblé : 17 tests PHP / 75 assertions et le parcours `edition-pages` 2/2 verts avec le venv CMD4.
- `harness.ps1 -SansBanc` : unitaire (421 / 2 389), sécurité et contrats verts. Les rouges Écrans sous
  concurrence se rejouent seuls verts (édition 2/2, maquettes 12/12, mobile 2/2) ; la charge rouge
  (p95 lecture 2 119 ms) est verte seule à 794 ms, écriture 269 ms, 0 erreur, 0 collision.

ETAT: fini

## Journal ged-pdfa-1 — copie d'archivage PDF/A-2b — 11-10-2026

**Oracle d'abord**
- `tests/php/Ingestion/CopieArchivageTest.php` (11 tests, 81 assertions) a d'abord échoué « Class
  Ged\Ingestion\CopieArchivage not found », puis prouve : PDF texte → copie 2b avec XMP, OutputIntent et
  texte conservé ; image JPEG ; PDF scanné sans texte ; PDF corrompu → échec propre ; PDF chiffré → échec
  nommé ; idempotence (deux passes = même résultat, 0 doublon) ; document scellé (copie permise, sceau
  intact) ; format non applicable ; rattrapage reprenable et écart.
- `PilotePdfATest` (2 tests) : branchement réel sur `DepotFichiers` (une pièce rangée reçoit sa copie ;
  une copie en échec laisse le document ingéré) ; `TachePdfATest` (1 test) : `tools/tache.php pdfa`
  idempotent ; `PdfaEcranTest` (3 tests) : fiche (ok / échec) et écran Application (rattrapage terminé).

**Mécanisme natif retenu (Ghostscript 10.02.1)**
- Doc officielle `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 14.7 « Creating a PDF/A document »,
  p. 315-316** (pages physiques 319-320) : `-dPDFA=2`, `-sColorConversionStrategy=RGB`, un *PDF/A
  definition file* (`lib/PDFA_def.ps`) portant l'`ICCProfile` de l'OutputIntent (`iccprofiles/srgb.icc`),
  et `PDFACompatibilityPolicy` (1 = l'opération non conforme est ignorée, la sortie reste conforme).
- Commande : `gs -dPDFA=2 -dBATCH -dNOPAUSE -dNOOUTERSAVE -sColorConversionStrategy=RGB -sDEVICE=pdfwrite
  -dPDFACompatibilityPolicy=1 -sOutputFile=<copie> --permit-file-read=<srgb.icc> -f <PDFA_def.ps modifié>
  <source>`. Le `PDFA_def.ps` livré est relu puis réécrit avec le chemin ABSOLU du profil (dépend du
  répertoire courant sinon), et le titre est adapté. Aucune ligne Ghostscript n'est recopiée dans le dépôt.
  `--permit-file-read=<icc>` est requis par le mode SAFER de Ghostscript 10.

**Convention de la copie (à côté de l'original, jamais modifié)**
- `<dossier>/.pdfa/<fichier>` quand l'original est un PDF, `<dossier>/.pdfa/<fichier>.pdf` sinon
  (`photo.jpg` → `.pdfa/photo.jpg.pdf`). Dossier en point : l'indexeur et le reconstructeur l'ignorent,
  la copie ne devient jamais un faux document. L'original n'est ni modifié ni remplacé (empreinte
  inchangée).
- État dans le `.meta.json` : `pdfa: { statut, niveau: "2b", chemin, empreinte_sha256, taille,
  empreinte_origine, le, raison }`. Pour un document **scellé**, le `.meta.json` fait partie du sceau : il
  n'est PAS réécrit, l'état identique vit dans `.pdfa/<fichier>.pdfa.json` (Garde d'archivage vérifiée par
  oracle). Un échec de conversion donne `statut: echec` + `raison` ; le document reste ingéré.
- Conversion via `Ged\Apercu\Outils::ghostscript()` (jamais un autre chemin). Un échec (chiffré, corrompu,
  ICC/`PDFA_def.ps` absent) est nommé, aucune copie partielle n'est publiée (temporaire puis renommage).
  Les images sont enveloppées en PDF d'une page (JPEG en `DCTDecode`, autres bitmaps via GD).

**Contrôle partiel (veraPDF absent, pas de Java)**
- `ControlePdfA` lit la copie en PHP : XMP `pdfaid:part = 2` et `pdfaid:conformance = B`, OutputIntent
  (`/OutputIntent`, `/GTS_PDFA1`, `/DestOutputProfile`), absence de chiffrement (`/Encrypt`), polices
  incorporées (`/FontFile` dès qu'une `/BaseFont` est déclarée). C'est un contrôle de SURFACE (aucun flux
  d'objets compressé lu, aucune règle fine) : **veraPDF reste la validation de recette**, à rejouer sur les
  copies d'un vrai fonds.

**Écrans (E2E par l'écran)**
- Fiche document : `data-testid="pdfa-etat"` → `pdfa-statut-ok` / `pdfa-statut-attente` /
  `pdfa-statut-echec` (+ `pdfa-raison`) / `pdfa-statut-sans-objet`, et `pdfa-telecharger` →
  `GET /documents/{id}/pdfa` (droit `document.voir`, 404 nommé sans copie).
- Écran Application : `data-testid="pdfa-rattrapage"` (lancer, tranche, progression, terminée, écart),
  routes `POST /application/pdfa-rattrapage[/{id}/tranche]` (droit `application:administrer`).
  Ces lignes UI/permissions sont HORS secteur Ingestion : ajout minimal signalé, commité avec le lot.

**Rattrapage du fonds déjà présent**
- `Ged\Ingestion\RattrapagePdfA` (registre `.ged/pdfa-rattrapage.json`) : périmètre figé au lancement,
  `traite` = point de reprise, `ecart` = documents disparus en cours de route, borné à 100 par tranche,
  idempotent (rejouer ne refait aucune copie juste). Aussi `php tools/tache.php pdfa [--lot=N] [--tours=N]`
  : reprend une tâche en cours, sinon en ouvre une.

**Budget mesuré (poste, Ghostscript 10.02.1)**
- PDF texte 10 pages A4 → copie produite en **0,297 s**, soit **0,030 s/page** (~30 ms), copie 8,6 Ko.
- Image JPEG 793×1123 (1 page) → **0,302 s**, copie 37,8 Ko. Le test borne à 30 s pour 5 pages (garde-fou
  grossier) : la mesure réelle est ~100× sous la borne.

**Preuves**
- `php vendor/bin/phpunit tests/php/Ingestion tests/php/Ui tests/php/Securite` : vert.
- `phpstan analyse` niveau 6 : 0 erreur ; baseline inchangée (vide).
- E2E : `npx playwright test tests/e2e/pdfa.spec.ts --project=parcours` → **2/2** (attente → rattrapage
  par l'écran → PDF/A 2b ok → copie téléchargée et contrôlée ; `.txt` « sans objet »).

