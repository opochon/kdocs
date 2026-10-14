<?php

declare(strict_types=1);

/**
 * Générateur de la catégorie `mfiles:*` de docs/parite/correspondance.json (lot ged-mfiles-oracle-1).
 *
 * Il lit l'inventaire M-Files (`docs/parite/inventaire-mfiles.json`, extrait de la documentation
 * PUBLIQUE M-Files) et pose, pour chaque élément :
 *   - `absent` par défaut, avec la référence de documentation publique et le motif « proposé au juge » ;
 *   - `couvert` / `couvert-autrement` SEULEMENT pour les ids listés dans $couverts, avec leur
 *     équivalent GEDv2 réel et une preuve PHPUnit rejouée.
 *
 * Aucune exclusion n'est posée par l'agent : une exclusion est une décision du juge (le motif
 * « pas de sens en GED » reste `absent` et lui est PROPOSÉ). Le script est additif : il ne touche
 * aucun id Paperless existant.
 *
 * Usage : php tools/parite/corriger-correspondance-mfiles.php
 */

$racine = dirname(__DIR__, 2);
$cheminCorrespondance = $racine . '/docs/parite/correspondance.json';
$cheminInventaire = $racine . '/docs/parite/inventaire-mfiles.json';

$document = json_decode((string) file_get_contents($cheminCorrespondance), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($document) || !is_array($document['elements'] ?? null)) {
    fwrite(STDERR, "correspondance.json illisible ou sans « elements »\n");
    exit(1);
}
$inventaire = json_decode((string) file_get_contents($cheminInventaire), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($inventaire) || !is_array($inventaire['elements'] ?? null)) {
    fwrite(STDERR, "inventaire-mfiles.json illisible ou sans « elements »\n");
    exit(1);
}

/** @var array<string, array<string, mixed>> $elements */
$elements = $document['elements'];
$bs = chr(92);

/** @return array{type:string, methode:string, chemin:string} */
function route(string $methode, string $chemin): array
{
    return ['type' => 'route', 'methode' => $methode, 'chemin' => $chemin];
}

/** @return array{type:string, classe:string} */
function service(string $classe): array
{
    return ['type' => 'service', 'classe' => $classe];
}

/** @return array{type:string, fichier:string} */
function ecran(string $fichier): array
{
    return ['type' => 'ecran', 'fichier' => $fichier];
}

/** @return array{type:string, cible:string} */
function p(string $cible): array
{
    return ['type' => 'phpunit', 'cible' => $cible];
}

$serviceSocle = 'Ged' . $bs . 'Socle' . $bs;
$serviceClasses = 'Ged' . $bs . 'ClassesMetadata' . $bs;
$serviceDepot = 'Ged' . $bs . 'DepotFileserver' . $bs;
$servicePermissions = 'Ged' . $bs . 'Permissions' . $bs;
$testJonctions = 'Ged' . $bs . 'Tests' . $bs . 'Jonctions' . $bs;
$testSocle = 'Ged' . $bs . 'Tests' . $bs . 'Socle' . $bs;
$testClasses = 'Ged' . $bs . 'Tests' . $bs . 'ClassesMetadata' . $bs;
$testDepot = 'Ged' . $bs . 'Tests' . $bs . 'DepotFileserver' . $bs;
$testParite = 'Ged' . $bs . 'Tests' . $bs . 'Parite' . $bs;

/**
 * Couvertures revendiquées : id M-Files => [gedv2, preuve(), note, couvert-autrement ?].
 *
 * Chaque entrée est vérifiée par le juge : l'équivalent doit EXISTER et la preuve doit avoir
 * réussi au dernier rejeu, sinon l'élément est reclassé « non-prouvé ».
 *
 * @var array<string, array{0:array<string,mixed>,1:array<string,string>,2:string,3?:bool}>
 */
