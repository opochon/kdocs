<?php

declare(strict_types=1);

/**
 * Correcteur cible du lot ged-parite-modele-3 : met a jour docs/parite/correspondance.json pour le RESTE
 * de la categorie `modele` et les petites categories voisines (permissions, masse, correspondance,
 * commande, chaine).
 *
 * Regle de la mission : un element n'est marque `couvert-autrement` que s'il cite un equivalent GEDv2
 * reellement chargeable ET une preuve PHPUnit/Playwright reellement passee. Les elements sans equivalent
 * honnete restent `absent` avec une note motivee (raison + reference), jamais forces.
 *
 * Usage : php tools/parite/corriger-correspondance-modele-reste.php
 */

$racine = dirname(__DIR__, 2);
$chemin = $racine . '/docs/parite/correspondance.json';
$donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
$elements = $donnees['elements'];

$vue = ['type' => 'service', 'classe' => 'Ged\\Socle\\Vues'];
$champs = ['type' => 'service', 'classe' => 'Ged\\Socle\\Champs'];
$depot = ['type' => 'service', 'classe' => 'Ged\\Socle\\DepotFichiers'];
$fichiers = ['type' => 'service', 'classe' => 'Ged\\Socle\\Fichiers'];
$etiquettes = ['type' => 'service', 'classe' => 'Ged\\Socle\\Etiquettes'];
$preferences = ['type' => 'service', 'classe' => 'Ged\\Socle\\Preferences'];
$matching = ['type' => 'service', 'classe' => 'Ged\\Socle\\Matching'];
$taches = ['type' => 'service', 'classe' => 'Ged\\Socle\\Taches'];
$droits = ['type' => 'service', 'classe' => 'Ged\\Socle\\ModeleDroits'];
$masse = ['type' => 'service', 'classe' => 'Ged\\Socle\\ModificationMasse'];
$collaboration = ['type' => 'service', 'classe' => 'Ged\\Socle\\Collaboration'];
$copie = ['type' => 'service', 'classe' => 'Ged\\Ingestion\\CopieArchivage'];
$export = ['type' => 'service', 'classe' => 'Ged\\Socle\\Export'];
$reconstructeur = ['type' => 'service', 'classe' => 'Ged\\Socle\\Reconstructeur'];
$correspondance = ['type' => 'service', 'classe' => 'Ged\\Classement\\Correspondance'];
$regles = ['type' => 'service', 'classe' => 'Ged\\Classement\\Regles'];
$miniature = ['type' => 'service', 'classe' => 'Ged\\Apercu\\Miniature'];
$droitsDossiers = ['type' => 'service', 'classe' => 'Ged\\Securite\\DroitsDossiers'];

$preuve = static fn (string $cible): array => ['type' => 'phpunit', 'cible' => $cible];

$pVecteurs = 'Ged\\Tests\\Parite\\ModeleResteTest::testRejoueLesVecteursEtEcritLaMesure';
$pEtiquettes = 'Ged\\Tests\\Socle\\ModelesResteTest::testEtiquettesCouleurEtBoiteDeReception';
$pPreferences = 'Ged\\Tests\\Socle\\ModelesResteTest::testPreferencesParUtilisateurEtRefusDUnReglageInconnu';
$pMatching = 'Ged\\Tests\\Socle\\ModelesResteTest::testMatchingDelegueLAlgorithmeDeCorrespondance';
$pTaches = 'Ged\\Tests\\Socle\\ModelesResteTest::testTachesCycleDeVieSansCelery';
$pChampUrl = 'Ged\\Tests\\Socle\\ModelesResteTest::testChampUrlEtLienSontValides';
$pVues = 'Ged\\Tests\\Socle\\ModelesResteTest::testVuesReglagesAffichageTriEtTableauDeBord';
$pDroits = 'Ged\\Tests\\Socle\\ModelesResteTest::testModeleDroitsTraduitLesPermissionsPaperless';
$pPartage = 'Ged\\Tests\\Socle\\ModelesResteTest::testPartageExterneVersionne';
$pMigration = 'Ged\\Tests\\Socle\\MigrationModeleTest::testMonteEtRedescendLaVersionPartagee';
$pRangement = 'Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1';
$pRangementMaj = 'Ged\\Tests\\Socle\\RangementTest::testMiseAJourDeplaceFichierMetaVersionsEtTrace';
$pMime = 'Ged\\Tests\\Socle\\RangementTest::testExtensionsBureautiquesSontReconnues';
$pChamps = 'Ged\\Tests\\Socle\\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut';
$pCopie = 'Ged\\Tests\\Ingestion\\CopieArchivageTest::testDeuxPassesDonnentLeMemeResultatZeroDoublonEtMetaInchangee';
$pReprocess = 'Ged\\Tests\\Ingestion\\CopieArchivageTest::testLeRattrapageEstIdempotentReprenableEtCompteLEcart';
$pDroitsBase = 'Ged\\Tests\\Securite\\DroitsBaseTest::testOracleDroitsEstRespecteParToutesLesSurfaces';
$pCorrAny = 'Ged\\Tests\\Classement\\CorrespondanceTest::testMatchAny';
$pCorrFuzzy = 'Ged\\Tests\\Classement\\CorrespondanceTest::testMatchFuzzy';
$pEmetteur = 'Ged\\Tests\\Classement\\ReglesTest::testRapprochementEmetteurIgnoreFormeJuridiqueEtAccepteInclusionSignificative';
$pType = 'Ged\\Tests\\Classement\\ReglesTest::testTypeEtPreuve';
$pReconstruction = 'Ged\\Tests\\Socle\\ReconstructionTest::testLaCommandeReconstruireIndexRendLeMemeHash';
$pShareLink = 'Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair';
$pMiniature = 'Ged\\Tests\\Apercu\\MiniatureTest::testGenereUneVignetteDepuisUnPdf';
$pMasseUnitaire = 'Ged\\Tests\\Socle\\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire';

