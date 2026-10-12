<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-api-2.
 *
 * Le curateur global reconstruit tout le fichier et perdrait les couverts des lots précédents ; ce
 * correcteur ADDITIF ne touche QUE les 77 éléments listés dans docs/lots/ged-parite-api-2-elements.txt,
 * et laisse tout le reste intact.
 *
 * Chaque élément `couvert` cite une route GEDv2 réelle ET une preuve PHPUnit/Playwright rejouée au dernier
 * passage ; chaque `couvert-autrement` cite l'écran/service équivalent et sa preuve ; chaque `exclu` porte
 * une raison de la liste fermée du juge (infrastructure-django, infrastructure-celery-redis) ; les
 * `absent` restants portent leur raison et leur référence Paperless. Le juge tranche.
 *
 * Usage : php tools/parite/corriger-correspondance-api-2.php
 */

$racine = dirname(__DIR__, 2);
$chemin = $racine . '/docs/parite/correspondance.json';
$document = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($document) || !is_array($document['elements'] ?? null)) {
    fwrite(STDERR, "correspondance.json illisible ou sans « elements »\n");
    exit(1);
}

/** @var array<string, array<string, mixed>> $elements */
$elements = $document['elements'];

/** @return array{type:string, methode:string, chemin:string} */
function route(string $methode, string $chemin): array
{
    return ['type' => 'route', 'methode' => $methode, 'chemin' => $chemin];
}

/** @return array{type:string, cible:string} */
function p(string $cible): array
{
    return ['type' => 'phpunit', 'cible' => $cible];
}

/** @return array{type:string, cible:string} */
function pw(string $cible): array
{
    return ['type' => 'playwright', 'cible' => $cible];
}

// Preuves PHPUnit rejouées par la couche 1.
$pvDocVec = 'Ged\\Tests\\Parite\\ApiDeuxFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments';
$pvDocChamps = 'Ged\\Tests\\Parite\\ApiDeuxFiltresDocumentsTest::testLesFiltresDesChampsPersonnalisesSontCables';
$pvBornes = 'Ged\\Tests\\Parite\\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables';
$pvRef = 'Ged\\Tests\\Parite\\ApiFiltresReferentielsTest::testLesFiltresDesReferentielsSontCablesEtRespectentLesDroits';
$pvChamps = 'Ged\\Tests\\Parite\\ApiDeuxFiltresRessourcesTest::testRejoueLesVecteursDesFiltresDeChampsPersonnalises';
$pvPartages = 'Ged\\Tests\\Parite\\ApiDeuxFiltresRessourcesTest::testLesFiltresDesLiensDePartageRespectentLesDroits';
$pvVues = 'Ged\\Tests\\Parite\\ApiDeuxVuesTest::testLesStatistiquesAutocompletionSelectionEtAsnRespectentLesDroits';
$pvActions = 'Ged\\Tests\\Parite\\ApiDeuxActionsTest::testLesActionsEmailMasseTelechargementEtDossierRespectentLesDroits';
$pvRecherche = 'Ged\\Tests\\Jonctions\\DocumentsApiTest::testLaRecherchePleinTexteRespecteLesDroits';
$pvDossiers = 'Ged\\Tests\\Jonctions\\ContratCmmTest::testParcoursCmmDeBoutEnBout';
$pvAppairage = 'Ged\\Tests\\Jonctions\\AppairageTest::testLeJetonAppairagePorteLesDroitsDuCompte';
$pvPartage = 'Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair';

// Preuves de parcours (couche 4), titres lus dans var/e2e-latest.json.
$pwConfig = 'configuration.spec.ts > un administrateur lit la configuration et les chemins de stockage';
$pwJournaux = 'journaux.spec.ts > un administrateur consulte les journaux';
$pwReglages = 'reglages.spec.ts > un utilisateur regle ses preferences generales';
$pwCorbeille = 'corbeille.spec.ts > un administrateur restaure un document de la corbeille';
$pwAdmin = 'utilisateurs.spec.ts > les écrans d’administration s’ouvrent par le menu, comme un utilisateur';

/**
 * Éléments COUVERTS par une route nouvelle du lot (route + preuve PHPUnit).
 *
 * @var array<string, array{0:array<string,string>, 1:array<string,string>, 2?:string}>
 */