$couverts = [
    // --- Famille archivage / versions : portée par le secteur DepotFileserver ------------------------
    'mfiles:rest:get:objects-type-objectid-version' => [
        service($serviceDepot . 'Veille'),
        p($testDepot . 'VeilleTest::testUneModificationHorsGedArchiveLaVersionPrecedenteDansUnDossierMasque'),
        'Versions gérées dans le dossier masqué `.<fichier>` à côté du document (docs/ARCHIVAGE-FILESERVER.md), doublées dans un dépôt séparé.',
    ],
    'mfiles:rest:put:objects-type-objectid-version-files-file-content' => [
        service($serviceDepot . 'DepotSepare'),
        p($testDepot . 'DepotSepareTest::testUneVersionEstConserveeDansLeDepotSepareAvecSaCorrespondance'),
        'Le contenu d’une nouvelle version est copié dans le dépôt séparé (garantie), avec la correspondance vers l’emplacement d’origine.',
    ],
    'mfiles:rest:get:objects-type-objectid-deleted' => [
        service($serviceSocle . 'DepotFichiers'),
        p($testSocle . 'CorbeilleTest::testLaCorbeilleDeplaceDrapeauteEtNeffaceRien'),
        'Corbeille du Socle : un document mis à la corbeille reste sur le serveur de fichiers, jamais supprimé.',
    ],
    'mfiles:rest:put:objects-type-objectid-deleted' => [
        service($serviceSocle . 'DepotFichiers'),
        p($testSocle . 'CorbeilleTest::testLaRestaurationRemetLeDocumentOctetPourOctet'),
        'Restauration depuis la corbeille : le document revient octet pour octet.',
    ],
    'mfiles:rest:get:objects-type-objectid-version-files-file-content' => [
        route('GET', '/api/v1/documents/{id:[0-9]+}/fichier'),
        p($testJonctions . 'DocumentsApiTest::testPaginationBorneeEtVersionTelechargeable'),
        'Téléchargement du fichier d’un document par l’API documentaire.',
    ],
    // --- Recherche, vues, métadonnées : déjà couverts par Paperless ---------------------------------
    'mfiles:rest:get:objects' => [
        service($serviceSocle . 'DepotFichiers'),
        p($testSocle . 'IndexeurTest::testUnDossierRenommeHorsApplicationEstSuiviSansPerdreLesVersions'),
        'Objets = documents du fonds, indexés depuis le dossier (le dossier fait foi).',
    ],
    'mfiles:rest:get:structure-properties' => [
        service($serviceSocle . 'Champs'),
        p($testSocle . 'ChampsTest::testUnChampTypéEstRattachéÀUnTypeAvecUneCléSlug'),
        'Définitions de champs (métadonnées dynamiques) dans le fonds.',
    ],
    'mfiles:rest:get:structure-classes' => [
        service($serviceSocle . 'Nomenclature'),
        p($testSocle . 'NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase'),
        'Classes d’objets et listes de valeurs nommées (nomenclature du fonds).',
    ],
    // --- Famille 1 : classes d’objets, métadonnées dynamiques, listes de valeurs nommées -------------
    // (lot ged-mfiles-classes-1 ; services Ged\ClassesMetadata\Carte et Ged\Socle\Listes).
    'mfiles:rest:get:structure-classes-id' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testLaCarteDuneClasseNeMontreQueSesPropresProprietes'),
        'La carte d’une classe : ses propriétés attendues, lues dans le fonds.',
    ],
    'mfiles:rest:get:structure-classes-itemidbyalias-alias' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Résolution d’un alias de classe (clé slug) vers son identifiant.',
    ],
    'mfiles:rest:get:structure-classes-itemidbyguid-guid-item' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Résolution d’un alias stable de classe (la clé slug joue le rôle du GUID), sans identifiant propriétaire.',
        true,
    ],
    'mfiles:rest:post:structure-classes-itemidbyalias' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Résolution d’un lot d’alias de classes (classesParAlias).',
    ],
    'mfiles:rest:get:structure-objecttypes' => [
        service($serviceSocle . 'Nomenclature'),
        p($testSocle . 'NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase'),
        'GEDv2 a un seul niveau type/classe : la nomenclature du fonds joue le rôle des types d’objets.',
        true,
    ],
    'mfiles:rest:get:structure-objecttypes-type' => [
        service($serviceSocle . 'Nomenclature'),
        p($testSocle . 'NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase'),
        'Un type d’objet = une classe du fonds (nomenclature), lue telle quelle.',
        true,
    ],
    'mfiles:rest:get:structure-objecttypes-type-classes' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Les classes d’un type : la carte d’une classe liste ses propriétés attendues.',
        true,
    ],
    'mfiles:rest:get:structure-objecttypes-itemidbyalias-alias' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Résolution d’un alias de type/classe.',
        true,
    ],
    'mfiles:rest:post:structure-objecttypes-itemidbyalias' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Résolution d’un lot d’alias de types/classes.',
        true,
    ],
    'mfiles:rest:get:structure-properties-id' => [
        service($serviceSocle . 'Champs'),
        p($testSocle . 'ChampsTest::testUnChampSeRelitParIdEtParCle'),
        'Définition d’une propriété (champ typé) par son identifiant.',
    ],
    'mfiles:rest:get:structure-properties-itemidbyalias-alias' => [
        service($serviceSocle . 'Champs'),
        p($testSocle . 'ChampsTest::testUnChampSeRelitParIdEtParCle'),
        'Définition d’une propriété par sa clé stable (slug).',
    ],
    'mfiles:rest:post:structure-properties-itemidbyalias' => [
        service($serviceSocle . 'Champs'),
        p($testSocle . 'ChampsTest::testUnChampSeRelitParIdEtParCle'),
        'Résolution d’un lot d’alias de propriétés (parCle).',
    ],
    'mfiles:rest:get:structure-properties-id-valuelist-items' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testValeursDeProprieteViennentDeLaListeNommee'),
        'Les valeurs proposées par une propriété viennent de sa liste nommée.',
    ],
    'mfiles:rest:post:structure-metadatastructure-itemidbyalias' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testResoudreLesAliasDeLaStructure'),
        'Résolution d’un alias de structure : classe, propriété ou liste nommée.',
    ],
    'mfiles:rest:get:valuelists' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testLaGrainePorteLesVocabulairesLivresEtEstIdempotente'),
        'Listes de valeurs nommées du fonds (configuration portable).',
    ],
    'mfiles:rest:get:valuelists-id' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testAjouterRenommerDesactiverSansJamaisSupprimer'),
        'Une liste nommée par son identifiant.',
    ],
    'mfiles:rest:get:valuelists-id-items' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testCompletRendLesValeursPourLAdministration'),
        'Les valeurs d’une liste nommée, valeurs retirées comprises.',
    ],
    'mfiles:rest:get:valuelists-id-items-objectid' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testAjoutRenommageEtRetraitTraceDuneValeur'),
        'Une valeur de liste par son identifiant, retirée ou active.',
    ],
    'mfiles:rest:get:valuelists-id-items-objectid-title' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testAjoutRenommageEtRetraitTraceDuneValeur'),
        'Le libellé d’une valeur de liste nommée.',
    ],
    'mfiles:rest:post:valuelists-id-items' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testAjoutRenommageEtRetraitTraceDuneValeur'),
        'Ajouter une valeur à une liste nommée.',
    ],
    'mfiles:rest:delete:valuelists-id-items-objectid' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testAjoutRenommageEtRetraitTraceDuneValeur'),
        'Retirer une valeur SANS l’effacer : retrait daté et attribué, la valeur reste traçable dans le dossier.',
        true,
    ],
    'mfiles:rest:put:valuelists-id-items-objectid-title' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testAjoutRenommageEtRetraitTraceDuneValeur'),
        'Renommer le libellé d’une valeur de liste nommée.',
    ],
    'mfiles:rest:get:structure-valuelists-itemidbyalias-alias' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testResoudreUnAliasDeListe'),
        'Résolution d’un alias de liste nommée.',
    ],
    'mfiles:rest:post:structure-valuelists-itemidbyalias' => [
        service($serviceSocle . 'Listes'),
        p($testSocle . 'ListesTest::testResoudreUnAliasDeListe'),
        'Résolution d’un alias de liste nommée (parCle).',
    ],
    'mfiles:rest:get:objects-type-objectid-version-properties' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testLaCarteDunDocumentSuitSaClasseEtRelitSesValeurs'),
        'La carte de métadonnées d’un document : ses propriétés et leurs valeurs, lues du .meta.json.',
    ],
    'mfiles:rest:get:objects-type-objectid-version-properties-id' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testLaCarteDunDocumentSuitSaClasseEtRelitSesValeurs'),
        'Une valeur de propriété d’un document.',
    ],
    'mfiles:rest:post:objects-type-objectid-version-properties' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserRelireEtEffacerUneValeurTypeeSansToucherAuFichier'),
        'Poser une propriété typée sur un document : le .meta.json fait foi, le fichier ne bouge pas.',
    ],
    'mfiles:rest:put:objects-type-objectid-version-properties' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserPlusieursEtSurPlusieursDocuments'),
        'Poser plusieurs propriétés sur un document (validation avant écriture).',
    ],
    'mfiles:rest:put:objects-type-objectid-version-properties-id' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserRelireEtEffacerUneValeurTypeeSansToucherAuFichier'),
        'Remplacer une propriété d’un document.',
    ],
    'mfiles:rest:delete:objects-type-objectid-version-properties-id' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserRelireEtEffacerUneValeurTypeeSansToucherAuFichier'),
        'Effacer une valeur de propriété : la valeur quitte le .meta.json, le fichier et son historique restent intacts.',
        true,
    ],
    'mfiles:rest:get:objects-properties' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserPlusieursEtSurPlusieursDocuments'),
        'Lire les propriétés de plusieurs documents (cartes indexées par chemin).',
    ],
    'mfiles:rest:post:objects-properties' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserPlusieursEtSurPlusieursDocuments'),
        'Lire les propriétés de plusieurs objets/documents.',
    ],
    'mfiles:rest:put:objects-setmultipleobjproperties' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testPoserPlusieursEtSurPlusieursDocuments'),
        'Poser les mêmes propriétés sur plusieurs documents (poserSurPlusieurs).',
    ],
    'mfiles:rest:get:objects-type' => [
        service($serviceClasses . 'Carte'),
        p($testClasses . 'CarteTest::testDocumentsDeClasseNeRendQueLesVivantsDeCetteClasse'),
        'Les documents vivants d’une classe (le fonds, pas la base).',
    ],
    'mfiles:rest:post:objects-type' => [
        service($serviceSocle . 'DepotFichiers'),
        p($testSocle . 'RangementTest::testDepotRangeFichierMetaEtVersion1'),
        'Créer un objet d’un type = déposer un document portant cette classe (le .meta.json porte le type).',
        true,
    ],
    // --- Famille 2 : relations entre objets, composition parent-enfant, références croisées -------------
    // (lot ged-mfiles-relations-1 ; service Ged\Socle\Relations, définitions portables dans
    // `.ged/relations.json`, valeurs par document dans le `.meta.json`).
    'mfiles:rest:get:objects-type-objectid-version-relationships' => [
        service($serviceSocle . 'Relations'),
        p($testSocle . 'RelationsTest::testUnLienTypeEstNavigableDansLesDeuxSens'),
        'Les relations typées d’un document (Référence, Dépendance, Affectation, Sous-objet), navigables dans les deux sens : la fiche montre ses liens sortants ET entrants.',
    ],
    'mfiles:rest:get:objects-type-objectid-version-relationships-count' => [
        service($serviceSocle . 'Relations'),
        p($testSocle . 'RelationsTest::testCompterLesRelationsDesDeuxSens'),
        'Comptage des relations d’un document, les deux sens compris.',
    ],
    'mfiles:rest:get:objects-type-objectid-version-subobjects' => [
        service($serviceSocle . 'Relations'),
        p($testSocle . 'RelationsTest::testCompositionParentEnfantEtDetachement'),
        'Composition parent-enfant : un dossier contient des pièces (liens Sous-objet), une pièce appartient à un dossier ; le contenant physique se lit du chemin.',
    ],
    'mfiles:rest:get:objects-type-objectid-version-subobjects-count' => [
        service($serviceSocle . 'Relations'),
        p($testSocle . 'RelationsTest::testCompositionParentEnfantEtDetachement'),
        'Comptage des sous-objets (relations de composition actives d’un document).',
    ],
    'mfiles:rest:put:objects-demotemultiobjects' => [
        service($serviceSocle . 'Relations'),
        p($testParite . 'MfilesRelationsVecteursTest::testRejoueLesVecteursDeLaCompositionEtDeLIntegrite'),
        'Détacher un sous-objet (M-Files « demoting objects ») : le lien de composition est retiré, l’enfant devient indépendant, le geste est tracé.',
        true,
    ],
    // --- Famille 3 : vues dynamiques (vues par classe/metadonnees, vues enregistrees, vues recherchees) --
    // (lot ged-mfiles-vues-1 ; service Ged\Socle\Vues, definitions portables dans `.ged/vues.json`).
    'mfiles:rest:get:views-path-items' => [
        service($serviceSocle . 'Vues'),
        p($testParite . 'MfilesVuesVecteursTest::testRejoueLesVecteursDesFiltresEtDuContenu'),
        'Contenu d’une vue : les documents retenus par sa classe et ses valeurs de métadonnées (filtres compilés dans le fonds, aucun fichier déplacé).',
    ],
    'mfiles:rest:get:views-path-items-count' => [
        service($serviceSocle . 'Vues'),
        p($testSocle . 'VuesTest::testLeContenuEtLeCompteDuneVueEnregistree'),
        'Comptage du contenu d’une vue par classe et métadonnées, même sémantique que la liste.',
    ],
    'mfiles:rest:get:views-path-objects' => [
        service($serviceSocle . 'Vues'),
        p($testParite . 'MfilesVuesVecteursTest::testRejoueLesVecteursDesVuesRecherchees'),
        'Contenu d’une vue de recherche : les critères (texte + classe + métadonnées) sont conservés dans le fonds puis rejoués par le moteur de Recherche, jamais un second moteur.',
    ],
    // --- Famille 4 : permissions dynamiques (droits par classe / metadonnee / etat, visibilite conditionnelle) --
    // (lot ged-mfiles-permissions-1 ; service Ged\Permissions\Moteur, règles nommées portables dans
    // `.ged/permissions.json`). Aucune exclusion posée : le reste des éléments de structure de workflows
    // (alias, state unique) reste `absent` et est PROPOSÉ au juge.
    'mfiles:rest:get:objects-type-objectid-version-workflowstate' => [
        service($servicePermissions . 'Moteur'),
        p($testParite . 'MfilesPermissionsVecteursTest::testRejoueLesVecteursDesEtats'),
        'L’état effectif d’un objet (premier circuit actif) : l’axe « droits selon l’état » des permissions dynamiques.',
    ],
    'mfiles:rest:put:objects-type-objectid-version-workflowstate' => [
        service($servicePermissions . 'Moteur'),
        p($testParite . 'MfilesPermissionsVecteursTest::testRejoueLesVecteursDesEtats'),
        'Changer l’état d’un document (transition) : soumis au droit de la transition (moteur Ged\\Workflows) et tracé.',
    ],
    'mfiles:rest:get:structure-workflows' => [
        service($servicePermissions . 'Moteur'),
        p($testParite . 'MfilesPermissionsVecteursTest::testRejoueLesVecteursDesEtats'),
        'Le catalogue des circuits à états (workflows M-Files) que les règles de permission nommées peuvent viser.',
        true,
    ],
    'mfiles:rest:get:structure-workflows-id-states' => [
        service($servicePermissions . 'Moteur'),
        p($testParite . 'MfilesPermissionsVecteursTest::testRejoueLesVecteursDesEtats'),
        'Les états nommés d’un circuit : une règle de permission peut viser un état (ex. a-valider).',
        true,
    ],
    'mfiles:rest:get:structure-workflows-id-statetransitions' => [
        service($servicePermissions . 'Moteur'),
        p($testParite . 'MfilesPermissionsVecteursTest::testRejoueLesVecteursDesEtats'),
        'Les transitions nommées d’un circuit, chacune avec sa permission : le droit est vérifié avant le changement d’état.',
        true,
    ],
];