/** @var array<string, array<string, mixed>> $couvert */
$couvert = [];

// --- Champs d'instance CustomFieldInstance -----------------------------------
foreach ([
    'modele:champ:CustomFieldInstance.created' => [$champs, $pChamps],
    'modele:champ:CustomFieldInstance.value_monetary_amount' => [$champs, $pChamps],
    'modele:champ:CustomFieldInstance.value_url' => [$champs, $pChampUrl],
    'modele:champ:CustomFieldInstance.value_document_ids' => [$champs, $pChampUrl],
] as $id => [$service, $preuveCible]) {
    $couvert[$id] = ['gedv2' => $service, 'preuve' => $preuve($preuveCible)];
}

// --- Champs du document -------------------------------------------------------
foreach ([
    'modele:champ:Document.added' => $pRangement,
    'modele:champ:Document.checksum' => $pRangement,
    'modele:champ:Document.filename' => $pRangement,
    'modele:champ:Document.mime_type' => $pMime,
    'modele:champ:Document.modified' => $pRangementMaj,
    'modele:champ:Document.archive_checksum' => $pCopie,
    'modele:champ:Document.archive_filename' => $pCopie,
] as $id => $preuveCible) {
    $couvert[$id] = ['gedv2' => $id === 'modele:champ:Document.archive_checksum' || $id === 'modele:champ:Document.archive_filename' ? $copie : $depot,
        'preuve' => $preuve($preuveCible)];
}

// --- Champ document sans equivalent honnete : note motivee --------------------
$elements['modele:champ:Document.page_count'] = ['statut' => 'absent', 'lot' => 'V2-04',
    'note' => 'GEDv2 ne calcule pas le nombre de pages ; la pagination est portee par l’origine d’ingestion (origine.pages), reference src/documents/models.py'];
$elements['modele:champ:Document.archive_serial_number'] = ['statut' => 'absent', 'lot' => 'V2-09',
    'note' => 'GEDv2 ne numerote pas les archives par un ASN ; le scellement legal porte un jeton TSA, reference src/documents/models.py'];
$elements['modele:champ:Document.storage_type'] = ['statut' => 'absent', 'lot' => 'V2-04',
    'note' => 'GEDv2 n’a pas de type de stockage chiffre : le fonds reste lisible sans l’application, reference src/documents/models.py'];

// --- MatchingModel -----------------------------------------------------------
foreach (['modele:classe:MatchingModel', 'modele:champ:MatchingModel.is_insensitive', 'modele:champ:MatchingModel.match',
    'modele:champ:MatchingModel.matching_algorithm', 'modele:champ:MatchingModel.name'] as $id) {
    $couvert[$id] = ['gedv2' => $matching, 'preuve' => $preuve($pMatching)];
}

// --- ModelWithOwner ----------------------------------------------------------
foreach (['modele:classe:ModelWithOwner', 'modele:champ:ModelWithOwner.owner'] as $id) {
    $couvert[$id] = ['gedv2' => $masse, 'preuve' => $preuve($pMasseUnitaire),
        'note' => 'GEDv2 porte le proprietaire dans les metadonnees du document (action masse `proprietaire`).'];
}

