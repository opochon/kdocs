<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-api-4.
 *
 * Additif, comme les correcteurs api-2 / api-3 : il ne touche QUE les 79 éléments listés dans
 * docs/lots/ged-parite-api-4-elements.txt et laisse intacts les couverts des lots précédents.
 *
 * Chaque `couvert` cite une route GEDv2 réelle ET une preuve PHPUnit rejouée ; chaque `couvert-autrement`
 * cite la route/écran/service existant et sa preuve ; chaque `absent` porte sa raison, sa référence
 * Paperless et le coût de l'évolution (schéma, écrans, flux F). Le juge tranche.
 *
 * Usage : php tools/parite/corriger-correspondance-api-4.php
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

// ---------------------------------------------------------------------------
// Preuves du lot api-4 (couche 1), rejouées au dernier passage.
// ---------------------------------------------------------------------------

$pvTaches = 'Ged\\Tests\\Parite\\ApiQuatreTachesTest::testRejoueLesVecteursDeTachesEtLeursFiltres';
$pvTotp = 'Ged\\Tests\\Parite\\ApiQuatreTotpTest::testRejoueLesVecteursTotp';
$pvMfa = 'Ged\\Tests\\Parite\\ApiQuatreTotpTest::testLaConnexionExigeLeCodeTotp';
$pvConnexion = 'Ged\\Tests\\Parite\\ApiQuatreTotpTest::testLEcranDeConnexionEstDisponible';
$pvJeton = 'Ged\\Tests\\Parite\\ApiQuatreJetonTest::testRejoueLesVecteursDuJetonEtDuCodeMfa';
$pvStatut = 'Ged\\Tests\\Parite\\ApiQuatreStatutTest::testRejoueLesVecteursDeStatutEtDeVersion';
$pvPartage = 'Ged\\Tests\\Parite\\ApiQuatrePartageTest::testRejoueLesVecteursDeMiseAJourDUnLienDePartage';
$pvConfig = 'Ged\\Tests\\Parite\\ApiQuatreAppConfigTest::testRejoueLesVecteursDeConfigurationEtDImages';
$pvDestroy = 'Ged\\Tests\\Parite\\ApiQuatreDestroyTest::testLeRetraitDUnTypeDesactiveSansSupprimer';
$pvWorkflows = 'Ged\\Tests\\Workflows\\ModuleTest::testLAdminCreeEditeActiveEtMontreLHistorique';

$doublon = 'Le doublon de la route est déjà traité par le même oracle.';

// ---------------------------------------------------------------------------
// 1. Couverts par une route NOUVELLE du lot (route + preuve PHPUnit).
// ---------------------------------------------------------------------------