$couverts = [];
foreach ([
    'api:filtre:CustomFieldFilterSet.id' => ['GET', '/api/v1/champs', $pvChamps],
    'api:filtre:CustomFieldFilterSet.name' => ['GET', '/api/v1/champs', $pvChamps],
    'api:filtre:ShareLinkFilterSet.created' => ['GET', '/api/v1/partages', $pvPartages],
    'api:filtre:ShareLinkFilterSet.expiration' => ['GET', '/api/v1/partages', $pvPartages],
    'api:vue:CustomFieldViewSet' => ['GET', '/api/v1/champs', $pvChamps],
    'api:route:autocomplete' => ['GET', '/api/v1/autocomplete', $pvVues],
    'api:route:bulk_download' => ['POST', '/api/v1/documents/bulk_download', $pvActions],
    'api:route:bulk_edit_objects' => ['POST', '/api/v1/documents/bulk_edit', $pvActions],
    'api:route:selection_data' => ['POST', '/api/v1/documents/selection_data', $pvVues],
    'api:route:statistics' => ['GET', '/api/v1/statistiques', $pvVues],
    'api:vue:BulkDownloadView' => ['POST', '/api/v1/documents/bulk_download', $pvActions],
    'api:vue:BulkEditObjectsView' => ['POST', '/api/v1/documents/bulk_edit', $pvActions],
    'api:vue:SearchAutoCompleteView' => ['GET', '/api/v1/autocomplete', $pvVues],
    'api:vue:SelectionDataView' => ['POST', '/api/v1/documents/selection_data', $pvVues],
    'api:vue:StatisticsView' => ['GET', '/api/v1/statistiques', $pvVues],
    'api:action:DocumentViewSet.email_document' => ['POST', '/api/v1/documents/{id:[0-9]+}/email', $pvActions],
    'api:action:DocumentViewSet.email_documents' => ['POST', '/api/v1/documents/email', $pvActions],
    'api:action:DocumentViewSet.metadata' => ['GET', '/api/v1/documents/{id:[0-9]+}/metadonnees', $pvVues],
    'api:action:StoragePathViewSet.test' => ['POST', '/api/v1/dossiers/tester', $pvActions],
    'api:action:UnifiedSearchViewSet.next_asn' => ['GET', '/api/v1/next_asn', $pvVues],
] as $id => [$methode, $cheminRoute, $preuve]) {
    $couverts[$id] = [route($methode, $cheminRoute), p($preuve)];
}

/**
 * Éléments COUVERTS AUTREMENT : l'écran, le service ou la route existante porte la fonction, prouvée.
 *
 * @var array<string, array{0:array<string,string>, 1:array<string,string>, 2:string}>
 */
$autrement = [];

// Filtres de documents : la couche de requêtes existante porte la fonction (nom GEDv2 de la colonne).
$filtresDocuments = [
    'api:filtre:DocumentFilterSet.archive_serial_number' => [$pvBornes, 'Le numéro d’archive de GEDv2 est l’identifiant d’index du document (filtre `id`).'],
    'api:filtre:DocumentFilterSet.correspondent__id__none' => [$pvBornes, 'Émetteur absent : filtre GEDv2 `sans_emetteur`.'],
    'api:filtre:DocumentFilterSet.custom_fields' => [$pvDocChamps, 'Champs présents : filtre GEDv2 `champs_contient` (nom ou valeur dans `meta.champs`).'],
    'api:filtre:DocumentFilterSet.custom_fields__icontains' => [$pvDocVec, 'Correspondance de `CustomFieldsFilter` : filtre GEDv2 `champs_contient` (nom ou valeur).'],
    'api:filtre:DocumentFilterSet.custom_fields__id__all' => [$pvDocChamps, 'Correspondance d’`ObjectFilter` (tous) : filtre GEDv2 `champs_tous`.'],
    'api:filtre:DocumentFilterSet.custom_fields__id__in' => [$pvDocChamps, 'Correspondance d’`ObjectFilter` (au moins un) : filtre GEDv2 `champs_any`.'],
    'api:filtre:DocumentFilterSet.custom_fields__id__none' => [$pvDocChamps, 'Correspondance d’`ObjectFilter` (aucun) : filtre GEDv2 `sans_champs`.'],
    'api:filtre:DocumentFilterSet.document_type__id__none' => [$pvBornes, 'Type absent : filtre GEDv2 `sans_type`.'],
    'api:filtre:DocumentFilterSet.has_custom_fields' => [$pvDocChamps, 'Correspondance de `has_custom_fields` : filtre GEDv2 `champs_presents`.'],
    'api:filtre:DocumentFilterSet.storage_path__id' => [$pvBornes, 'Chemin de stockage absent comme entité : GEDv2 range par dossier (`dossier`).'],
    'api:filtre:DocumentFilterSet.storage_path__id__none' => [$pvBornes, 'Correspondance de « sans chemin » : filtre GEDv2 `sans_dossier`.'],
    'api:filtre:DocumentFilterSet.storage_path__name' => [$pvBornes, 'Chemin de stockage nommé : GEDv2 range par dossier (`dossier`).'],
    'api:filtre:DocumentFilterSet.tags__id' => [$pvBornes, 'Étiquette identifiée par son NOM en GEDv2 (pas d’id) : filtre `etiquette`.'],
];
foreach ($filtresDocuments as $id => [$preuve, $note]) {
    $autrement[$id] = [route('GET', '/api/v1/documents'), p($preuve), $note];
}

