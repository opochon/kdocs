# Secteur SOCLE — dossier, métadonnées, versions, index, installateur

Agent : GLM · Juge : Codex · Lot en cours : V2-01

## Mission
Le dossier fait foi. Chaque document = un fichier + `<fichier>.meta.json` à côté ; versions dans `.versions/` à côté du
fichier ; la base MariaDB est un index que l'on peut **supprimer puis reconstruire à l'identique** depuis le dossier.

## Chemins (seuls modifiables par ce secteur)
`src/Socle/` (dont `src/Socle/Module.php` si le secteur expose des routes ; `src/Kernel.php` l'appelle, ne le modifie jamais),
`database/`, `tools/installer.php`, `tools/reconstruire-index.php`, `tests/php/Socle/`, `tests/oracles/socle/`.
Tu IMPLÉMENTES le contrat `src/Contrats/Depot.php` (classe `Ged\Socle\DepotFichiers`) ; tu ne modifies pas l'interface.

## Lot V2-01 — porte (rejouée par le juge)
1. Installateur : `php tools/installer.php gedv2_test --admin=admin` pose un schéma neuf (≤ 20 tables au départ), refuse
   une base peuplée sans `--remplacer` (et fait alors un dump d'abord).
2. `Socle\Depot::deposer(fichier, métadonnées)` range le fichier, écrit le `.meta.json`, indexe.
3. Modifier le fichier hors application → au passage suivant de l'indexeur, v2 archivée dans `.versions/`, v1 intacte.
4. Déplacer/renommer un dossier hors application → l'index suit (la base suit le dossier, jamais l'inverse).
5. `php tools/reconstruire-index.php` : base vidée puis reconstruite depuis le dossier = même contenu (hash de l'export).
6. Aucun `DELETE` sur une donnée utilisateur : corbeille = dossier `.corbeille/` + drapeau, restauration testée.

## Reprendre de GEDv1 (audit 29-09, `GEDv1/var/audit-v2/deepcode-stockage.md`)
- `app/Services/FilesystemIndexer.php` : indexation, dédoublonnage par hash, `.versions/` (fonctionne, prouvé) — porter,
  en corrigeant la liste d'extensions (xls, xlsx, odt, rtf manquaient).
- `app/Services/StoragePathGenerator.php` + `ConsumeFolderService::createAndMoveToPath()` : rangement année/type/émetteur,
  assainissement des noms Windows.
- `app/Services/Storage/StorageRootResolver.php` : racines multiples par préfixe le plus long.
- `app/Services/DocumentMovementService.php` + `tests/integration/test_tracabilite_deplacement.php` : trace des déplacements.
- `tools/schema/*` : l'idée (schéma de référence + vérification colonne par colonne), pas les 126 tables.
- NE PAS reprendre : le second moteur d'indexation, l'API de versions concurrente (`nom_vN.ext` à la racine), les deux
  corbeilles, `restoreSnapshot()` (mort).

## Demandes aux autres secteurs
- (vue d'ensemble, 29-09) L'installateur appelle `Ged\<Secteur>\Schema::installer(\PDO)` pour chaque secteur dont la classe
  existe (Securite, Socle, Ingestion, Classement, Apercu, Recherche, Workflows, Jonctions), après ton propre schéma.
  Connexion : `Ged\Base::pdo($config)` et `Ged\Base::assurerBase($config)` (déjà écrits).
- (socle, 29-09) La reconstruction vide puis refait les tables `documents`, `document_versions`,
  `document_deplacements`. Toute table qui référence `documents.id` (ex. `ingestion_travaux.document_id`) casse au
  passage : le secteur Demandes/Jonctions devra re-liar par `chemin_relatif` ou `empreinte`, jamais par l'id.
- (socle, 29-09) Pour lire le fonds : passer par `Ged\Contrats\Depot` (`Ged\Socle\DepotFichiers`), jamais par SQL direct —
  l'index peut être reconstruit à tout moment, seul le dossier fait foi.

## Journal V2-01 (29-09)

**Fait, clause par clause**
1. `tools/installer.php` : pose le schéma du socle puis celui de chaque secteur présent (ordre du Kernel), refuse une base
   peuplée sans `--remplacer`, fait alors un dump SQL complet en PHP pur sous `var/sauvegardes/` AVANT de remplacer,
   refuse toute base autre que `gedv2`/`gedv2_test`, pose le compte admin (mot de passe haché, affiché une fois).
   Garde ≤ 20 tables au départ (8 aujourd'hui).
2. `DepotFichiers::deposer()` : copie la pièce, la range en `année/type[/émetteur]/Nom.pdf` (assainissement Windows repris
   de GEDv1, accents conservés, noms réservés suffixés), écrit `<fichier>.meta.json` (JSON canonique), archive la v1 dans
   `.versions/<fichier>/v001_<hash8>.<ext>`, indexe la ligne. Le nom de fichier est FIXÉ au dépôt ; `mettreAJour()` ne
   déplace que le rangement et trace le déplacement (cause `mise_a_jour`).
3. `Indexeur::passer()` : deux relevés de stabilité `taille:mtime` dans `.index/etat.json` (un fichier en cours d'écriture
   n'est jamais lu à moitié) ; contenu changé → l'ancienne version part en `.versions/` avant mise à jour de l'index,
   v1 intacte. Passe bornée par budget (`passer(int $budgetS = 20)`), marquage `absent_depuis` seulement sur passe complète.
4. Renommage/déplacement hors application : même `empreinte` à un nouveau chemin + ancien chemin disparu → la ligne suit
   (même id), `.versions/` et `.meta.json` suivent, le déplacement est tracé dans `document_deplacements`
   (cause `indexeur`). La base suit le dossier, jamais l'inverse.
5. `tools/reconstruire-index.php` + `Socle\Reconstructeur` : TRUNCATE des trois tables puis reconstruction depuis le dossier
   (fonds + `.corbeille/`). Preuve d'égalité par le hash sha256 d'un export canonique (`Socle\Export`) — sans ids ni
   horodatages, sinon l'égalité serait fausse par construction. Refuse de partir si un document vivant manque au dossier.
6. Zéro suppression : aucun `DELETE FROM` dans `src/` (garde statique dans `CorbeilleTest`), `TRUNCATE` uniquement chez
   le Reconstructeur. Corbeille = déplacement sous `.corbeille/<chemin>` (arborescence conservée) + drapeaux
   `supprime_le`/`supprime_par` ; `restaurer()` remet le fichier octet pour octet et lève le drapeau.

**Preuves**
- PHPUnit : 28 tests / 159 assertions verts (`tests/php/Socle/` : RangementTest 8, IndexeurTest 7, CorbeilleTest 5,
  ReconstructionTest 4, InstallateurTest 4).
- Porte rejouable sans PHPUnit : `php tests/oracles/socle/porte-v2-01.php` → les six clauses OK, code de sortie 0,
  base de test remise en installation fraîche à la fin.
- Commits : b1c3d86 (rangement + .meta.json + v1), fa68d97 (indexeur : versions et suivi du dossier), d690470 (corbeille,
  zéro suppression), f9063c6 (reconstruction, porte F12), 96d73e4 (les tests restituent STORAGE_ROOT), 26582be
  (installateur), 92507cb (oracle de porte).

**Décisions retenues (divergences assumées d'avec GEDv1)**
- Pas de dédoublonnage de contenu dans l'indexeur : le dossier fait foi, deux fichiers identiques à deux endroits sont
  deux documents. L'Ingestion dédoublonne déjà en amont (`uq_empreinte_parent`).
- Journal des déplacements non reconstruisable : `document_deplacements` est une trace d'histoire, pas une donnée du
  fonds ; une reconstruction le vide. Si le secteur Demandes veut une trace durable, elle devra vivre dans le dossier.
- MIME : carte d'extensions AVANT `finfo` (déterministe, et corrige les xls/xlsx/odt/rtf manquants de GEDv1).

**Reste hors du lot (signalé, non bloquant)**
- Le verrou des passages concurrents de l'indexeur (deux processus en même temps sur le même fonds) : le harness est
  séquentiel, le sujet est pour le lot exploitation.
- Les tests Selenium/E2E dynamiques (ids de documents instables) relèvent du secteur Interface.

ETAT: fini

## Jugement V2-01 (Claude Code, 29-09) — porte franchie
Oracle `tests/oracles/socle/porte-v2-01.php` rejoué : 6 clauses OK ; banc F1 de bout en bout (53/53 dans le fonds). Réserve : la trace des déplacements est perdue à la reconstruction — à écrire aussi dans le dossier (journal NDJSON) quand le journal d'audit V2-07b sera là.
ETAT: fini (jugé)

## Demande reçue de WORKFLOWS (V2-06, DeepCode, 29-09) — point d'accroche « document déposé / mis à jour »
Le secteur Workflows expose l'écouteur ci-dessous, à appeler **après** le rangement et l'indexation,
depuis `DepotFichiers::deposer()` (événement `document_depose`) et `mettreAJour()` (`document_modifie`) :
```php
if (class_exists(\Ged\Workflows\Ecouteur::class)) {
    \Ged\Workflows\Ecouteur::prevenir($config, 'document_depose', $id, $meta);
}
```
`Ecouteur::prevenir(Config, string $evenement, int $idDocument, array $meta, string $auteur = 'systeme')`
ne lève **jamais** : une règle en erreur est signalée et journalisée, le document continue — l'ingestion
ne doit donc pas être enveloppée dans un `try/catch` de ce côté.
Second point, constaté en V2-06 : le `.meta.json` écrit au dépôt ne contient pas `id` (la ligne d'index
n'existe qu'après l'`INSERT`). `Apercu\Source` et `Workflows\Fonds` le résolvent par `chemin_relatif`
(lecture seule) en attendant ; réécrire l'`id` dans le `.meta.json` après l'indexation rendrait le
dossier auto-descriptible. Détail complet dans `docs/secteurs/workflows.md`, rubrique « Demandes ».

## Demande reçue de ARCHIVAGE (V2-09, DeepCode, 29-09) — garde d'écriture branchée au niveau du service
Le lot Archivage ajoute `Ged\Archivage\Garde` et l'a branchée par appels d'une ligne **clairement signalés** :
```php
if (class_exists(\Ged\Archivage\Garde::class)) {
    \Ged\Archivage\Garde::interdireMutation($this->cheminAbsolu($relatif), 'mise à jour', $idDocument);   // ou 'corbeille'
}
```
- `DepotFichiers::mettreAJour()` et `miseALaCorbeille()` : refus 403 nommé si le document est scellé (le
  point d'entrée unique du service couvre donc écran, API et actions de workflow).
- `Indexeur::passer()` : si le contenu d'un document scellé change sur disque, `Garde::constaterAlteration()`
  est appelée et le passage renvoie `scelles_alteres` ; aucune version n'est archivée silencieusement.
Le scellement vit dans le dossier (`.scellement/<fichier>.json` + `.tsr`), jamais en base : rien à changer
au schéma ni à la reconstruction. Merci de confirmer ces appels au prochain lot Socle (garde `class_exists`,
le Socle ne dépend pas du tout du secteur Archivage).

