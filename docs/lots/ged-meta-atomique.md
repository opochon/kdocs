# Lot ged-meta-atomique - le `.meta.json` ne se perd plus (audit du 16-10, G1)

Branche `ged/ged-meta-atomique` (worktree `C:\ged\wt\ged-meta-atomique`), partie de `origin/v2` (`bb65cc7`).
Origine : audit technique Codex du 16-10-2026 (`GEDV2.md`, constat G1, priorité haute). Secteur Socle.

## Constat vérifié sur `v2`

Le `.meta.json` fait foi, mais il était écrit par `file_put_contents` direct, sans contrôle du retour, sans
temporaire ni verrou, à quatre endroits : `DepotFichiers::mettreAJour`, `Champs::poserValeurTypée`,
`Ingestion\CopieArchivage::persister`, `Fichiers::copierAvecMeta`. Deux défauts réels prouvés ROUGES :

1. `mettreAJour` fusionnait dans la copie de l'INDEX, puis écrasait le disque : une clé posée sur le disque
   (à la main, ou par un secteur avant que l'index ne la voie) était perdue.
2. `Fichiers::deplacerAvecAnnexes` avalait l'échec du déplacement du `.meta.json` (`@rename`) : le document
   arrivait sans ses métadonnées, en silence.

## Fait

Documentation d'abord : php.net `rename` (remplacement de la cible, notes contradictoires sous Windows : à
tester sur la machine) et `flock` (obligatoire sous Windows : verrou sur un fichier DÉDIÉ, jamais sur le
fichier lu ; `fopen` en `c`, jamais en `w`).

- `Fichiers::ecrireMeta` : temporaire unique du même dossier (`.tmp`, ignoré par l'indexeur), `fflush` +
  `fsync`, renommage qui remplace ; jusqu'à 10 essais rapprochés si un lecteur tient la cible ouverte sous
  Windows ; toute étape ratée lève une erreur nommée et retire le temporaire.
- `Fichiers::lireMeta` : `null` si absent ; illisible, tracé au journal PHP et repli sur l'index.
- `Fichiers::modifierMeta` / `sousVerrouMeta` : lecture fraîche du disque, transformation, écriture atomique,
  SOUS VERROU exclusif du dossier (`.verrou-meta`, caché) ; le document est revérifié après obtention du
  verrou (un concurrent a pu le déplacer : erreur nommée « à refaire », jamais de fiche orpheline).
- `mettreAJour` : fusion dans le `.meta.json` du disque sous verrou ; l'index suit. Si l'UPDATE SQL échoue
  après publication, l'erreur remonte et l'indexeur réaligne (le disque est déjà juste).
- `deplacerAvecAnnexes` : un `.meta.json` qui ne peut pas suivre remet le document en place et lève.
- `Champs`, `CopieArchivage`, `copierAvecMeta` passent par ces gestes. Plus aucun `file_put_contents` de
  `.meta.json` dans `src/`.

## Oracle et contre-épreuve

`tests/php/Socle/MetaAtomiqueTest.php` (8 tests), vu ROUGE avant le code (3 erreurs, 5 échecs, dont les deux
défauts ci-dessus), puis VERT : remplacement complet sans résidu, publication impossible nommée, dossier absent,
transformation qui lève n'écrit rien, document disparu refusé, trois processus concurrents x 40 incréments
(`Support/incrementer-meta.php`), clé posée hors application conservée par `mettreAJour`, déplacement sans
`.meta.json` possible remis en place.

Mutation : verrou neutralisé -> 45 incréments sur 120 (ROUGE) ; verrou rétabli -> 120 (VERT).

## Mesures (16-10, M1, PHP 8.4.0, base `gedv2_test`)

- Oracle du lot : **8 tests, 32 assertions, vert**.
- PHPStan niveau 6, baseline vide : **0 erreur**.
- PHPUnit complet : **963 tests, 17 935 assertions, 14 échecs, 2 ignorés**. Les 14 échecs sont TOUS PDF/A
  (`CopieArchivageTest` 8, `PilotePdfATest`, `RelancePdfATest` 2, `TachePdfATest`, `PdfaEcranTest` 2) et
  ils sont **identiques sans ce lot** (rejoués sur `origin/v2` avec `src/` du lot retiré : 8/12 puis 6/11
  en échec). Ce sont les 14 échecs du harnais du 15-10 cités par l'audit (G2), pas une régression.
- Cause relevée, à traiter dans un lot PDF/A (secteur Ingestion) : la copie sort en `statut: echec`,
  « contrôle partiel en échec : output_intent_srgb ». `ControlePdfA` cherche `/OutputIntent` dans les
  octets bruts du PDF ; Ghostscript 10.03 (`C:\Program Files\gs\gs10.03.0`) écrit par défaut des flux
  d'objets compressés (`/ObjStm` présent, constaté sur un PDF du corpus), où le catalogue et son
  `/OutputIntents` ne sont plus lisibles en clair. Piste à confirmer par la doc Ghostscript (`pdfwrite`,
  `-dWriteObjStms=false -dWriteXRefStm=false`) ou par un contrôle qui décompresse : non tranché ici.
- Harness complet non rejoué : à faire par le juge sur sa copie.

ETAT: fini - à juger (l'auteur ne juge pas son lot).

## Suites de l'audit du 16-10 retenues pour GEDv2 (hors de ce lot)

- **G2 - harnais rouge du 15-10** (`7f5620b`, branche `ged-mfiles-fiche-1`) : 14 échecs PHPUnit, 1 écran,
  sonde POST `/reglages` expirée, p95 lecture 7,6 s (seuil 1,5 s). À rejouer sur arbre figé avant tout
  jugement de cette branche ; latence à instrumenter par sondes avant correction (règle B).
- **G3 - garde de rendu** : `GardeCentrale::renduUnitaire` saute les contrôles en CLI/test sans
  `X-GED-Teste-Garde: 1`. Règle pour tout lot : une route sensible ajoutée a son test de REFUS joué avec cet
  en-tête ; une affirmation de sécurité cite ces tests ou des requêtes HTTP, jamais un test de rendu seul.
- **G4 - modules qui grossissent** : `Jonctions\RessourcesApi` (2 059 lignes), `Jonctions\Catalogue` (1 353),
  `Socle\Relations` (900). Extraction par responsabilité à l'occasion d'un changement utile, en lot final.
- Le lot `ged-mfiles-fiche-1` (`Fiche\Verrou`) écrit aussi le `.meta.json` : à faire passer par
  `Fichiers::modifierMeta` avant sa fusion (demande au juge).
