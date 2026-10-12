<?php

declare(strict_types=1);

/**
 * Curateur de docs/parite/correspondance.json (lot ged-oracle-paperless-1).
 *
 * Chaque entree « couvert » ou « couvert-autrement » de la table ci-dessous a ete lue
 * dans le code et les tests de GEDv2 : elle cite une route, un ecran ou un service reel,
 * ET une preuve PHPUnit existante. Le juge (src/Parite/Juge.php) verifie ces deux points
 * au dernier rejeu ; toute entree fausse est reclassee « non-prouve ». Tout element non
 * cite ici est « absent » (statut le plus bas), avec le lot a creer suggere.
 *
 * Ce fichier n'est PAS une preuve : il construit la declaration. Le juge tranche.
 *
 * Usage : php tools/parite/corriger-correspondance.php
 */

$racine = dirname(__DIR__, 2);
$inventaire = json_decode((string) file_get_contents($racine . '/docs/parite/inventaire-paperless.json'), true, 512, JSON_THROW_ON_ERROR);

// ---------------------------------------------------------------------------
// Aides de declaration
// ---------------------------------------------------------------------------

/** @return array{type:string, methode:string, chemin:string} */
function route(string $methode, string $chemin): array
{
    return ['type' => 'route', 'methode' => $methode, 'chemin' => $chemin];
}

