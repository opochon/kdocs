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
$serviceDepot = 'Ged' . $bs . 'DepotFileserver' . $bs;
$testJonctions = 'Ged' . $bs . 'Tests' . $bs . 'Jonctions' . $bs;
$testSocle = 'Ged' . $bs . 'Tests' . $bs . 'Socle' . $bs;
$testDepot = 'Ged' . $bs . 'Tests' . $bs . 'DepotFileserver' . $bs;

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
    'mfiles:rest:get:views-path-items' => [
        service($serviceSocle . 'Vues'),
        p($testSocle . 'VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier'),
        'Vues enregistrées du fonds (dossiers virtuels posés sur le dossier physique).',
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
            'note' => 'Fonction M-Files documentée publiquement ; pas encore d’équivalent GEDv2 mesuré. '
                . 'Reste `absent` et est PROPOSÉ au juge (aucune exclusion posée par l’agent).',
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