/** @var array<string, array{0:array<string,string>, 1:array<string,string>}> $couverts */
$couverts = [
    // Tâches : actions nouvelles.
    'api:action:TasksViewSet.acknowledge' => [route('POST', '/api/v1/taches/acknowledge'), p($pvTaches)],
    'api:action:TasksViewSet.run' => [route('POST', '/api/v1/taches/run'), p($pvTaches)],
    // 2FA / TOTP.
    'api:action:UserViewSet.deactivate_totp' => [route('POST', '/api/v1/comptes/{id:[0-9]+}/deactivate_totp'), p($pvTotp)],
    'api:verbe:TOTPView.get' => [route('GET', '/api/v1/profil/totp'), p($pvTotp)],
    'api:verbe:TOTPView.post' => [route('POST', '/api/v1/profil/totp'), p($pvTotp)],
    'api:verbe:TOTPView.delete' => [route('DELETE', '/api/v1/profil/totp'), p($pvTotp)],
    'api:route:totp_view' => [route('GET', '/api/v1/profil/totp'), p($pvTotp)],
    // Jeton d'API.
    'api:route:anon:api-token-paperlessobtainauthtokenview-as-view' => [route('POST', '/api/v1/token'), p($pvJeton)],
    'api:vue:PaperlessObtainAuthTokenView' => [route('POST', '/api/v1/token'), p($pvJeton)],
    // État système et version distante.
    'api:route:system_status' => [route('GET', '/api/v1/statut'), p($pvStatut)],
    'api:vue:SystemStatusView' => [route('GET', '/api/v1/statut'), p($pvStatut)],
    'api:route:remoteversion' => [route('GET', '/api/v1/version'), p($pvStatut)],
    'api:verbe:RemoteVersionView.get' => [route('GET', '/api/v1/version'), p($pvStatut)],
    'api:vue:RemoteVersionView' => [route('GET', '/api/v1/version'), p($pvStatut)],
    // Logo / favicon.
    'api:route:app_logo' => [route('GET', '/logo'), p($pvConfig)],
    'api:route:favicon' => [route('GET', '/favicon'), p($pvConfig)],
    'api:verbe:FaviconView.get' => [route('GET', '/favicon'), p($pvConfig)],
    // Configuration applicative : création interdite (405).
    'api:verbe:ApplicationConfigurationViewSet.create' => [route('POST', '/api/v1/configuration'), p($pvConfig)],
    // Partage : mise à jour de l'échéance.
    'api:verbe:ShareLinkViewSet.partial_update' => [route('PATCH', '/api/v1/partages/{id:[0-9]+}'), p($pvPartage)],
    'api:verbe:ShareLinkViewSet.update' => [route('PUT', '/api/v1/partages/{id:[0-9]+}'), p($pvPartage)],
    // Retrait d'un type de document (désactivation tracée).
    'api:verbe:DocumentTypeViewSet.destroy' => [route('DELETE', '/api/v1/types/{id:[0-9]+}'), p($pvDestroy)],
];

// ---------------------------------------------------------------------------
// 2. Couverts autrement (route/écran/service existant + preuve).
// ---------------------------------------------------------------------------

/** @var array<string, array{0:array<string,string>, 1:array<string,string>, 2:string}> $autrement */
$autrement = [
    // Tâches : la liste existe déjà ; les filtres sont des paramètres de la même route.
    'api:route:tasks' => [route('GET', '/api/v1/taches'), p($pvTaches),
        'La route `/api/tasks/` de Paperless correspond à `GET /api/v1/taches` (file ingestion_travaux + suivi, sans Celery).'],
    'api:vue:TasksViewSet' => [route('GET', '/api/v1/taches'), p($pvTaches), $doublon],
    'api:filtre:PaperlessTaskFilterSet.acknowledged' => [route('GET', '/api/v1/taches'), p($pvTaches),
        'Filtre porté par la même route : `?acknowledged=` (rejoué par l’oracle).'],
    'api:filtre:PaperlessTaskFilterSet.status' => [route('GET', '/api/v1/taches'), p($pvTaches),
        'Filtre porté par la même route : `?status=` (rejoué par l’oracle).'],
    'api:filtre:PaperlessTaskFilterSet.task_name' => [route('GET', '/api/v1/taches'), p($pvTaches),
        'Filtre porté par la même route : `?task_name=` (rejoué par l’oracle).'],
    'api:filtre:PaperlessTaskFilterSet.type' => [route('GET', '/api/v1/taches'), p($pvTaches),
        'Filtre porté par la même route : `?type=` (rejoué par l’oracle).'],
    'api:vue:TOTPView' => [route('GET', '/api/v1/profil/totp'), p($pvTotp),
        'La vue TOTP est rendue par les trois verbes de `/api/v1/profil/totp`.'],
    // MFA de la connexion.
    'api:route:mfa_authenticate' => [route('POST', '/connexion'), p($pvMfa),
        'La porte MFA est portée par la connexion GEDv2 : quand le TOTP est actif, un code est exigé avant l’ouverture de session.'],
    'api:route:account_login' => [route('GET', '/connexion'), p($pvConnexion),
        'L’écran de connexion GEDv2 remplace la page django-allauth `account_login`.'],
    'api:route:account_confirm_email' => [route('GET', '/profil/mail/verifier'), p('Ged\\Tests\\Jonctions\\ProfilTest::testLAdresseEstVerifieeParUnLienEnvoyeParCourriel'),
        'La confirmation d’adresse django-allauth correspond à la vérification d’adresse GEDv2 : le lien à usage unique arrive sur `/profil/mail/verifier`.'],
    // Favicon (vue).
    'api:vue:FaviconView' => [route('GET', '/favicon'), p($pvConfig), $doublon],
    // Retrait d’un workflow : désactivation, précédent MailAccountViewSet.destroy.
    'api:verbe:WorkflowViewSet.destroy' => [route('POST', '/workflows/admin/{id:[0-9]+}/basculer'), p($pvWorkflows),
        'GEDv2 ne supprime pas une définition de workflow : la bascule la désactive (preuve existante).'],
];