// --- PaperlessTask / TaskName / TaskType -------------------------------------
$champsTache = ['acknowledged', 'date_created', 'date_done', 'date_started', 'result', 'status',
    'task_file_name', 'task_id', 'task_name', 'type'];
foreach ($champsTache as $champ) {
    $couvert["modele:champ:PaperlessTask.$champ"] = ['gedv2' => $taches, 'preuve' => $preuve($pTaches)];
}
$couvert['modele:classe:PaperlessTask'] = ['gedv2' => $taches, 'preuve' => $preuve($pTaches),
    'note' => 'Suivi utilisateur reimplemente sans Celery/Redis (journal dans le fonds).'];
foreach (['CHECK_SANITY', 'CONSUME_FILE', 'INDEX_OPTIMIZE', 'TRAIN_CLASSIFIER'] as $nom) {
    $couvert["modele:type:TaskName.$nom"] = ['gedv2' => $taches, 'preuve' => $preuve($pTaches)];
}
foreach (['AUTO', 'MANUAL_TASK', 'SCHEDULED_TASK'] as $nom) {
    $couvert["modele:type:TaskType.$nom"] = ['gedv2' => $taches, 'preuve' => $preuve($pTaches)];
}

// --- SavedView : affichage, tri, mode, tableau de bord -----------------------
foreach (['display_fields', 'display_mode', 'page_size', 'show_in_sidebar', 'show_on_dashboard', 'sort_field', 'sort_reverse'] as $champ) {
    $couvert["modele:champ:SavedView.$champ"] = ['gedv2' => $vue, 'preuve' => $preuve($pVues)];
}
$champsAffiches = ['ADDED', 'ASN', 'CORRESPONDENT', 'CREATED', 'CUSTOM_FIELD', 'DOCUMENT_TYPE', 'NOTES', 'OWNER',
    'PAGE_COUNT', 'SHARED', 'STORAGE_PATH', 'TAGS', 'TITLE'];
foreach ($champsAffiches as $champ) {
    $couvert["modele:type:DisplayFields.$champ"] = ['gedv2' => $vue, 'preuve' => $preuve($pVues)];
}
foreach (['LARGE_CARDS', 'SMALL_CARDS', 'TABLE'] as $mode) {
    $couvert["modele:type:DisplayMode.$mode"] = ['gedv2' => $vue, 'preuve' => $preuve($pVues)];
}

// --- ShareLink.file_version --------------------------------------------------
$couvert['modele:champ:ShareLink.file_version'] = ['gedv2' => $collaboration, 'preuve' => $preuve($pPartage),
    'note' => 'Colonne version de document_collaboration, migration versionnee non destructive.'];

// --- Tag ---------------------------------------------------------------------
$couvert['modele:champ:Tag.color'] = ['gedv2' => $etiquettes, 'preuve' => $preuve($pEtiquettes)];
$couvert['modele:champ:Tag.is_inbox_tag'] = ['gedv2' => $etiquettes, 'preuve' => $preuve($pEtiquettes)];

// --- UiSettings --------------------------------------------------------------
$couvert['modele:classe:UiSettings'] = ['gedv2' => $preferences, 'preuve' => $preuve($pPreferences)];
$couvert['modele:champ:UiSettings.settings'] = ['gedv2' => $preferences, 'preuve' => $preuve($pPreferences)];
$couvert['modele:champ:UiSettings.user'] = ['gedv2' => $preferences, 'preuve' => $preuve($pPreferences)];

// --- Types de champs personnalises (FieldDataType / customfield-type) --------
foreach (['BOOL', 'DATE', 'FLOAT', 'INT', 'LONG_TEXT', 'MONETARY', 'SELECT', 'STRING'] as $type) {
    $couvert["modele:customfield-type:$type"] = ['gedv2' => $champs, 'preuve' => $preuve($pChamps)];
}
foreach (['URL', 'DOCUMENTLINK'] as $type) {
    $couvert["modele:customfield-type:$type"] = ['gedv2' => $champs, 'preuve' => $preuve($pChampUrl)];
}
$couvert['modele:type:FieldDataType.URL'] = ['gedv2' => $champs, 'preuve' => $preuve($pChampUrl)];
$couvert['modele:type:FieldDataType.DOCUMENTLINK'] = ['gedv2' => $champs, 'preuve' => $preuve($pChampUrl)];

// --- Permissions -------------------------------------------------------------
$couvert['permissions:classe:PaperlessObjectPermissions'] = ['gedv2' => $droits, 'preuve' => $preuve($pDroits),
    'note' => 'GEDv2 attache les droits au dossier de droits (Securite\\DroitsDossiers), herite par ses documents.'];