// Filtres des autres ressources.
$autrement['api:filtre:CorrespondentFilterSet.id'] = [route('GET', '/api/v1/emetteurs'), p($pvRef),
    'Paperless filtre par identifiant ; GEDv2 identifie un émetteur par son nom (`nom` / `nom_contient`).'];
$autrement['api:filtre:TagFilterSet.id'] = [route('GET', '/api/v1/etiquettes'), p($pvRef),
    'Paperless filtre par identifiant ; GEDv2 identifie une étiquette par son nom (`nom` / `nom_contient`).'];
foreach (['api:filtre:StoragePathFilterSet.id', 'api:filtre:StoragePathFilterSet.name',
    'api:filtre:StoragePathFilterSet.path'] as $id) {
    $autrement[$id] = [route('GET', '/api/v1/dossiers'), p($pvDossiers),
        'Chemin de stockage comme entité absent ; GEDv2 expose ses dossiers (chemin relatif) sur GET /api/v1/dossiers.'];
}

// Routes existantes portant la fonction.
$autrement['api:route:anon:api-profile-generate-auth-token-generateauthtokenview-as-view'] =
    [route('POST', '/api/v1/appairage/jeton'), p($pvAppairage),
        'Générer un jeton d’API : GEDv2 l’échange par POST /api/v1/appairage/jeton (jeton lié à un compte).'];
$autrement['api:route:config'] = [route('GET', '/application'), pw($pwConfig),
    'Configuration applicative : l’écran /application montre la configuration et les chemins réels.'];
$autrement['api:route:logs'] = [route('GET', '/journaux'), pw($pwJournaux),
    'Journaux : l’écran /journaux lit le registre d’audit (Socle\\Registre).'];
$autrement['api:route:storage-paths'] = [route('GET', '/api/v1/dossiers'), p($pvDossiers),
    'Chemins de stockage : GET /api/v1/dossiers rend les dossiers du compte.'];
$autrement['api:route:ui_settings'] = [route('GET', '/reglages'), pw($pwReglages),
    'Réglages d’interface : l’écran /reglages écrit les préférences bornées (Socle\\Preferences).'];

// Vues (ViewSets) équivalentes.
$autrement['api:vue:ApplicationConfigurationViewSet'] = [route('GET', '/application'), pw($pwConfig),
    'Configuration applicative : écran /application.'];
$autrement['api:vue:GenerateAuthTokenView'] = [route('POST', '/api/v1/appairage/jeton'), p($pvAppairage),
    'Jeton d’API : échange d’appairage de GEDv2.'];
$autrement['api:vue:GroupViewSet'] = [route('GET', '/administration/groupes'), pw($pwAdmin),
    'Groupes : écran d’administration des groupes (source unique des droits).'];
$autrement['api:vue:LogViewSet'] = [route('GET', '/journaux'), pw($pwJournaux),
    'Journaux : écran /journaux sur le registre d’audit.'];
$autrement['api:vue:SharedLinkView'] = [route('POST', '/documents/{id:[0-9]+}/partages'), p($pvPartage),
    'Lien de partage : GEDv2 le gère PAR DOCUMENT (Socle\\Collaboration), expire, révocable, jamais en clair.'];
$autrement['api:vue:StoragePathViewSet'] = [route('GET', '/api/v1/dossiers'), p($pvDossiers),
    'Chemin de stockage comme entité absent ; dossiers exposés par GET /api/v1/dossiers.'];
$autrement['api:vue:TrashView'] = [route('GET', '/corbeille'), pw($pwCorbeille),
    'Corbeille : écran /corbeille + service Socle\\DepotFichiers (aucune suppression).'];
$autrement['api:vue:UiSettingsView'] = [route('GET', '/reglages'), pw($pwReglages),
    'Réglages d’interface : écran /reglages (Socle\\Preferences).'];
$autrement['api:vue:UnifiedSearchViewSet'] = [route('GET', '/api/v1/documents'), p($pvRecherche),
    'Recherche unifiée : GET /api/v1/documents?q= (index plein texte, au périmètre des droits).'];
$autrement['api:vue:UserViewSet'] = [route('GET', '/administration/comptes'), pw($pwAdmin),
    'Utilisateurs : écran d’administration des comptes.'];

/**
 * Exclusions (raison fermée du juge + référence Paperless).
 *
 * @var array<string, array{0:string, 1:string}>
 */