// ---------------------------------------------------------------------------
// 3. Absents : raison, référence Paperless et coût de l’évolution.
// ---------------------------------------------------------------------------

$refFiltres = 'src/documents/filters.py:95,726,749,763';
$refModeles = 'src/documents/models.py';

/** @var array<string, string> $absents */
$absents = [];

// Filtres de documents qui supposent un modèle absent.
$absents['api:filtre:DocumentFilterSet.owner'] =
    'Propriétaire de document absent du modèle GEDv2 : le document n’a pas d’utilisateur propriétaire (le droit est par dossier et par portée de jeton). '
    . 'Évolution : colonne `documents.proprietaire_id` (ou registre du fonds) + écran de réaffectation + reprise de la indexation, flux F1 (dépôt) et F10 (droits). Référence ' . $refFiltres . '.';
$absents['api:filtre:DocumentFilterSet.owner__id'] =
    'Même absence de propriétaire (`owner__id`). Évolution : colonne propriétaire + filtre + écran, flux F1/F10. Référence ' . $refFiltres . '.';
$absents['api:filtre:DocumentFilterSet.owner__id__none'] =
    'Même absence de propriétaire (`owner__id__none`, documents sans propriétaire). Évolution : colonne propriétaire nullable + filtre, flux F1/F10. Référence ' . $refFiltres . '.';
$absents['api:filtre:DocumentFilterSet.original_filename'] =
    'Nom de fichier d’origine non porté par `documents` : GEDv2 expose le nom public (`nom`) et conserve le nom d’origine dans la file `ingestion_travaux` (travail), pas sur la fiche. '
    . 'Évolution : colonne `documents.nom_origine` + reprise des index + filtre, flux F1 (dépôt) et F5 (retrouver). Référence ' . $refFiltres . '.';
$absents['api:filtre:DocumentFilterSet.custom_field_query'] =
    'Le `custom_field_query` de Paperless est un DSL JSON propre à son moteur (fields.py) : aucune requête équivalente côté GEDv2, qui filtre par champs via ses propres critères. '
    . 'Porter le DSL serait un moteur de requêtes parallèle ; proposition au juge : laisser absent. Référence ' . $refFiltres . '.';
$absents['api:filtre:TagFilterSet.is_root'] =
    'Hiérarchie d’étiquettes absente : les étiquettes GEDv2 sont plates (nom, couleur, boîte de réception), sans parent/enfant. '
    . 'Évolution : colonne `etiquettes_parents` + arbre + écran de glisser-déposer + héritage des filtres, flux F3 (classement) et F5. Référence ' . $refFiltres . '.';

// Comptes / courriel (django-allauth).
$absents['api:route:account_logout'] =
    'La déconnexion existe (`POST /deconnexion`) mais sa preuve de bout en bout passe par un parcours navigateur avec session : proposé au juge plutôt que couvert par un test de session synthétique. Référence src/paperless/urls.py.';
$absents['api:route:account_inactive'] =
    'Pas de notion de compte « inactif » dans GEDv2 : un compte est créé/supprimé de la matrice des rôles, la connexion échoue par un refus nommé. '
    . 'Évolution : colonne `comptes.actif` + écran d’administration + refus de connexion nommé, flux F10. Référence src/paperless/urls.py.';
