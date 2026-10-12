<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-api-3.
 *
 * Additif, comme le correcteur api-2 : il ne touche QUE les 108 éléments listés dans
 * docs/lots/ged-parite-api-3-elements.txt et laisse intacts les couverts des lots précédents.
 *
 * Chaque `couvert` cite une route GEDv2 réelle ET une preuve PHPUnit rejouée ; chaque `couvert-autrement`
 * cite l'écran/service/route existante et sa preuve (PHPUnit ou parcours Playwright) ; chaque `absent` porte
 * sa raison et sa référence Paperless. Le juge tranche.
 *
 * Usage : php tools/parite/corriger-correspondance-api-3.php
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

/** @return array{type:string, classe:string} */
function service(string $classe): array
{
    return ['type' => 'service', 'classe' => $classe];
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

// ---------------------------------------------------------------------------
// Preuves du lot api-3 (couche 1) et des lots précédents (rejouées au dernier passage).
// ---------------------------------------------------------------------------

$pvClassement = 'Ged\\Tests\\Parite\\ApiTroisRessourcesClassementTest::testLesVerbesDesRegistresDuFonds';
$pvClassementVec = 'Ged\\Tests\\Parite\\ApiTroisRessourcesClassementTest::testRejoueLesVecteursDeClassement';
$pvAdmin = 'Ged\\Tests\\Parite\\ApiTroisAdministrationTest::testLesVerbesDesComptesEtGroupesEtLeRefusDeNonAcces';
$pvAdminVec = 'Ged\\Tests\\Parite\\ApiTroisAdministrationTest::testRejoueLesVecteursDAdministration';
$pvSecurite = 'Ged\\Tests\\Parite\\ApiTroisSecuriteTest::testProfilJetonsPreferencesCorbeilleTachesEtCourrier';
$pvSecuriteVec = 'Ged\\Tests\\Parite\\ApiTroisSecuriteTest::testRejoueLesVecteursDUiSettings';
$pvMasse = 'Ged\\Tests\\Parite\\ApiTroisMasseTest::testRejoueLesVecteursDeTelechargementDeMasse';
$pvRecherche = 'Ged\\Tests\\Parite\\ApiTroisRechercheTest::testRejoueLesVecteursDeTachesEtDeCorbeille';
$pvPartageVec = 'Ged\\Tests\\Parite\\ApiTroisPartageTest::testRejoueLesVecteursDeLiensDePartage';

$pvActions = 'Ged\\Tests\\Parite\\ApiDeuxActionsTest::testLesActionsEmailMasseTelechargementEtDossierRespectentLesDroits';
$pvVues = 'Ged\\Tests\\Parite\\ApiDeuxVuesTest::testLesStatistiquesAutocompletionSelectionEtAsnRespectentLesDroits';
$pvFiltresChamps = 'Ged\\Tests\\Parite\\ApiDeuxFiltresRessourcesTest::testRejoueLesVecteursDesFiltresDeChampsPersonnalises';
$pvPartages = 'Ged\\Tests\\Parite\\ApiDeuxFiltresRessourcesTest::testLesFiltresDesLiensDePartageRespectentLesDroits';
$pvDepot = 'Ged\\Tests\\Jonctions\\DocumentsApiTest::testDeposerUnFichierPasseParLAChaineDIngestionEtEstIdempotent';
$pvRechercheDoc = 'Ged\\Tests\\Jonctions\\DocumentsApiTest::testLaRecherchePleinTexteRespecteLesDroits';
$pvDossiers = 'Ged\\Tests\\Jonctions\\ContratCmmTest::testParcoursCmmDeBoutEnBout';
$pvPartage = 'Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair';
$pvLienExterne = 'Ged\\Tests\\Socle\\V212dTest::testLeJetonExterneEstUnFlashDeSessionConsommeUneSeuleFois';
$pvMasseService = 'Ged\\Tests\\Socle\\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire';
$pvWorkflows = 'Ged\\Tests\\Workflows\\ModuleTest::testLAdminCreeEditeActiveEtMontreLHistorique';
$pwConfig = 'configuration.spec.ts > un administrateur lit la configuration et les chemins de stockage';

// ---------------------------------------------------------------------------
// 1. Couverts par une route NOUVELLE du lot (route + preuve PHPUnit).
// ---------------------------------------------------------------------------

/** @var array<string, array{0:array<string,string>, 1:array<string,string>}> $couverts */
$couverts = [];

foreach ([
    // Ressources de classement (registres du fonds).
    "api:verbe:TagViewSet.create" => ['POST', '/api/v1/etiquettes', $pvClassement],
    "api:verbe:TagViewSet.retrieve" => ['GET', '/api/v1/etiquettes/{id:[0-9]+}', $pvClassement],
    "api:verbe:TagViewSet.update" => ['PUT', '/api/v1/etiquettes/{id:[0-9]+}', $pvClassement],
    "api:verbe:TagViewSet.partial_update" => ['PATCH', '/api/v1/etiquettes/{id:[0-9]+}', $pvClassement],
    "api:verbe:DocumentTypeViewSet.create" => ['POST', '/api/v1/types', $pvClassement],
    "api:verbe:DocumentTypeViewSet.retrieve" => ['GET', '/api/v1/types/{id:[0-9]+}', $pvClassement],
    "api:verbe:DocumentTypeViewSet.update" => ['PUT', '/api/v1/types/{id:[0-9]+}', $pvClassement],
    "api:verbe:DocumentTypeViewSet.partial_update" => ['PATCH', '/api/v1/types/{id:[0-9]+}', $pvClassement],
    "api:verbe:CustomFieldViewSet.list" => ['GET', '/api/v1/champs', $pvFiltresChamps],
    "api:verbe:CustomFieldViewSet.create" => ['POST', '/api/v1/champs', $pvClassementVec],
    "api:verbe:CustomFieldViewSet.retrieve" => ['GET', '/api/v1/champs/{id:[0-9]+}', $pvClassement],
    "api:verbe:CustomFieldViewSet.update" => ['PUT', '/api/v1/champs/{id:[0-9]+}', $pvClassement],
    "api:verbe:CustomFieldViewSet.partial_update" => ['PATCH', '/api/v1/champs/{id:[0-9]+}', $pvClassement],
    "api:verbe:SavedViewViewSet.create" => ['POST', '/api/v1/vues', $pvClassementVec],
    "api:verbe:SavedViewViewSet.retrieve" => ['GET', '/api/v1/vues/{id:[0-9]+}', $pvClassement],
    "api:verbe:SavedViewViewSet.update" => ['PUT', '/api/v1/vues/{id:[0-9]+}', $pvClassement],
    "api:verbe:SavedViewViewSet.partial_update" => ['PATCH', '/api/v1/vues/{id:[0-9]+}', $pvClassement],
    "api:verbe:StoragePathViewSet.list" => ['GET', '/api/v1/dossiers', $pvDossiers],
    // Administration.
    "api:verbe:UserViewSet.list" => ['GET', '/api/v1/comptes', $pvAdminVec],
    "api:verbe:UserViewSet.create" => ['POST', '/api/v1/comptes', $pvAdminVec],
    "api:verbe:UserViewSet.retrieve" => ['GET', '/api/v1/comptes/{id:[0-9]+}', $pvAdmin],
    "api:verbe:UserViewSet.update" => ['PUT', '/api/v1/comptes/{id:[0-9]+}', $pvAdmin],
    "api:verbe:UserViewSet.partial_update" => ['PATCH', '/api/v1/comptes/{id:[0-9]+}', $pvAdmin],
    "api:verbe:GroupViewSet.list" => ['GET', '/api/v1/groupes', $pvAdminVec],
    "api:verbe:GroupViewSet.create" => ['POST', '/api/v1/groupes', $pvAdminVec],
    "api:verbe:GroupViewSet.retrieve" => ['GET', '/api/v1/groupes/{id:[0-9]+}', $pvAdmin],
    "api:verbe:GroupViewSet.update" => ['PUT', '/api/v1/groupes/{id:[0-9]+}', $pvAdmin],
    "api:verbe:GroupViewSet.partial_update" => ['PATCH', '/api/v1/groupes/{id:[0-9]+}', $pvAdmin],
    // Liens de partage.
    "api:verbe:ShareLinkViewSet.list" => ['GET', '/api/v1/partages', $pvPartages],
    "api:verbe:ShareLinkViewSet.create" => ['POST', '/api/v1/partages', $pvPartageVec],
    "api:verbe:ShareLinkViewSet.retrieve" => ['GET', '/api/v1/partages/{id:[0-9]+}', $pvPartageVec],
    "api:verbe:ShareLinkViewSet.destroy" => ['DELETE', '/api/v1/partages/{id:[0-9]+}', $pvPartageVec],
    // Compte et sécurité.
    "api:verbe:ProfileView.get" => ['GET', '/api/v1/profil', $pvSecurite],
    "api:verbe:ProfileView.patch" => ['PATCH', '/api/v1/profil', $pvSecurite],
    "api:verbe:GenerateAuthTokenView.post" => ['POST', '/api/v1/jetons', $pvSecurite],
    "api:verbe:UiSettingsView.get" => ['GET', '/api/v1/preferences', $pvSecuriteVec],
    "api:verbe:UiSettingsView.post" => ['POST', '/api/v1/preferences', $pvSecuriteVec],
    // Corbeille et suivi.
    "api:verbe:TrashView.get" => ['GET', '/api/v1/corbeille', $pvRecherche],
    "api:verbe:TrashView.post" => ['POST', '/api/v1/corbeille', $pvRecherche],
    "api:verbe:TasksViewSet.list" => ['GET', '/api/v1/taches', $pvRecherche],
    "api:verbe:TasksViewSet.retrieve" => ['GET', '/api/v1/taches/{id:[0-9]+}', $pvSecurite],
    "api:verbe:ProcessedMailViewSet.retrieve" => ['GET', '/api/v1/courriers/traites/{id:[0-9]+}', $pvSecurite],
] as $id => [$methode, $cheminRoute, $preuve]) {
    $couverts[$id] = [route($methode, $cheminRoute), p($preuve)];
}

// ---------------------------------------------------------------------------
// 2. Couverts AUTREMENT : la fonction existe déjà (route/service/écran), prouvée.
// ---------------------------------------------------------------------------

/** @var array<string, array{0:array<string,string>, 1:array<string,string>, 2:string}> $autrement */
$autrement = [];

// Opérations en masse et dépôt : routes déjà livrées par le lot api-2 / les lots précédents.
$autrement['api:verbe:BulkDownloadView.post'] = [route('POST', '/api/v1/documents/bulk_download'), p($pvActions),
    'Téléchargement groupé : POST /api/v1/documents/bulk_download rend l’archive ZIP au périmètre des droits.'];
$autrement['api:verbe:BulkEditObjectsView.post'] = [route('POST', '/api/v1/documents/bulk_edit'), p($pvActions),
    'Modification groupée : POST /api/v1/documents/bulk_edit réutilise Socle\\ModificationMasse (contrôle par dossier).'];
$autrement['api:verbe:BulkEditView.post'] = [route('POST', '/api/v1/documents/bulk_edit'), p($pvActions),
    'Modification groupée (verbe générique) : même route GEDv2, mêmes contrôles par dossier.'];
$autrement['api:verbe:PostDocumentView.post'] = [route('POST', '/api/v1/documents'), p($pvDepot),
    'Dépôt d’un document : POST /api/v1/documents passe par la chaîne d’ingestion (idempotent).'];

// Recherche, statistiques, état système.
$autrement['api:verbe:SelectionDataView.post'] = [route('POST', '/api/v1/documents/selection_data'), p($pvVues),
    'Données de sélection : POST /api/v1/documents/selection_data, borné aux documents lisibles.'];
$autrement['api:verbe:SearchAutoCompleteView.get'] = [route('GET', '/api/v1/autocomplete'), p($pvVues),
    'Autocomplétion : GET /api/v1/autocomplete au périmètre des droits.'];
$autrement['api:verbe:StatisticsView.get'] = [route('GET', '/api/v1/statistiques'), p($pvVues),
    'Statistiques : GET /api/v1/statistiques au périmètre des droits.'];
$autrement['api:verbe:GlobalSearchView.get'] = [route('GET', '/api/v1/documents'), p($pvRechercheDoc),
    'Recherche globale : GET /api/v1/documents?q= (index plein texte, au périmètre des droits).'];
$autrement['api:verbe:SystemStatusView.get'] = [route('GET', '/sante'), p($pvSecurite),
    'État système : GET /sante nomme l’application et la base qui répondent (équivalent GEDv2 du statut Django).'];

// Lien externe public.
$autrement['api:verbe:SharedLinkView.get'] = [route('GET', '/partages/{jeton:[A-Za-z0-9_-]+}'), p($pvLienExterne),
    'Lien de partage public : GET /partages/{jeton} consomme le jeton de session, jamais le document hors droits.'];

// Configuration applicative (lecture par l’écran /application ; écriture hors périmètre).
$autrement['api:verbe:ApplicationConfigurationViewSet.list'] = [route('GET', '/application'), pw($pwConfig),
    'Configuration applicative : l’écran /application montre la configuration et les chemins réels (lecture seule).'];
$autrement['api:verbe:ApplicationConfigurationViewSet.retrieve'] = [route('GET', '/application'), pw($pwConfig),
    'Configuration applicative : lecture par l’écran /application.'];

// Workflows : le modèle GEDv2 est un graphe unique (définition + déclencheurs + actions), administré par
// DepotWorkflows et les écrans /workflows/admin ; les trois ViewSets Paperless se projettent dessus.
foreach (['WorkflowViewSet', 'WorkflowTriggerViewSet', 'WorkflowActionViewSet'] as $vue) {
    foreach (['list', 'retrieve', 'create', 'update', 'partial_update'] as $verbe) {
        $autrement['api:verbe:' . $vue . '.' . $verbe] = [service('Ged\\Workflows\\DepotWorkflows'), p($pvWorkflows),
            'Workflows : un graphe unique (définition + déclencheurs + actions) administré par DepotWorkflows et les écrans /workflows/admin.'];
    }
}

// ---------------------------------------------------------------------------
// 3. Absents : le modèle GEDv2 n’existe pas (ou interdit la suppression), raison et référence.
// ---------------------------------------------------------------------------

$referencePaperless = 'src/documents/filters.py, src/documents/models.py, src/paperless/views.py';

/** @var array<string, string> $absents */
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

// Émetteurs : GEDv2 porte un émetteur comme chaîne du document, pas comme entité.
foreach (['create', 'destroy', 'partial_update', 'retrieve', 'update'] as $verbe) {
    $absents['api:verbe:CorrespondentViewSet.' . $verbe] =
        'Émetteur = chaîne portée par le document (documents.emetteur), pas d’entité ; la liste dérivée est déjà exposée (GET /api/v1/emetteurs). '
        . 'Un verbe d’écriture suppose un registre d’émetteurs (registre du fonds) + un écran ; coût : un fichier .ged/emetteurs.json, un service Socle, une section de /metadonnees. Référence ' . $referencePaperless . '.';
}

// Suppression de référentiel : interdite par la règle produit « zéro suppression ».
foreach (['TagViewSet', 'DocumentTypeViewSet', 'CustomFieldViewSet', 'SavedViewViewSet'] as $vue) {
    $absents['api:verbe:' . $vue . '.destroy'] =
        'GEDv2 ne supprime jamais un référentiel (règle 4 : zéro suppression) ; les étiquettes/types/champs/vues se renomment ou se désactivent, ils ne s’effacent pas. '
        . 'Un `destroy` supposerait un drapeau de retrait + une corbeille de référentiel. Référence ' . $referencePaperless . '.';
}
foreach (['UserViewSet', 'GroupViewSet'] as $vue) {
    $absents['api:verbe:' . $vue . '.destroy'] =
        'GEDv2 ne supprime jamais un compte ni un groupe (règle 4) : un compte se retire par révocation de ses jetons, un groupe se vide. '
        . 'Un `destroy` supposerait un état « retiré » tracé (colonnes + migration + écran). Référence ' . $referencePaperless . '.';
}

// Chemins de stockage : pas d'entité (GEDv2 range par dossier).
foreach (['create', 'destroy', 'partial_update', 'retrieve', 'update'] as $verbe) {
    $absents['api:verbe:StoragePathViewSet.' . $verbe] =
        'Chemin de stockage comme entité absent : GEDv2 range par dossier (`documents.chemin_relatif`, `securite_dossiers`) ; la liste est exposée par GET /api/v1/dossiers. '
        . 'Les verbes d’écriture supposent un modèle de modèle de chemin (gabarits) que GEDv2 n’a pas. Référence ' . $referencePaperless . '.';
}

// Partage : modifier l'échéance d'un lien n'est pas porté.
foreach (['update', 'partial_update'] as $verbe) {
    $absents['api:verbe:ShareLinkViewSet.' . $verbe] =
        'L’échéance d’un partage externe n’est pas modifiable en GEDv2 : on révoque puis on recrée (DELETE + POST). '
        . 'Un `update` supposerait une route dédiée et une trace d’audit de changement d’échéance. Référence ' . $referencePaperless . '.';
}

// Favicon, version distante, MFA, social/OAuth : hors modèle GEDv2 (incohérence à trancher par le juge).
$absents['api:verbe:FaviconView.get'] =
    'Pas de favicon configurable côté GEDv2 : l’icône est un asset statique, aucune route API. Le juge d’api-2 a exclu la route apparentée '
    . '`api:route:favicon` (infrastructure-django) ; ce verbe est resté absent — incohérence proposée à l’exclusion. Référence src/paperless/urls.py.';
$absents['api:verbe:RemoteVersionView.get'] =
    'Aucun contrôle de version distante en GEDv2 (pas d’appel réseau sortant de mise à jour). Le juge d’api-2 a exclu `api:route:remoteversion` '
    . '(infrastructure-django) ; ce verbe est resté absent — incohérence proposée à l’exclusion. Référence src/paperless/views.py.';
foreach (['get', 'post', 'delete'] as $verbe) {
    $absents['api:verbe:TOTPView.' . $verbe] =
        'MFA TOTP (django-allauth / django-otp) absent du modèle GEDv2. Le juge d’api-2 a exclu `api:route:totp_view` (infrastructure-django) ; '
        . 'ce verbe est resté absent — incohérence proposée à l’exclusion.';
}
$absents['api:verbe:SocialAccountProvidersView.get'] =
    'Comptes sociaux django-allauth absents (infrastructure Django). La route apparentée est déjà exclue ; verbe proposé à l’exclusion.';
$absents['api:verbe:DisconnectSocialAccountView.post'] =
    'Déconnexion d’un compte social django-allauth absente (infrastructure Django). La route apparentée est déjà exclue ; verbe proposé à l’exclusion.';
$absents['api:verbe:OauthCallbackView.get'] =
    'Rappel OAuth du courrier (django-allauth) absent (infrastructure Django). La route apparentée est déjà exclue ; verbe proposé à l’exclusion.';

$absents['api:verbe:ApplicationConfigurationViewSet.destroy'] =
    'Aucune suppression de clé de configuration par le produit (zéro suppression). Référence ' . $referencePaperless . '.';

// Workflows : suppression d'un graphe non portée.
foreach (['WorkflowViewSet', 'WorkflowTriggerViewSet', 'WorkflowActionViewSet'] as $vue) {
    $absents['api:verbe:' . $vue . '.destroy'] =
        'GEDv2 ne supprime jamais une définition de workflow : elle se désactive (`/workflows/admin/{id}/basculer`). '
        . 'Un `destroy` supposerait une corbeille de workflows. Référence ' . $referencePaperless . '.';
}

// Configuration applicative : lecture seule (Config en .env + écran /application) ; pas de registre global inscriptible.
foreach (['create', 'update', 'partial_update'] as $verbe) {
    $absents['api:verbe:ApplicationConfigurationViewSet.' . $verbe] =
        'La configuration d’application de GEDv2 est lue (Config du .env + écran /application) et n’a pas de registre global inscriptible par API. '
        . 'Écrire par verbe suppose un registre du fonds (clé → valeur) + un écran d’édition + une trace d’audit. Référence ' . $referencePaperless . '.';
}

// ---------------------------------------------------------------------------
// 4. Application, en ne touchant QUE les ids du lot.
// ---------------------------------------------------------------------------

$liste = $racine . '/docs/lots/ged-parite-api-3-elements.txt';
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
printf("OK: %d elements api-3 declares (couverts %d, couvert-autrement %d, absents %d)\n",
    $traites, count($couverts), count($autrement), count($absents));