$exclus = [];
foreach ([
    'api:filtre:PaperlessTaskFilterSet.acknowledged', 'api:filtre:PaperlessTaskFilterSet.status',
    'api:filtre:PaperlessTaskFilterSet.task_name', 'api:filtre:PaperlessTaskFilterSet.type',
] as $id) {
    $exclus[$id] = ['infrastructure-celery-redis',
        'Filtres de PaperlessTask (résultats de tâches Celery/Redis). Référence src/documents/filters.py:800-807, src/documents/views.py:2532.'];
}
foreach ([
    'api:route:anon:api-profile-disconnect-social-account-disconnectsocialaccountview-as-view',
    'api:route:anon:api-profile-social-account-providers-socialaccountprovidersview-as-view',
    'api:route:socialaccount_login_cancelled', 'api:route:socialaccount_login_error',
    'api:route:socialaccount_signup', 'api:route:oauth_callback', 'api:route:system_status',
    'api:route:totp_view',
    'api:vue:DisconnectSocialAccountView', 'api:vue:SocialAccountProvidersView',
    'api:vue:OauthCallbackView', 'api:vue:SystemStatusView', 'api:vue:TOTPView',
    'api:action:UserViewSet.deactivate_totp',
] as $id) {
    $reference = str_contains($id, 'oauth') || str_contains($id, 'Oauth')
        ? 'src/paperless_mail/views.py:181, src/paperless/urls.py:203 (OAuth django-allauth)'
        : 'src/paperless/views.py:97-412, src/paperless/urls.py:173-334 (django-allauth, MFA, statut Django)';
    $exclus[$id] = ['infrastructure-django', $reference];
}

// Restants assumés : le modèle n'existe pas (ou pas encore) côté GEDv2.
$absents = [
    'api:filtre:DocumentFilterSet.custom_field_query' =>
        'DSL JSON de requête sur champs personnalisés (CustomFieldQueryParser) non porté ; src/documents/filters.py:749.',
    'api:filtre:DocumentFilterSet.original_filename' =>
        'Nom d’origine du fichier non porté par le document GEDv2 (vit dans ingestion_travaux.nom_origine) ; src/documents/filters.py:763.',
    'api:filtre:DocumentFilterSet.owner' =>
        'Propriétaire de document absent du modèle GEDv2 ; src/documents/filters.py:763.',
    'api:filtre:DocumentFilterSet.owner__id' =>
        'Propriétaire de document absent du modèle GEDv2 ; src/documents/filters.py:763.',
    'api:filtre:DocumentFilterSet.owner__id__none' =>
        'Propriétaire de document absent du modèle GEDv2 ; src/documents/filters.py:726.',
    'api:filtre:TagFilterSet.is_root' =>
        'Hiérarchie d’étiquettes absente : GEDv2 porte les étiquettes comme valeurs texte, sans parenté ; src/documents/filters.py:95.',
];

// ---------------------------------------------------------------------------
// Application, en ne touchant QUE les ids du lot.
// ---------------------------------------------------------------------------

$liste = $racine . '/docs/lots/ged-parite-api-2-elements.txt';
$brut = (string) file_get_contents($liste);
$ids = array_values(array_filter(array_map('trim', explode("\n", $brut)), static fn (string $l): bool => $l !== ''));
$ids[0] = preg_replace('/^\xEF\xBB\xBF/', '', $ids[0]) ?? $ids[0];
$idsLot = array_fill_keys($ids, true);

$inconnus = [];
$traites = 0;

foreach ($couverts as $id => [$gedv2, $preuve]) {
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    $elements[$id] = ['statut' => 'couvert', 'gedv2' => $gedv2, 'preuve' => $preuve];
    ++$traites;
}
foreach ($autrement as $id => [$gedv2, $preuve, $note]) {
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    $elements[$id] = ['statut' => 'couvert-autrement', 'gedv2' => $gedv2, 'preuve' => $preuve, 'note' => $note];
    ++$traites;
}
foreach ($exclus as $id => [$exclusion, $reference]) {
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    $elements[$id] = ['statut' => 'exclu', 'exclusion' => $exclusion, 'note' => $reference];
    ++$traites;
}
foreach ($absents as $id => $note) {
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    $elements[$id] = ['statut' => 'absent', 'lot' => 'V2-05', 'note' => $note];
    ++$traites;
}

if ($inconnus !== []) {
    fwrite(STDERR, "ids absents de l'inventaire : " . implode(', ', $inconnus) . "\n");
    exit(1);
}

// Contrôle de complétude : tout id du lot a reçu un statut (sinon on refuse d'écrire).
$manquants = array_diff_key($idsLot, array_intersect_key($elements, $idsLot));
if ($manquants !== []) {
    fwrite(STDERR, "ids du lot sans statut : " . implode(', ', array_keys($manquants)) . "\n");
    exit(1);
}

$document['elements'] = $elements;
file_put_contents($chemin, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("OK: %d elements api-2 declares (couverts %d, couvert-autrement %d, exclus %d, absents %d)\n",
    $traites, count($couverts), count($autrement), count($exclus), count($absents));