$absents['api:route:account_signup'] =
    'GEDv2 n’ouvre pas d’inscription libre : un administrateur crée les comptes (`/administration/comptes`). '
    . 'Porter `account_signup` supposerait une politique d’inscription (invitation, validation) + écran + courriel, flux F10. Proposition au juge. Référence src/paperless/urls.py.';
foreach (['account_reset_password', 'account_reset_password_done', 'account_reset_password_from_key', 'account_reset_password_from_key_done'] as $nom) {
    $absents['api:route:' . $nom] =
        'Réinitialisation de mot de passe par courriel absente : GEDv2 vérifie déjà une adresse (`/profil/mail/verifier`) mais n’a pas de jeton de réinitialisation. '
        . 'Évolution : registre de jetons à usage unique + deux écrans + envoi via le mailer existant, flux F10. Référence src/paperless/urls.py (django-allauth).';
}

// Comptes sociaux / SSO (django-allauth, OAuth).
$sso = [
    'api:route:anon:api-profile-disconnect-social-account-disconnectsocialaccountview-as-view',
    'api:route:anon:api-profile-social-account-providers-socialaccountprovidersview-as-view',
    'api:route:oauth_callback',
    'api:route:socialaccount_login_cancelled',
    'api:route:socialaccount_login_error',
    'api:route:socialaccount_signup',
    'api:verbe:DisconnectSocialAccountView.post',
    'api:verbe:OauthCallbackView.get',
    'api:verbe:SocialAccountProvidersView.get',
    'api:vue:DisconnectSocialAccountView',
    'api:vue:OauthCallbackView',
    'api:vue:SocialAccountProvidersView',
];
foreach ($sso as $id) {
    $absents[$id] =
        'Comptes sociaux / SSO django-allauth absents : GEDv2 s’authentifie sur ses propres comptes (mot de passe + TOTP porté par ce lot). '
        . 'Évolution : clients OAuth2 par fournisseur (config, écran de liaison, rappel, jetons chiffrés) + politique de rattachement d’identité, flux F10. '
        . 'Proposition au juge : laisser absent à ce stade. Référence src/paperless/urls.py, src/paperless/views.py.';
}

// WebSocket.
$absents['api:route:anon:ws-status-statusconsumer-as-asgi'] =
    'WebSocket de statut ASGI absent : GEDv2 doit tourner en hébergement mutualisé sans démon ni socket persistant. '
    . 'Correspondance fonctionnelle : `GET /api/v1/taches` (suivi réel, polling court) — porté par ce lot. '
    . 'Porter un canal temps réel supposerait un serveur ASGI séparé, interdit par la cible. Proposition au juge. Référence src/paperless/asgi.py.';

// Correspondants (registre d’émetteurs).
foreach (['create', 'retrieve', 'update', 'partial_update', 'destroy'] as $verbe) {
    $absents['api:verbe:CorrespondentViewSet.' . $verbe] =
        'Émetteur = chaîne portée par le document (`documents.emetteur`), pas d’entité : `GET /api/v1/emetteurs` en expose déjà la liste dérivée. '
        . 'Un `' . $verbe . '` supposerait un registre d’émetteurs (fichier `.ged/emetteurs.json`, service Socle, écran, fusion des graphies), flux F3 (classement) et F5. Référence ' . $refModeles . '.';
}

// Chemins de stockage.
foreach (['create', 'retrieve', 'update', 'partial_update', 'destroy'] as $verbe) {
    $absents['api:verbe:StoragePathViewSet.' . $verbe] =
        'Pas d’entité « chemin de stockage » : GEDv2 range par DOSSIER (`documents.chemin_relatif`, `securite_dossiers`) — `GET /api/v1/dossiers` en expose la liste. '
        . 'Un `' . $verbe . '` supposerait un registre de gabarits de chemin + dossier cible + écran, flux F1/F3. Référence ' . $refModeles . '.';
}

