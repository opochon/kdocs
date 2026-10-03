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

## Demande reçue de DROITS & SÉCURITÉ (R1, DeepCode, 01-10) — rattachement aux dossiers de droits

Correction minimale signalée dans `src/Socle/DepotFichiers.php` et `src/Socle/Indexeur.php`, exactement
pour le rattachement au dossier (le Socle ne dépend pas du secteur Droits, garde `class_exists`, une base
sans schéma d'ACL ne fait jamais échouer un dépôt ni une indexation) :
```php
(new \Ged\Securite\DroitsDossiers($this->pdo))->assurerDossierPourChemin($cheminRelatif);
```
- appelé par `DepotFichiers::enregistrer()` (dépôt, indexeur d'un fichier posé à la main, reconstruction F12),
  `mettreAJour()` (changement de rangement) et `restaurer()` ;
- appelé par `Indexeur::passer()` sur un document DÉJÀ indexé (branche « intouché ») et après un déplacement,
  pour rattraper les fonds repris d'une version antérieure.

Sans ce rattachement, `GardeCentrale` refuse l'aperçu de tout document rangé à tout compte réel. À
reprendre tel quel au prochain lot Socle. **Installateur** : `--admin` exige désormais une adresse de
courriel valide (l'écran de connexion a un champ `type=email`) ; `tests/php/Socle/InstallateurTest.php` a
suivi (`--admin=admin@gedv2.test`, identifiant attendu `admin@gedv2.test`), modification minimale signalée.



## Journal V2-04 (30-09) — métadonnées, types, champs personnalisés, vues

**Reprise après interruption.** La session du 29-09 (18:21) avait bâti le lot sur trois tables
(`socle_types`, `socle_champs`, `socle_vues`) et relevé le plafond à 24. Le juge (30-09, 10:15) a imposé
deux corrections avant tout jugement — appliquées d'abord, tout le reste suit :
1. **Plafond de tables : il RESTE 20.** `tools/installer.php` et `tests/php/Socle/InstallateurTest.php`
   remis TELS QUE COMMITÉS (git restore) ; les trois tables ont disparu du schéma. La nomenclature
   (types et champs) et les vues vivent désormais dans le FONDS : dossier réservé `.ged/` à la racine
   documentaire, JSON canonique écrit de façon atomique (`Ged\Socle\Registre`), lu avec un cache par
   requête rafraîchi par la signature du fichier (mtime+ctime+taille — le preparer d'oracle et le serveur
   peuvent se suivre d'un processus à l'autre). Cohérent avec « le dossier fait foi » : la configuration
   survit à F12 et suit un déménagement du fonds. Zéro table nette de plus : la base de test tient à 20.
2. **Corpus en lecture seule.** Les restes de tests (`tests/corpus/apercu/documents/essais/`, et le
   `.ged/` que le contrat HTTP y avait fait écrire au premier regard de `/metadonnees`) sont déplacés
   dans `var/restes/` (var/ est ignoré). L'écran Métadonnées est devenu une lecture PURE — un GET
   n'écrit jamais dans le fonds ; la graine des six types se pose par l'action d'administration
   « graine » (POST). Rejoué : le harness complet ne laisse plus rien dans `tests/corpus/`.

**Fait, clause par clause**
1. Nomenclature livrée (Facture, Note de crédit, Reçu, Contrat, Courrier, Document RH), modifiable par
   l'administrateur : ajouter, renommer, activer/désactiver — jamais supprimer. Lecture exposée aux
   autres secteurs : `Nomenclature::types()` / `lister()` (demande écrite dans la fiche Classement ;
   `Regles::TYPES_CONNUS` n'est PAS touché, fichier d'un autre secteur).
2. Champs personnalisés typés (texte, nombre, montant CHF, date, liste, oui/non) rattachés à un type ;
   définition dans `.ged/nomenclature.json`, clé = slug stable du nom. Les VALEURS vivent dans le
   `.meta.json` à côté du fichier (le dossier fait foi) et sont portées par la colonne `meta` de l'index
   (`DepotFichiers::rapporterMeta()`, sans déplacer la pièce) — l'indexeur reprend aussi un `.meta.json`
   édité hors application.
3. Vues enregistrées = filtres typés COMPILÉS (type, émetteur, période, étiquette, champ) dans
   `.ged/vues.json` ; la compilation valide tout à l'enregistrement (opérateur cohérent avec la nature,
   dates réelles, valeurs typées) ; `filtrerDocs()` est pure : JAMAIS un déplacement de fichier.
4. Reconstruction (F12) : hash d'export identique avec des champs posés (ChampsTest).
5. Bibliothèque (`templates/ecrans/bibliotheque.twig`) : les vraies vues (volet, comptes) et les vrais
   documents du fonds (liste par l'index, panneau d'aperçu relu du `.meta.json`, filtres typés dans le
   bandeau, formulaire de champs du document retenu), au système visuel existant ; écran Métadonnées
   neuf (`templates/ecrans/metadonnees.twig`).

**Preuves**
- PHPUnit : Socle 65 tests / 291 assertions verts (RegistreTest 5, NomenclatureTest 8, ChampsTest 9,
  VuesTest 10, EcranMetadonneesTest 2, suites V2-01 intactes) ; suite entière du dépôt : 276 tests,
  1340 assertions verts.
- Oracle de porte rejouable : Playwright `tests/e2e/bibliotheque.spec.ts` (connexion par
  `tests/e2e/connexion.ts`) — le persona crée SANS AIDE le champ « Échéance » (date) sur Contrat, pose
  sa valeur, enregistre « Contrats à échéance < 90 j » ; la vue ne garde que les contrats à échéance
  proche et AUCUN fichier ne bouge (chemin du panneau identique avant/après). 2 passed.
- `harness.ps1 -SansBanc` : 1-unitaire, 4-ecrans (34 passed), 7-securite, 2-contrat, 6-charge VERTS
  (3-banc sautée par -SansBanc, 5-personas pas encore écrite — hors de mon secteur) ;
  `tests/corpus/` reste vierge après le passage.
- Commits : 83b8d26 (registre .ged/), 0df9df0 (nomenclature en fichier), 2c4b53f (champs typés),
  b9f7e81 (vues compilées), c527e47 (écrans + routes), f0fe4b7 (oracle du persona), puis la fiche.

**Décisions retenues**
- Configuration dans le fonds plutôt qu'en base (piste du juge) : `.ged/` est interne (nom en point :
  indexeur, reconstructeur et Fonds l'ignorent, comme `.versions/`), ids attribués sous verrou
  (l'équivalent déterministe de l'auto-incrément), écriture tmp+rename sous flock dédié.
- Un GET n'écrit jamais : la graine des six types est une action d'administration (bouton « Poser les
  six types livrés » sur un fonds vide) ; l'installateur des oracles la pose dans LEUR fonds.
- `typesSansEchec`/`champsSansEchec` (bibliothèque) attrapent aussi RuntimeException : un `.ged/`
  illisible ne rend jamais l'écran muet.
- Couture signalée : `Ui\Ecrans::bibliotheque()` délègue à `Ged\Socle\EcranBibliotheque` par
  `class_exists` (le pattern de /recherche et /workflows ; FastRoute refuse deux routes identiques),
  avec repli maquette si la base manque — 28 lignes dans le fichier d'Interface, le gabarit garde son
  contrat visuel.
- `recette/registre.json` et `recette/preuves/charge/*` modifiés par le harness : non commités
  (gérés par le juge, comme les livraisons précédentes).

**À commiter par le juge (fichiers partagés — je n'y commite pas)**
- `src/Securite/DroitsRoutes.php` — 6 lignes après `'GET /application'` :
  `'GET /metadonnees'`, `'POST /metadonnees/type'`, `'POST /metadonnees/champ'` → `application:administrer` ;
  `'POST /bibliotheque/vue'` → `documents:lire` ; `'POST /documents/champ'` → `documents:traiter`
  (+ 1 ligne de commentaire « Socle V2-04 »).
- `tools/banc/sonde-securite.php` — 5 lignes dans les charges défensives : `POST /metadonnees/type`
  (`action=inconnue`), `POST /metadonnees/champ` (`type=0&nom=`), `POST /bibliotheque/vue` (`nom=`),
  `POST /documents/champ` (`doc=0`) — refusées par le métier sans écrire (+ 1 ligne de commentaire).

ETAT: fini

### Jugement V2-04 (juge, 30-09 16:55, copie propre sur 8416e6a)

- Le juge a commité les lignes du secteur dans `DroitsRoutes.php` et `sonde-securite.php` (8416e6a), conformes à la liste « À commiter par le juge ».
- Plafond tenu sans y toucher : 20 tables. Nomenclature, champs et vues sont des fichiers du fonds (`.ged/`) via `Registre` : verrou exclusif, écriture temporaire puis renommage, cache invalidé par signature. Les valeurs de champs vont dans les `.meta.json` et l'index. `InstallateurTest` et `tools/installer.php` sont inchangés.
- Corpus : rien n'est écrit dans `tests/corpus` au commit jugé.
- Harness : 0, 1 (276 tests), 2, 3 (53/53, F1 franchie), 4 (34/34, dont le parcours persona « Échéance » et la vue « Contrats à échéance < 90 j »), 6 (p95 lecture 922 ms) et 7 verts. Le test F12 (`ChampsTest::testLaReconstructionRendToujoursLeMêmeHashAvecDesChampsPosés`) est vert.
- Demande au Classement déposée dans `classement.md` : lire la nomenclature, avec repli sur `TYPES_CONNUS`.
- Note du juge : la session GLM arrêtée à 10:15 lisait encore (journal actif jusqu'à 10:09) ; ce n'était pas un blocage. Désormais, l'activité d'un agent se juge sur son journal de session.
- Verdict : **V2-04 franchi.**

### Note R2b (Classement, 01-10)

- La liste par défaut de `Nomenclature` est étendue à Décompte, Relevé, Attestation, Police d’assurance, Avis d’impôt et Décision. Elle ne modifie jamais une nomenclature déjà posée dans le fonds : l’administrateur garde ses choix.
- `DepotFichiers` range une pièce sans date sous `Sans_date/` et ne crée pas de sous-dossier depuis un émetteur manifestement bruité lorsque la proposition est automatique ; une validation humaine demeure crédible.

### Note R2c (Classement, 01-10)

- `ReconstructionTest` suit le rangement sans date : son oracle d’absence vise désormais `Sans_date/A_classer/Unique.pdf` (changement de chemin seulement).

## Note V2-11 — versions téléversées — 02-10-2026

- Modification minimale signalée depuis Interface : `DepotFichiers::remplacerContenu()` conserve le même nom et les mêmes métadonnées, refuse le changement de type MIME, passe par `Archivage\Garde::interdireMutation()` puis archive le contenu entrant dans `.versions/` et met à jour empreinte/taille/MIME de l’index. Si le contenu sortant n’était pas encore archivé, il est archivé avant remplacement. Aucun fichier utilisateur n’est supprimé par le produit.
- Oracle : `RangementTest::testNouvelleVersionConserveLaPrecedenteEtIndexeLeNouveauContenu` (v1 + v2 archivées, index et courant = v2).

## Note V2-12c — restauration versionnée et navigation indexée — 02-10-2026

- Modification minimale signalée depuis Interface : `DepotFichiers::restaurerVersion()` reprend une archive `.versions/` comme une nouvelle version via `remplacerContenu()`, donc ne détruit aucune version, respecte le scellement et trace `restauration`.
- Modification minimale signalée depuis Interface : `EcranBibliotheque` utilise uniquement l'index `k_vivant` pour former l'arbre et filtre un dossier/ses descendants par préfixe SQL; aucun fichier du fonds n'est lu au GET.
- Oracle : `V212cTest` prouve v1 → v2 → restauration v1 = v3, et un dossier parent qui compte et filtre ses deux descendants.

## Journal V2-12d — données de collaboration et reconstruction — 03-10-2026

- `document_notes`, `document_liens`, `document_partages`, `document_collaboration_evenements` et `reconstruction_index_jobs` sont installées idempotemment par `Socle\Schema`. Aucune donnée utilisateur n'est supprimée : retrait/révocation sont datés.
- `Collaboration` garde les jetons externes uniquement sous empreinte SHA-256, vérifie expiration/révocation, journalise sans secret, actualise la ligne du même index Recherche après une note, et vérifie les ACL existantes avant lien ou partage interne.
- `ReconstructionIndex` vide logiquement le cache Recherche, puis le reprend par lots au plus 100 (25 depuis l'écran). Une tâche `en_cours` est son point de reprise et empêche un lancement concurrent ; aucun GET de consultation n'est bloqué.
- Oracle : `V212dTest` 5 tests / 34 assertions verts (notes, index, historique, liens, ACL de partage interne, faux/expiré/révoqué/sans fuite externe, reprise d'index).

**Demande au juge Droits/Sécurité** : voir les routes et charges de sonde exactes dans `docs/secteurs/interface.md`, Journal V2-12d. Les mutations de note/lien/partage interne doivent être évaluées avec l'action de dossier `lire` afin de respecter le modèle Utilisateur ; le lien externe demeure réservé au Manager/Administrateur jusqu'à V2-13.

ETAT: fini