$poses = 0;
$couvertsPoses = 0;
$manquants = [];
$idsInventaire = [];
foreach ($inventaire['elements'] as $element) {
    $id = (string) ($element['id'] ?? '');
    $idsInventaire[$id] = (string) ($element['reference'] ?? '');
}

foreach ($idsInventaire as $id => $reference) {
    if (isset($couverts[$id])) {
        [$gedv2, $preuve, $note, $autrement] = $couverts[$id] + [3 => false];
        $elements[$id] = [
            'statut' => $autrement ? 'couvert-autrement' : 'couvert',
            'gedv2' => $gedv2,
            'preuve' => $preuve,
            'note' => $note,
        ];
        ++$couvertsPoses;
    } else {
        $elements[$id] = [
            'statut' => 'absent',
            'doc' => $reference,
            'note' => 'Pas encore d’équivalent GEDv2 mesuré ; proposé au juge (aucune exclusion posée par l’agent).',
            'lot' => 'ged-mfiles-2..7',
        ];
    }
    ++$poses;
}
foreach (array_keys($couverts) as $idCouvert) {
    if (!isset($idsInventaire[$idCouvert])) {
        $manquants[] = $idCouvert;
    }
}
if ($manquants !== []) {
    fwrite(STDERR, 'couverts M-Files absents de l’inventaire : ' . implode(', ', $manquants) . "\n");
    exit(1);
}

// Ordonne : les ids Paperless existants gardent leur ordre, puis les `mfiles:*` triés.
$ordonne = [];
foreach ($elements as $id => $valeur) {
    if (!str_starts_with((string) $id, 'mfiles:')) {
        $ordonne[$id] = $valeur;
    }
}
$mfiles = [];
foreach ($elements as $id => $valeur) {
    if (str_starts_with((string) $id, 'mfiles:')) {
        $mfiles[(string) $id] = $valeur;
    }
}
ksort($mfiles);
$document['elements'] = $ordonne + $mfiles;

file_put_contents(
    $cheminCorrespondance,
    json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);
printf("OK: %d elements mfiles poses (couverts %d, absents %d)\n", $poses, $couvertsPoses, $poses - $couvertsPoses);