// Retraits de référentiels (drapeau de retrait absent).
$absents['api:verbe:TagViewSet.destroy'] =
    'GEDv2 ne supprime jamais un référentiel (règle 4). Les étiquettes n’ont pas de drapeau de retrait. '
    . 'Évolution : colonne `etiquettes.retire_le` + écran de retrait + trace, flux F3/F5. Référence ' . $refModeles . '.';
$absents['api:verbe:CustomFieldViewSet.destroy'] =
    'GEDv2 ne supprime jamais un référentiel (règle 4). Les champs personnalisés vivent dans `.ged/` ; un retrait supposerait un drapeau + purge des valeurs + écran, flux F3/F5. Référence ' . $refModeles . '.';
$absents['api:verbe:SavedViewViewSet.destroy'] =
    'GEDv2 ne supprime jamais un référentiel (règle 4). Une vue enregistrée se modifie ; un retrait supposerait un drapeau + écran de corbeille des vues, flux F5. Référence ' . $refModeles . '.';
$absents['api:verbe:UserViewSet.destroy'] =
    'GEDv2 ne supprime jamais un compte (règle 4) : la révocation des jetons et le retrait de la matrice des rôles existent, pas la suppression. '
    . 'Un `destroy` supposerait une désactivation de compte tracée, flux F10. Référence src/paperless/views.py.';
$absents['api:verbe:GroupViewSet.destroy'] =
    'GEDv2 ne supprime jamais un groupe (règle 4) : un groupe se vide. Un `destroy` supposerait une corbeille de groupes, flux F10. Référence src/paperless/views.py.';
foreach (['WorkflowTriggerViewSet', 'WorkflowActionViewSet'] as $vue) {
    $absents['api:verbe:' . $vue . '.destroy'] =
        'GEDv2 ne supprime jamais une définition de workflow : un déclencheur ou une action se retire en réenregistrant la définition (désactivation) ; '
        . 'un `destroy` unitaire supposerait une corbeille de workflows, flux F6. Référence ' . $refModeles . '.';
}

// Configuration applicative : écritures non portées.
foreach (['update', 'partial_update', 'destroy'] as $verbe) {
    $absents['api:verbe:ApplicationConfigurationViewSet.' . $verbe] =
        'La configuration applicative de GEDv2 est lue depuis le `.env` (lecture seule via `GET /api/v1/configuration`) ; `POST` répond 405 comme Paperless. '
        . 'Un `' . $verbe . '` supposerait un registre global inscriptible (clé → valeur) + écran + trace d’audit, flux F12. Proposition au juge. Référence src/paperless/views.py.';
}

// ---------------------------------------------------------------------------
// 4. Application, en ne touchant QUE les ids du lot.
// ---------------------------------------------------------------------------

$liste = $racine . '/docs/lots/ged-parite-api-4-elements.txt';
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

$manquants = array_diff_key($idsLot, array_intersect_key($elements, $idsLot));
$declarees = array_fill_keys(array_merge(array_keys($couverts), array_keys($autrement), array_keys($absents)), true);
$nonAssignes = array_diff_key($idsLot, $declarees);
if ($nonAssignes !== []) {
    fwrite(STDERR, "ids du lot NON assignés par ce correcteur : " . implode(', ', array_keys($nonAssignes)) . "\n");
    exit(1);
}
if (count($declarees) !== count($idsLot)) {
    fwrite(STDERR, "le correcteur déclare " . count($declarees) . " ids pour " . count($idsLot) . " attendus\n");
    exit(1);
}
if ($manquants !== []) {
    fwrite(STDERR, "ids du lot sans statut : " . implode(', ', array_keys($manquants)) . "\n");
    exit(1);
}

$document['elements'] = $elements;
file_put_contents($chemin, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("OK: %d elements api-4 declares (couverts %d, couvert-autrement %d, absents %d)\n",
    $traites, count($couverts), count($autrement), count($absents));