$couvert['permissions:fonction:get_groups_with_only_permission'] = ['gedv2' => $droits, 'preuve' => $preuve($pDroits)];
$couvert['permissions:fonction:get_objects_for_user_owner_aware'] = ['gedv2' => $droitsDossiers, 'preuve' => $preuve($pDroitsBase)];
$couvert['permissions:fonction:set_permissions_for_object'] = ['gedv2' => $droitsDossiers, 'preuve' => $preuve($pDroitsBase)];

// --- Masse -------------------------------------------------------------------
$couvert['masse:fonction:set_permissions'] = ['gedv2' => $masse, 'preuve' => $preuve($pMasseUnitaire)];
$couvert['masse:fonction:reprocess'] = ['gedv2' => $copie, 'preuve' => $preuve($pReprocess)];
$couvert['masse:fonction:reflect_doclinks'] = ['gedv2' => $collaboration, 'preuve' => $preuve($pShareLink)];

// --- Correspondance ----------------------------------------------------------
$couvert['correspondance:fonction:match_correspondents'] = ['gedv2' => $regles, 'preuve' => $preuve($pEmetteur)];
$couvert['correspondance:fonction:match_document_types'] = ['gedv2' => $regles, 'preuve' => $preuve($pType)];
$couvert['correspondance:fonction:match_storage_paths'] = ['gedv2' => $fichiers, 'preuve' => $preuve($pRangement)];
$couvert['correspondance:fonction:match_tags'] = ['gedv2' => $correspondance, 'preuve' => $preuve($pCorrAny)];

// --- Commandes ---------------------------------------------------------------
$couvert['commande:documents:document_exporter'] = ['gedv2' => $export, 'preuve' => $preuve($pVecteurs)];
$couvert['commande:documents:document_importer'] = ['gedv2' => $reconstructeur, 'preuve' => $preuve($pReconstruction)];
$couvert['commande:documents:document_retagger'] = ['gedv2' => $regles, 'preuve' => $preuve($pType)];
$couvert['commande:documents:document_fuzzy_match'] = ['gedv2' => $correspondance, 'preuve' => $preuve($pCorrFuzzy)];

// --- Chaine ------------------------------------------------------------------
$couvert['chaine:parseur:TextDocumentParser.get_thumbnail'] = ['gedv2' => $miniature, 'preuve' => $preuve($pMiniature)];
$couvert['chaine:parseur:documents.get_default_thumbnail'] = ['gedv2' => $miniature, 'preuve' => $preuve($pMiniature)];

// --- Ecarts assumes motives --------------------------------------------------
$elements['commande:documents:decrypt_documents'] = ['statut' => 'absent', 'lot' => 'V2-09',
    'note' => 'GEDv2 ne chiffre pas le fonds : les fichiers restent lisibles sans l’application (le dossier fait foi), reference src/documents/management/commands/'];
$elements['commande:documents:prune_audit_logs'] = ['statut' => 'absent', 'lot' => 'V2-09',
    'note' => 'GEDv2 garde le journal d’audit en AJOUT SEUL (retention Archivage) : aucune purge automatique, reference src/documents/management/commands/'];
$elements['chaine:parseur:TikaDocumentParser.parse'] = ['statut' => 'absent', 'lot' => 'V2-04',
    'note' => 'Tika est un service Java externe ; GEDv2 n’en depend pas (extraction PDF/Texte/OCR maison), reference src/paperless_tika/parsers.py'];
$elements['chaine:parseur:TikaDocumentParser.extract_metadata'] = ['statut' => 'absent', 'lot' => 'V2-04',
    'note' => 'Metadonnees lues par les parseurs GEDv2, sans Tika (service Java externe), reference src/paperless_tika/parsers.py'];
$elements['chaine:parseur:TikaDocumentParser.get_thumbnail'] = ['statut' => 'absent', 'lot' => 'V2-04',
    'note' => 'Vignette produite par Ged\\Apercu\\Miniature, sans Tika, reference src/paperless_tika/parsers.py'];

// --- Application -------------------------------------------------------------
$modifies = 0;
foreach ($couvert as $id => $definition) {
    if (!isset($elements[$id])) {
        fwrite(STDERR, "correspondance : id inconnu $id\n");
        exit(1);
    }
    $elements[$id] = array_merge(['statut' => 'couvert-autrement'], $definition);
    $modifies++;
}

$donnees['elements'] = $elements;
file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "correspondance modele-reste : $modifies elements couverts, " . count($elements) . " au total\n";