/** @return array{type:string, fichier:string} */
function ecran(string $fichier): array
{
    return ['type' => 'ecran', 'fichier' => $fichier];
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

/** @var array<string, array<string, mixed>> $elements */
$elements = [];

/**
 * @param array<string, mixed> $gedv2
 * @param array<string, mixed> $preuve
 */
function poser(array &$elements, string $id, string $statut, array $gedv2, array $preuve, string $note = ''): void
{
    $elements[$id] = array_filter([
        'statut' => $statut,
        'gedv2' => $gedv2,
        'preuve' => $preuve,
        'note' => $note,
    ], static fn ($v): bool => $v !== '');
}

function exclure(array &$elements, string $id, string $raison): void
{
    $elements[$id] = ['statut' => 'exclu', 'exclusion' => $raison];
}

function absent(array &$elements, string $id, string $lot, string $note = ''): void
{
    $elements[$id] = array_filter([
        'statut' => 'absent',
        'lot' => $lot,
        'note' => $note,
    ], static fn ($v): bool => $v !== '');
}

// ===========================================================================
// 1. API REST
// ===========================================================================

$A = 'couvert-autrement';
poser($elements, 'api:route:documents', $A, route('GET', '/api/v1/documents'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesNouvellesRoutesSontDeclareesHorsSession'));
poser($elements, 'api:route:post_document', $A, route('POST', '/api/v1/documents'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testDeposerUnFichierPasseParLAChaineDIngestionEtEstIdempotent'));
poser($elements, 'api:route:correspondents', $A, route('GET', '/api/v1/emetteurs'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:route:document-types', $A, route('GET', '/api/v1/types'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:route:tags', $A, route('GET', '/api/v1/etiquettes'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:route:saved-views', $A, route('GET', '/api/v1/vues'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:route:global_search', $A, route('GET', '/recherche'), p('Ged\\Tests\\Recherche\\EcranTest::testLesVraisResultatsArriventDansLeGabarit'));
poser($elements, 'api:route:bulk_edit', $A, route('POST', '/bibliotheque/masse'), p('Ged\\Tests\\Socle\\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'));
poser($elements, 'api:route:share-links', $A, route('POST', '/documents/{id:[0-9]+}/partages'), p('Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair'));
poser($elements, 'api:route:anon:share-p-slug-w-sharedlinkview-as-view', $A, route('GET', '/partages/{jeton:[A-Za-z0-9_-]+}'), p('Ged\\Tests\\Socle\\V212dTest::testLeJetonExterneEstUnFlashDeSessionConsommeUneSeuleFois'));
poser($elements, 'api:route:workflows', $A, route('GET', '/workflows'), p('Ged\\Tests\\Workflows\\ModuleTest::testEcranRepondMemeSansTablesDuSecteur'));
poser($elements, 'api:route:workflow-triggers', $A, route('POST', '/workflows/evenements'), p('Ged\\Tests\\Workflows\\ModuleTest::testEvenementDeDepotParRouteJournaliseLAction'));
poser($elements, 'api:route:workflow-actions', $A, service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\MoteurTest::testUneErreurDActionNeBloquePasLeResteDuPlan'));
poser($elements, 'api:route:custom-fields', $A, route('POST', '/metadonnees/champ'), p('Ged\\Tests\\Socle\\ChampsTest::testUnChampTypéEstRattachéÀUnTypeAvecUneCléSlug'));
poser($elements, 'api:route:profile_view', $A, route('GET', '/profil'), p('Ged\\Tests\\Jonctions\\ProfilTest::testLAdresseEstVerifieeParUnLienEnvoyeParCourriel'));
poser($elements, 'api:route:users', $A, route('GET', '/administration/comptes'), p('Ged\\Tests\\Securite\\V213DroitsRolesTest::testAdministrationCreeUnCompteEtUnGroupeAvecTraceDaudit'));
poser($elements, 'api:route:groups', $A, route('GET', '/administration/groupes'), p('Ged\\Tests\\Securite\\V213DroitsRolesTest::testAdministrationCreeUnCompteEtUnGroupeAvecTraceDaudit'));
poser($elements, 'api:route:trash', $A, service('Ged\\Socle\\DepotFichiers'), p('Ged\\Tests\\Socle\\CorbeilleTest::testLaCorbeilleDeplaceDrapeauteEtNeffaceRien'), 'GEDv2 : corbeille par service (mise en corbeille depuis la bibliotheque), pas de route dediee.');
poser($elements, 'api:action:DocumentViewSet.download', $A, route('GET', '/api/v1/documents/{id:[0-9]+}/fichier'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testPaginationBorneeEtVersionTelechargeable'));
poser($elements, 'api:action:DocumentViewSet.preview', $A, route('GET', '/documents/{id:[0-9]+}/apercu'), p('Ged\\Tests\\Apercu\\ModuleTest::testPageApercuPdfSeDesigne'));
poser($elements, 'api:action:DocumentViewSet.thumb', $A, route('GET', '/documents/{id:[0-9]+}/miniature'), p('Ged\\Tests\\Apercu\\ModuleTest::testMiniatureServieEnPng'));
poser($elements, 'api:action:DocumentViewSet.notes', $A, route('POST', '/documents/{id:[0-9]+}/notes'), p('Ged\\Tests\\Socle\\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex'));
poser($elements, 'api:action:DocumentViewSet.share_links', $A, route('POST', '/documents/{id:[0-9]+}/partages'), p('Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair'));
poser($elements, 'api:action:DocumentViewSet.history', $A, service('Ged\\Socle\\Registre'), p('Ged\\Tests\\Socle\\RegistreTest::testÉcritPuisRelitDepuisUneAutreInstance'), 'GEDv2 : journal (AuditLog) par le Registre.');
poser($elements, 'api:action:DocumentViewSet.suggestions', $A, service('Ged\\Classement\\ClasseurGed'), p('Ged\\Tests\\Classement\\ClasseurGedTest::testSansIndiceDuToutLeRepliCourrierEstProposeSansValidation'), 'GEDv2 : propositions du classeur affichees dans A traiter.');
poser($elements, 'api:vue:DocumentViewSet', $A, route('GET', '/api/v1/documents'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesNouvellesRoutesSontDeclareesHorsSession'));
poser($elements, 'api:vue:PostDocumentView', $A, route('POST', '/api/v1/documents'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testDeposerUnFichierPasseParLAChaineDIngestionEtEstIdempotent'));
poser($elements, 'api:vue:BulkEditView', $A, route('POST', '/bibliotheque/masse'), p('Ged\\Tests\\Socle\\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'));
poser($elements, 'api:vue:SharedLinkView', $A, route('GET', '/partages/{jeton:[A-Za-z0-9_-]+}'), p('Ged\\Tests\\Socle\\V212dTest::testLeJetonExterneEstUnFlashDeSessionConsommeUneSeuleFois'));
poser($elements, 'api:vue:GlobalSearchView', $A, route('GET', '/recherche'), p('Ged\\Tests\\Recherche\\EcranTest::testLesVraisResultatsArriventDansLeGabarit'));
poser($elements, 'api:vue:ProfileView', $A, route('GET', '/profil'), p('Ged\\Tests\\Jonctions\\ProfilTest::testLAdresseEstVerifieeParUnLienEnvoyeParCourriel'));
poser($elements, 'api:vue:CorrespondentViewSet', $A, route('GET', '/api/v1/emetteurs'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:vue:DocumentTypeViewSet', $A, route('GET', '/api/v1/types'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:vue:TagViewSet', $A, route('GET', '/api/v1/etiquettes'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:vue:SavedViewViewSet', $A, route('GET', '/api/v1/vues'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
poser($elements, 'api:vue:ShareLinkViewSet', $A, route('POST', '/documents/{id:[0-9]+}/partages'), p('Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair'));
poser($elements, 'api:vue:WorkflowViewSet', $A, route('GET', '/workflows'), p('Ged\\Tests\\Workflows\\ModuleTest::testEcranRepondMemeSansTablesDuSecteur'));
poser($elements, 'api:vue:WorkflowTriggerViewSet', $A, route('POST', '/workflows/evenements'), p('Ged\\Tests\\Workflows\\ModuleTest::testEvenementDeDepotParRouteJournaliseLAction'));
poser($elements, 'api:vue:WorkflowActionViewSet', $A, service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\MoteurTest::testUneErreurDActionNeBloquePasLeResteDuPlan'));

foreach ([
    'CorrespondentViewSet' => ['GET', '/api/v1/emetteurs'],
    'DocumentTypeViewSet' => ['GET', '/api/v1/types'],
    'TagViewSet' => ['GET', '/api/v1/etiquettes'],
    'SavedViewViewSet' => ['GET', '/api/v1/vues'],
] as $vue => [$m, $c]) {
    poser($elements, "api:verbe:$vue.list", $A, route($m, $c), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesReferentielsSontExposes'));
}
poser($elements, 'api:verbe:DocumentViewSet.list', $A, route('GET', '/api/v1/documents'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testLesNouvellesRoutesSontDeclareesHorsSession'));
poser($elements, 'api:verbe:DocumentViewSet.retrieve', $A, route('GET', '/api/v1/documents/{id:[0-9]+}'), p('Ged\\Tests\\Jonctions\\DocumentsApiTest::testUnCompteNeVoitJamaisUnDocumentHorsDeSesDossiers'));

foreach ([
    'api:filtre:DocumentFilterSet.title_content' => ['GET', '/recherche'],
    'api:filtre:DocumentFilterSet.created__date__gte' => ['POST', '/recherche/vue'],
    'api:filtre:DocumentFilterSet.created__date__lte' => ['POST', '/recherche/vue'],
] as $id => [$m, $c]) {
    poser($elements, $id, $A, route($m, $c), p('Ged\\Tests\\Perf\\BibliothequeFiltresTest::testLeFiltreSqlDonneLeMemeResultatQueLeFiltragePhp'));
}
poser($elements, 'api:filtre:DocumentFilterSet.correspondent__id', $A, route('POST', '/recherche/vue'), p('Ged\\Tests\\Recherche\\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre'));
poser($elements, 'api:filtre:DocumentFilterSet.document_type__id', $A, route('POST', '/recherche/vue'), p('Ged\\Tests\\Recherche\\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre'));
poser($elements, 'api:filtre:DocumentFilterSet.tags__id__all', $A, route('POST', '/recherche/vue'), p('Ged\\Tests\\Recherche\\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre'));
poser($elements, 'api:filtre:DocumentFilterSet.tags__id__in', $A, route('POST', '/recherche/vue'), p('Ged\\Tests\\Recherche\\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre'));

// Exclusions infra Django/Celery.
foreach ([
    'api:route:base', 'api:route:anon:accounts-3rdparty-include', 'api:route:anon:accounts-include',
    'api:route:anon:accounts-password-include', 'api:route:anon:admin-admin-site-urls',
    'api:route:anon:api-auth-include', 'api:route:anon:api-documents-include', 'api:route:anon:api-include',
    'api:route:anon:api-profile-include', 'api:route:anon:api-redirectview-as-view',
    'api:route:anon:api-schema-include', 'api:route:anon:api-search-include',
    'api:route:anon:api-token-paperlessobtainauthtokenview-as-view',
    'api:route:anon:assets-path-path-redirectview-as-view',
    'api:route:anon:fetch-doc-p-pk-d-redirectview-as-view', 'api:route:anon:fetch-include',
    'api:route:anon:fetch-preview-p-pk-d-redirectview-as-view',
    'api:route:anon:fetch-thumb-p-pk-d-redirectview-as-view',
    'api:route:account_confirm_email', 'api:route:account_inactive', 'api:route:account_login',
    'api:route:account_logout', 'api:route:account_reset_password',
    'api:route:account_reset_password_done', 'api:route:account_reset_password_from_key',
    'api:route:account_reset_password_from_key_done', 'api:route:account_signup',
    'api:route:mfa_authenticate', 'api:route:admin', 'api:route:app_logo', 'api:route:favicon',
    'api:route:remoteversion', 'api:route:schema', 'api:route:swagger-ui',
    'api:vue:FaviconView', 'api:vue:IndexView', 'api:vue:PassUserMixin', 'api:vue:RemoteVersionView',
    'api:vue:PaperlessObtainAuthTokenView',
] as $id) {
    exclure($elements, $id, 'infrastructure-django');
}
foreach ([
    'api:route:tasks', 'api:route:anon:ws-status-statusconsumer-as-asgi', 'api:vue:TasksViewSet',
    'api:action:TasksViewSet.acknowledge', 'api:action:TasksViewSet.run',
] as $id) {
    exclure($elements, $id, 'infrastructure-celery-redis');
}

// absent explicites (fonctions Paperless non portees, sans exclusion).
$absentsApi = [
    'api:route:storage-paths', 'api:route:statistics', 'api:route:selection_data',
    'api:route:autocomplete', 'api:route:bulk_edit_objects', 'api:route:logs',
    'api:route:config', 'api:route:ui_settings', 'api:route:mail-accounts',
    'api:route:mail-rules', 'api:route:processed-mail', 'api:route:totp_view',
    'api:route:oauth_callback', 'api:route:socialaccount_login_cancelled',
    'api:route:socialaccount_login_error', 'api:route:socialaccount_signup',
    'api:vue:SearchAutoCompleteView', 'api:vue:BulkEditObjectsView', 'api:vue:BulkDownloadView',
    'api:vue:StoragePathViewSet', 'api:vue:LogViewSet', 'api:vue:StatisticsView',
    'api:vue:SelectionDataView', 'api:vue:SystemStatusView', 'api:vue:UiSettingsView',
    'api:vue:ApplicationConfigurationViewSet', 'api:vue:MailAccountViewSet', 'api:vue:MailRuleViewSet',
    'api:vue:ProcessedMailViewSet', 'api:vue:CustomFieldViewSet',
    'api:vue:TOTPView', 'api:vue:GenerateAuthTokenView', 'api:vue:DisconnectSocialAccountView',
    'api:vue:OauthCallbackView', 'api:vue:SharedLinkView',
    'api:action:DocumentViewSet.email_document', 'api:action:DocumentViewSet.email_documents',
    'api:action:DocumentViewSet.metadata', 'api:action:MailAccountViewSet.process',
    'api:action:MailAccountViewSet.test', 'api:action:ProcessedMailViewSet.bulk_delete',
    'api:action:StoragePathViewSet.test', 'api:action:UnifiedSearchViewSet.next_asn',
    'api:action:UserViewSet.deactivate_totp',
];
foreach ($absentsApi as $id) {
    absent($elements, $id, 'V2-05');
}

// ===========================================================================
// 2. MODELE DE DONNEES
// ===========================================================================

$modelesCouverts = [
    'modele:classe:Document' => [service('Ged\\Socle\\DepotFichiers'), p('Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1')],
    'modele:classe:Correspondent' => [service('Ged\\Socle\\Nomenclature'), p('Ged\\Tests\\Socle\\NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase')],
    'modele:classe:Tag' => [service('Ged\\Socle\\Nomenclature'), p('Ged\\Tests\\Socle\\NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase')],
    'modele:classe:DocumentType' => [service('Ged\\Socle\\Nomenclature'), p('Ged\\Tests\\Socle\\NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase')],
    'modele:classe:StoragePath' => [service('Ged\\Socle\\Fichiers'), p('Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1')],
    'modele:classe:SavedView' => [service('Ged\\Socle\\Vues'), p('Ged\\Tests\\Socle\\VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier')],
    'modele:classe:SavedViewFilterRule' => [service('Ged\\Socle\\Vues'), p('Ged\\Tests\\Socle\\VuesTest::testCompilerDesFiltresTypés')],
    'modele:classe:CustomField' => [service('Ged\\Socle\\Champs'), p('Ged\\Tests\\Socle\\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut')],
    'modele:classe:CustomFieldInstance' => [service('Ged\\Socle\\Champs'), p('Ged\\Tests\\Socle\\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex')],
    'modele:classe:Note' => [service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex')],
    'modele:classe:ShareLink' => [service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair')],
    'modele:classe:Workflow' => [service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\MoteurTest::testConditionsEvalueesSurFiche')],
    'modele:classe:WorkflowTrigger' => [service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\ModuleTest::testEvenementDeDepotParRouteJournaliseLAction')],
    'modele:classe:WorkflowAction' => [service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\MoteurTest::testUneErreurDActionNeBloquePasLeResteDuPlan')],
    'modele:classe:WorkflowActionEmail' => [service('Ged\\Workflows\\Catalogue'), p('Ged\\Tests\\Workflows\\CatalogueTest::testLesActionsExposeesSontExactementCellesDuMoteur')],
    'modele:classe:WorkflowActionWebhook' => [service('Ged\\Workflows\\Catalogue'), p('Ged\\Tests\\Workflows\\CatalogueTest::testLesActionsExposeesSontExactementCellesDuMoteur')],
    'modele:classe:WorkflowRun' => [service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\MoteurTest::testHorlogeFigeeInjecteeHorodateLeJournal')],
    'modele:classe:MailAccount' => [service('Ged\\Ingestion\\Imap\\ImapBoite'), p('Ged\\Tests\\Ingestion\\SourceAdresseTest::testClientImapContreServeurLocal')],
];
foreach ($modelesCouverts as $id => [$gedv2, $preuve]) {
    poser($elements, $id, $A, $gedv2, $preuve);
}

// Champs de Document persistes dans le .meta.json de GEDv2.
$champsDocument = [
    'title', 'correspondent', 'document_type', 'tags', 'created', 'content',
    'storage_path', 'original_filename',
];
foreach ($champsDocument as $champ) {
    poser(
        $elements,
        "modele:champ:Document.$champ",
        $A,
        service('Ged\\Socle\\DepotFichiers'),
        p('Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1'),
    );
}
foreach (['Correspondent.name', 'Tag.name', 'DocumentType.name', 'StoragePath.path'] as $champ) {
    poser($elements, "modele:champ:$champ", $A, service('Ged\\Socle\\Nomenclature'), p('Ged\\Tests\\Socle\\NomenclatureTest::testLesTypesLivresSontLisiblesDansLOrdreDuPlan'));
}
foreach (['note', 'document', 'user', 'created'] as $champ) {
    poser($elements, "modele:champ:Note.$champ", $A, service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex'));
}
foreach (['slug', 'document', 'expiration', 'owner', 'created'] as $champ) {
    poser($elements, "modele:champ:ShareLink.$champ", $A, service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair'));
}
foreach (['name'] as $champ) {
    poser($elements, "modele:champ:SavedView.$champ", $A, service('Ged\\Socle\\Vues'), p('Ged\\Tests\\Socle\\VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier'));
}
foreach (['saved_view', 'rule_type', 'value'] as $champ) {
    poser($elements, "modele:champ:SavedViewFilterRule.$champ", $A, service('Ged\\Socle\\Vues'), p('Ged\\Tests\\Socle\\VuesTest::testCompilerDesFiltresTypés'));
}
foreach (['name', 'data_type', 'extra_data', 'created'] as $champ) {
    poser($elements, "modele:champ:CustomField.$champ", $A, service('Ged\\Socle\\Champs'), p('Ged\\Tests\\Socle\\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut'));
}
foreach (['document', 'field', 'value_text', 'value_bool', 'value_date', 'value_int', 'value_float', 'value_monetary', 'value_select', 'value_long_text'] as $champ) {
    poser($elements, "modele:champ:CustomFieldInstance.$champ", $A, service('Ged\\Socle\\Champs'), p('Ged\\Tests\\Socle\\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex'));
}

$typesCouverts = [
    'modele:type:FieldDataType.STRING', 'modele:type:FieldDataType.DATE', 'modele:type:FieldDataType.BOOL',
    'modele:type:FieldDataType.INT', 'modele:type:FieldDataType.FLOAT', 'modele:type:FieldDataType.MONETARY',
    'modele:type:FieldDataType.SELECT', 'modele:type:FieldDataType.LONG_TEXT',
];
foreach ($typesCouverts as $id) {
    poser($elements, $id, $A, service('Ged\\Socle\\Champs'), p('Ged\\Tests\\Socle\\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut'));
}
poser($elements, 'modele:type:DocumentSourceChoices.CONSUME_FOLDER', $A, service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLotDecoupeEnPiecesPuisRange'));
poser($elements, 'modele:type:DocumentSourceChoices.API_UPLOAD', $A, route('POST', '/api/v1/depots'), p('Ged\\Tests\\Jonctions\\DepotApiTest::testTroisFichiersPoussesDeuxFoisDonnentTroisTravauxSansDoublon'));
poser($elements, 'modele:type:DocumentSourceChoices.MAIL_FETCH', $A, service('Ged\\Ingestion\\Imap\\ImapBoite'), p('Ged\\Tests\\Ingestion\\SourceAdresseTest::testClientImapContreServeurLocal'));
poser($elements, 'modele:type:DocumentSourceChoices.WEB_UI', $A, route('POST', '/deposer'), p('Ged\\Tests\\Ui\\ATraiterNomenclatureTest::testUnTypeAjouteAuFondsApparaitDansLesChoixDeLEcran'));
poser($elements, 'modele:type:FileVersion.ORIGINAL', $A, service('Ged\\Socle\\DepotFichiers'), p('Ged\\Tests\\Socle\\RangementTest::testNouvelleVersionConserveLaPrecedenteEtIndexeLeNouveauContenu'));
poser($elements, 'modele:type:FileVersion.ARCHIVE', $A, service('Ged\\Ingestion\\CopieArchivage'), p('Ged\\Tests\\Ingestion\\CopieArchivageTest::testUnPdfTexteDonneUneCopiePdfA2bAvecXmpOutputIntentEtTexteConserve'));
// GEDv2 connait ssl (993) et tcp (non chiffre, tests locaux) ; STARTTLS n'est pas porte.
foreach (['modele:type:ImapSecurity.NONE', 'modele:type:ImapSecurity.SSL'] as $id) {
    poser($elements, $id, $A, service('Ged\\Ingestion\\Imap\\ImapBoite'), p('Ged\\Tests\\Ingestion\\SourceAdresseTest::testClientImapContreServeurLocal'));
}
poser($elements, 'modele:type:ScheduleDateField.CUSTOM_FIELD', $A, service('Ged\\Workflows\\Catalogue'), p('Ged\\Tests\\Workflows\\GabaritsTest::testContratAEcheanceProduitSonRappelAJ90'));
poser($elements, 'modele:type:WorkflowTriggerType.CONSUMPTION', $A, service('Ged\\Workflows\\Moteur'), p('Ged\\Tests\\Workflows\\ModuleTest::testEvenementDeDepotParRouteJournaliseLAction'));
poser($elements, 'modele:type:WorkflowTriggerType.SCHEDULED', $A, service('Ged\\Workflows\\Catalogue'), p('Ged\\Tests\\Workflows\\GabaritsTest::testContratAEcheanceProduitSonRappelAJ90'));
foreach (['modele:type:WorkflowActionType.ASSIGNMENT', 'modele:type:WorkflowActionType.REMOVAL'] as $id) {
    poser($elements, $id, $A, service('Ged\\Workflows\\Catalogue'), p('Ged\\Tests\\Workflows\\CatalogueTest::testLesActionsExposeesSontExactementCellesDuMoteur'));
}

// Modeles exclus (Celery) ou absents.
foreach (['modele:classe:PaperlessTask'] as $id) {
    exclure($elements, $id, 'infrastructure-celery-redis');
}
foreach ([
    'modele:classe:MatchingModel', 'modele:classe:ModelWithOwner',
    'modele:classe:UiSettings', 'modele:classe:MailRule', 'modele:classe:ProcessedMail',
] as $id) {
    absent($elements, $id, 'V2-04');
}

// ===========================================================================
// 3. OPERATIONS EN MASSE
// ===========================================================================

$masse = [
    'set_correspondent' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'set_document_type' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'set_storage_path' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'add_tag' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'remove_tag' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'modify_tags' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'modify_custom_fields' => ['assignation', 'testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire'],
    'delete' => ['corbeille', 'testLaCorbeilleEstUneMiseEnCorbeilleJamaisUneSuppression'],
    'rotate' => ['retouche-pdf', 'testTournerScinderFusionnerEtRetirerReecriventDesPdfValides'],
    'merge' => ['retouche-pdf', 'testTournerScinderFusionnerEtRetirerReecriventDesPdfValides'],
    'split' => ['retouche-pdf', 'testTournerScinderFusionnerEtRetirerReecriventDesPdfValides'],
    'delete_pages' => ['retouche-pdf', 'testTournerScinderFusionnerEtRetirerReecriventDesPdfValides'],
    'edit_pdf' => ['retouche-pdf', 'testTournerScinderFusionnerEtRetirerReecriventDesPdfValides'],
];
foreach ($masse as $fonction => [$genre, $methode]) {
    $classe = $genre === 'retouche-pdf' ? 'Ged\\Ingestion\\EditeurPdf' : 'Ged\\Socle\\ModificationMasse';
    $test = $genre === 'retouche-pdf' ? 'Ged\\Tests\\Ingestion\\EditeurPdfTest' : 'Ged\\Tests\\Socle\\ModificationMasseTest';
    poser($elements, "masse:fonction:$fonction", $A, service($classe), p($test . '::' . $methode));
}
foreach (['masse:fonction:reflect_doclink', 'masse:fonction:remove_doclink'] as $id) {
    poser($elements, $id, $A, service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testUneNoteRetireeSortDeLIndexEtUnLienEstVisibleDesDeuxCotes'));
}
foreach (['masse:fonction:reprocess', 'masse:fonction:set_permissions'] as $id) {
    absent($elements, $id, 'V2-03');
}

// ===========================================================================
// 4. CHAINE DE CONSOMMATION
// ===========================================================================

$chaine = [
    'chaine:etape:ConsumerPlugin.run' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLotDecoupeEnPiecesPuisRange')],
    'chaine:etape:ConsumerPlugin._store' => [service('Ged\\Socle\\DepotFichiers'), p('Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1')],
    'chaine:etape:ConsumerPlugin._write' => [service('Ged\\Socle\\DepotFichiers'), p('Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1')],
    'chaine:etape:ConsumerPreflightPlugin.pre_check_file_exists' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLeMemeFichierNEntreJamaisDeuxFois')],
    'chaine:etape:ConsumerPreflightPlugin.pre_check_directories' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testMemoireDEtatDansUnDossierQuiNExistePasEncore')],
    'chaine:doublon:pre_check_duplicate' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLeMemeFichierNEntreJamaisDeuxFois')],
    'chaine:etape:ConsumerPreflightPlugin.pre_check_duplicate' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLeMemeFichierNEntreJamaisDeuxFois')],
    'chaine:classe:ConsumerPlugin' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLotDecoupeEnPiecesPuisRange')],
    'chaine:parseur:DocumentParser.get_date' => [service('Ged\\Classement\\Regles'), p('Ged\\Tests\\Classement\\ReglesTest::testDateEnTete')],
    'chaine:parseur:documents.parse_date' => [service('Ged\\Classement\\Regles'), p('Ged\\Tests\\Classement\\ReglesTest::testDateEnTete')],
    'chaine:parseur:DocumentParser.get_thumbnail' => [service('Ged\\Apercu\\Miniature'), p('Ged\\Tests\\Apercu\\MiniatureTest::testGenereUneVignetteDepuisUnPdf')],
    'chaine:parseur:documents.make_thumbnail_from_pdf' => [service('Ged\\Apercu\\Miniature'), p('Ged\\Tests\\Apercu\\MiniatureTest::testGenereUneVignetteDepuisUnPdf')],
    'chaine:parseur:documents.make_thumbnail_from_pdf_gs_fallback' => [service('Ged\\Apercu\\Miniature'), p('Ged\\Tests\\Apercu\\MiniatureTest::testLaVignetteDuRepliGhostscriptRespecteLaTaille')],
    'chaine:nom-fichier:get_basic_metadata_context' => [service('Ged\\Socle\\Fichiers'), p('Ged\\Tests\\Socle\\RangementTest::testDepotRangeFichierMetaEtVersion1')],
];
foreach ($chaine as $id => [$gedv2, $preuve]) {
    poser($elements, $id, $A, $gedv2, $preuve);
}

// ===========================================================================
// 5. CORRESPONDANCE AUTOMATIQUE
// ===========================================================================
// Lot ged-parite-comportement-1 : le service Ged\Classement\Correspondance rejoue les algorithmes de
// documents.matching.matches (et _split_match pour le decoupage des mots). Preuves : les tests
// unitaires du service, dont les vecteurs complets sont rejoues par tests/php/Parite/ComportementTest.php.

$correspondance = [
    'correspondance:algo:MATCH_NONE' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testUnMotifVideNeCorrespondJamais'),
    'correspondance:algo:MATCH_ALL' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchAll'),
    'correspondance:algo:MATCH_ANY' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchAny'),
    'correspondance:algo:MATCH_LITERAL' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchLiteral'),
    'correspondance:algo:MATCH_REGEX' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchRegex'),
    'correspondance:algo:MATCH_FUZZY' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchFuzzy'),
    'correspondance:algo:MATCH_AUTO' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchNoneEtMatchAutoNeCorrespondentJamais'),
    'correspondance:fonction:matches' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testUnMotifVideNeCorrespondJamais'),
    'correspondance:fonction:_split_match' => p('Ged\\Tests\\Classement\\CorrespondanceTest::testMatchAll'),
];
foreach ($correspondance as $id => $preuve) {
    poser($elements, $id, $A, service('Ged\\Classement\\Correspondance'), $preuve,
        'GEDv2 : service de correspondance rejouant les algorithmes de Paperless, vecteurs en phase 2.');
}

// ===========================================================================
// 6. ECRANS
// ===========================================================================

$ecrans = [
    'ecran:route:documents' => ['templates/ecrans/bibliotheque.twig', 'Ged\\Tests\\EcransTest::testEcranRepondEtSeDesigne'],
    'ecran:route:documents-id' => ['templates/ecrans/document.twig', 'Ged\\Tests\\EcransTest::testEcranRepondEtSeDesigne'],
    'ecran:route:documents-id-section' => ['templates/ecrans/document.twig', 'Ged\\Tests\\EcransTest::testEcranRepondEtSeDesigne'],
    'ecran:route:view-id' => ['templates/ecrans/bibliotheque.twig', 'Ged\\Tests\\Socle\\VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier'],
    'ecran:route:workflows' => ['templates/ecrans/workflows.twig', 'Ged\\Tests\\Workflows\\ModuleTest::testEcranRepondMemeSansTablesDuSecteur'],
    'ecran:route:customfields' => ['templates/ecrans/metadonnees.twig', 'Ged\\Tests\\Socle\\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds'],
    'ecran:route:tags' => ['templates/ecrans/metadonnees.twig', 'Ged\\Tests\\Socle\\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds'],
    'ecran:route:documenttypes' => ['templates/ecrans/metadonnees.twig', 'Ged\\Tests\\Socle\\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds'],
    'ecran:route:correspondents' => ['templates/ecrans/metadonnees.twig', 'Ged\\Tests\\Socle\\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds'],
    'ecran:route:404' => ['templates/erreur.twig', 'Ged\\Tests\\EcransTest::testPageInconnueEstNommee'],
];
foreach ($ecrans as $id => [$fichier, $preuve]) {
    poser($elements, $id, $A, ecran($fichier), p($preuve));
}
foreach (['ecran:route:vide'] as $id) {
    exclure($elements, $id, 'infrastructure-django');
}

// ===========================================================================
// 7. COMMANDES DE GESTION
// ===========================================================================

$commandes = [
    'commande:documents:document_consumer' => [service('Ged\\Ingestion\\Pilote'), p('Ged\\Tests\\Ingestion\\PiloteTest::testLotDecoupeEnPiecesPuisRange')],
    'commande:documents:document_index' => [service('Ged\\Socle\\ReconstructionIndex'), p('Ged\\Tests\\Socle\\ReconstructionTest::testLaCommandeReconstruireIndexRendLeMemeHash')],
    'commande:documents:document_thumbnails' => [service('Ged\\Apercu\\Miniature'), p('Ged\\Tests\\Apercu\\MiniatureTest::testGenereUneVignetteDepuisUnPdf')],
    'commande:documents:document_archiver' => [service('Ged\\Ingestion\\CopieArchivage'), p('Ged\\Tests\\Ingestion\\CopieArchivageTest::testUnPdfTexteDonneUneCopiePdfA2bAvecXmpOutputIntentEtTexteConserve')],
    'commande:documents:document_sanity_checker' => [service('Ged\\Archivage\\Verificateur'), p('Ged\\Tests\\Archivage\\VerificateurTest::testFondsSainRendZeroEcart')],
    'commande:documents:document_renamer' => [service('Ged\\Socle\\Fichiers'), p('Ged\\Tests\\Socle\\RangementTest::testMiseAJourDeplaceFichierMetaVersionsEtTrace')],
    'commande:documents:document_create_classifier' => [service('Ged\\Classement\\Habitudes'), p('Ged\\Tests\\Classement\\HabitudesTest::testTroisChoixIdentiquesFontUneHabitude')],
    'commande:documents:manage_superuser' => [service('Ged\\Socle\\Schema'), p('Ged\\Tests\\Socle\\InstallateurTest::testPoseUnSchemaNeufAvecCompteAdmin')],
    'commande:paperless_mail:mail_fetcher' => [service('Ged\\Ingestion\\Imap\\ImapBoite'), p('Ged\\Tests\\Ingestion\\SourceAdresseTest::testClientImapContreServeurLocal')],
];
foreach ($commandes as $id => [$gedv2, $preuve]) {
    poser($elements, $id, $A, $gedv2, $preuve);
}
foreach (['commande:documents:convert_mariadb_uuid', 'commande:documents:loaddata_stdin'] as $id) {
    exclure($elements, $id, 'infrastructure-django');
}

// ===========================================================================
// 8. PERMISSIONS
// ===========================================================================

$permissions = [
    'permissions:proprietaire:ModelWithOwner' => [service('Ged\\Securite\\DroitsDossiers'), p('Ged\\Tests\\Securite\\DroitsBaseTest::testOracleDroitsEstRespecteParToutesLesSurfaces')],
    'permissions:proprietaire:ShareLink' => [service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair')],
    'permissions:classe:ViewDocumentsPermissions' => [service('Ged\\Securite\\DroitsDossiers'), p('Ged\\Tests\\Securite\\DroitsBaseTest::testOracleDroitsEstRespecteParToutesLesSurfaces')],
    'permissions:classe:PaperlessAdminPermissions' => [service('Ged\\Securite\\DroitsDossiers'), p('Ged\\Tests\\Securite\\V213DroitsRolesTest::testMatriceCanoniqueSuitLaDecisionEtCouvreLesRoutes')],
    'permissions:classe:PaperlessNotePermissions' => [service('Ged\\Socle\\Collaboration'), p('Ged\\Tests\\Socle\\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex')],
    'permissions:fonction:get_document_count_filter_for_user' => [service('Ged\\Securite\\DroitsDossiers'), p('Ged\\Tests\\Recherche\\SemblablesTest::testLesDroitsDeDossierSontAppliquesDansLaRequete')],
    'permissions:fonction:has_perms_owner_aware' => [service('Ged\\Securite\\DroitsDossiers'), p('Ged\\Tests\\Securite\\DroitsBaseTest::testHeritageEtRefusExpliciteSontPlusFortsQueLaPermissionDuRole')],
];
foreach ($permissions as $id => [$gedv2, $preuve]) {
    poser($elements, $id, $A, $gedv2, $preuve);
}
foreach (['permissions:classe:AcknowledgeTasksPermissions'] as $id) {
    exclure($elements, $id, 'infrastructure-celery-redis');
}

// ===========================================================================
// Assemblage : tout element non declare est « absent » (statut le plus bas).
// ===========================================================================

$lots = [
    'api' => 'V2-05', 'modele' => 'V2-04', 'masse' => 'V2-03', 'chaine' => 'V2-02',
    'correspondance' => 'V2-03', 'ecran' => 'V2-03', 'commande' => 'V2-00', 'permissions' => 'V2-07',
];

$correspondance = [];
$manquants = [];
foreach ($inventaire['elements'] as $element) {
    $id = (string) $element['id'];
    if (isset($elements[$id])) {
        $correspondance[$id] = $elements[$id];
        continue;
    }
    $manquants[] = $id;
    $correspondance[$id] = ['statut' => 'absent', 'lot' => $lots[(string) $element['categorie']] ?? 'V2-XX'];
}

ksort($correspondance);

$sortie = [
    'version' => 1,
    'reference' => $inventaire['reference'],
    'total' => count($correspondance),
    'elements' => $correspondance,
];

file_put_contents(
    $racine . '/docs/parite/correspondance.json',
    json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);
printf(
    "OK: %d statuts ecrits (%d declares, %d absents par defaut)\n",
    count($correspondance),
    count($elements),
    count($manquants),
);
