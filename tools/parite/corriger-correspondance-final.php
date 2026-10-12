<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-final-1.
 *
 * Additif, comme les correcteurs api-* : il ne touche QUE les 36 ids listés dans
 * docs/lots/final-porter.txt et laisse intacts les couverts des lots précédents. Chaque élément devient
 * `couvert` (nouveau code + preuve PHPUnit rejouée) ou `couvert-autrement` (équivalent existant + preuve).
 *
 * Les 19 exclusions django-allauth/SSO (docs/lots/final-exclure-sso.txt) ne sont PAS touchées : elles
 * restent `absent` et sont posées en `exclu` par le JUGE (décision d'Olivier du 12.10.2026).
 *
 * Usage : php tools/parite/corriger-correspondance-final.php
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

// Les noms de classes PHPUnit sont construits avec chr(92) : aucune ambiguïté d'échappement de l'antislash.
$bs = chr(92);
$classeParite = 'Ged' . $bs . 'Tests' . $bs . 'Parite' . $bs;
$classeWorkflows = 'Ged' . $bs . 'Tests' . $bs . 'Workflows' . $bs;

$pvVerbes = $classeParite . 'FinalVerbesTest::testRejoueLesVecteursDesVerbesRestants';
$pvRetraits = $classeParite . 'FinalVerbesTest::testLesRetraitsDesReferentielsSontDesMisesEnVeille';
$pvConfig = $classeParite . 'ApiQuatreAppConfigTest::testRejoueLesVecteursDeConfigurationEtDImages';
$pvFiltres = $classeParite . 'FinalModeleTest::testRejoueLesVecteursDeLEvolutionDeModele';
$pvFiche = $classeParite . 'FinalModeleTest::testLaFicheExposeLesChampsPaperlessDuDocument';
$pvWorkflows = $classeWorkflows . 'ModuleTest::testLAdminCreeEditeActiveEtMontreLHistorique';
$pvTikaMeta = $classeParite . 'ChaineParseursTest::testMetadonneesDunePiece';
$pvTikaVignette = $classeParite . 'ChaineParseursTest::testVignetteDelegueALapercuSansEchecMuet';
$pvTikaTexte = $classeParite . 'ChaineParseursTest::testLitUnTexteLocalEtRemplaceLesOctetsInvalides';
$pvDecrypt = $classeParite . 'FinalCommandesTest::testLeDechiffrementNommeLOutilAbsentPuisDechiffreAvecUnOutilExterne';
$pvPurge = $classeParite . 'FinalCommandesTest::testLaPurgeDuJournalConserveLesEntreesRecentEtLesIllisibles';
$pvTaches = $classeParite . 'FinalCommandesTest::testLAcquittementDesTachesEstReserveAuxAdministrateursEtLeStatutEstUnSse';

/** @var array<string, array{0:array<string,mixed>, 1:array<string,string>, 2:string}> $couverts */
$couverts = [];

// --- Verbes REST : correspondants (émetteurs) ------------------------------------------------------
$couverts['api:verbe:CorrespondentViewSet.create'] = [route('POST', '/api/v1/emetteurs'), p($pvVerbes),
    'Registre des émetteurs dans le fonds (`.ged/emetteurs.json`), servi par `Socle\Emetteurs`.'];
$couverts['api:verbe:CorrespondentViewSet.retrieve'] = [route('GET', '/api/v1/emetteurs/{id:[0-9]+}'), p($pvVerbes), 'Idem : fiche d’un émetteur du registre.'];
$couverts['api:verbe:CorrespondentViewSet.update'] = [route('PUT', '/api/v1/emetteurs/{id:[0-9]+}'), p($pvVerbes), 'Idem : remplacement (nom + règle d’appariement).'];
$couverts['api:verbe:CorrespondentViewSet.partial_update'] = [route('PATCH', '/api/v1/emetteurs/{id:[0-9]+}'), p($pvVerbes), 'Idem : modification partielle.'];
$couverts['api:verbe:CorrespondentViewSet.destroy'] = [route('DELETE', '/api/v1/emetteurs/{id:[0-9]+}'), p($pvVerbes),
    'GEDv2 ne supprime pas un référentiel (règle 4) : le retrait est une mise en veille (200), écart assumé mesuré.'];

// --- Verbes REST : chemins de stockage (emplacements) ----------------------------------------------
$couverts['api:verbe:StoragePathViewSet.create'] = [route('POST', '/api/v1/emplacements'), p($pvVerbes),
    'Registre des emplacements de classement (`.ged/emplacements.json`), servi par `Socle\Emplacements`.'];
$couverts['api:verbe:StoragePathViewSet.retrieve'] = [route('GET', '/api/v1/emplacements/{id:[0-9]+}'), p($pvVerbes), 'Idem : fiche d’un emplacement.'];
$couverts['api:verbe:StoragePathViewSet.update'] = [route('PUT', '/api/v1/emplacements/{id:[0-9]+}'), p($pvVerbes), 'Idem : remplacement (nom + gabarit de chemin).'];
$couverts['api:verbe:StoragePathViewSet.partial_update'] = [route('PATCH', '/api/v1/emplacements/{id:[0-9]+}'), p($pvVerbes), 'Idem : modification partielle.'];
$couverts['api:verbe:StoragePathViewSet.destroy'] = [route('DELETE', '/api/v1/emplacements/{id:[0-9]+}'), p($pvVerbes),
    'Retrait = mise en veille (200), écart assumé vs la suppression Paperless (204).'];

// --- Verbes REST : configuration applicative inscriptible ------------------------------------------
$couverts['api:verbe:ApplicationConfigurationViewSet.update'] = [route('PUT', '/api/v1/configuration'), p($pvConfig),
    'Configuration applicative inscriptible (surcharge du fonds `.ged/configuration.json`, `Socle\ConfigurationApp`).'];
$couverts['api:verbe:ApplicationConfigurationViewSet.partial_update'] = [route('PATCH', '/api/v1/configuration'), p($pvConfig), 'Idem : modification partielle (titre, langue).'];
$couverts['api:verbe:ApplicationConfigurationViewSet.destroy'] = [route('DELETE', '/api/v1/configuration'), p($pvConfig),
    'Retrait de la surcharge (retour au `.env`), 200 au lieu de 204 : écart assumé, zéro suppression de donnée.'];

// --- Retraits de référentiels (mise en veille) -----------------------------------------------------
$couverts['api:verbe:TagViewSet.destroy'] = [route('DELETE', '/api/v1/etiquettes/{id:[0-9]+}'), p($pvRetraits),
    'Mise en veille d’une étiquette (`Socle\Etiquettes::definirActif(false)`) : elle n’est plus proposée, reste lisible.'];
$couverts['api:verbe:CustomFieldViewSet.destroy'] = [route('DELETE', '/api/v1/champs/{id:[0-9]+}'), p($pvRetraits),
    'Mise en veille d’un champ ; ses valeurs restent dans les `.meta.json`.'];
$couverts['api:verbe:SavedViewViewSet.destroy'] = [route('DELETE', '/api/v1/vues/{id:[0-9]+}'), p($pvRetraits),
    'Mise en veille d’une vue : elle quitte la barre latérale, reste relisible.'];
$couverts['api:verbe:UserViewSet.destroy'] = [route('DELETE', '/api/v1/comptes/{id:[0-9]+}'), p($pvRetraits),
    'Désactivation d’un compte (`comptes.actif = 0`) : plus de connexion, traces conservées (zéro suppression).'];
$couverts['api:verbe:GroupViewSet.destroy'] = [route('DELETE', '/api/v1/groupes/{id:[0-9]+}'), p($pvRetraits),
    'Désactivation d’un groupe (`securite_groupes.actif = 0`) ; ses règles de dossier restent lisibles.'];
$couverts['api:verbe:WorkflowActionViewSet.destroy'] = [service('Ged' . $bs . 'Workflows' . $bs . 'DepotWorkflows'), p($pvWorkflows),
    'GEDv2 ne supprime pas une définition : la mise en veille du workflow (`basculer`) est le retrait.'];
$couverts['api:verbe:WorkflowTriggerViewSet.destroy'] = [service('Ged' . $bs . 'Workflows' . $bs . 'DepotWorkflows'), p($pvWorkflows),
    'Idem : déclencheur retiré en réenregistrant/en veille la définition.'];

// --- Filtres de documents / d’étiquettes -----------------------------------------------------------
$couverts['api:filtre:DocumentFilterSet.owner'] = [route('GET', '/api/v1/documents'), p($pvFiltres),
    'Filtre `owner` (identifiant ou nom de compte) sur `documents.proprietaire_id`.'];
$couverts['api:filtre:DocumentFilterSet.owner__id'] = [route('GET', '/api/v1/documents'), p($pvFiltres), 'Filtre `owner__id` (identifiant de compte ou nom).'];
$couverts['api:filtre:DocumentFilterSet.owner__id__none'] = [route('GET', '/api/v1/documents'), p($pvFiltres), 'Filtre `owner__id__none` : documents sans propriétaire.'];
$couverts['api:filtre:DocumentFilterSet.original_filename'] = [route('GET', '/api/v1/documents'), p($pvFiltres),
    'Filtre `original_filename` : meta.nom_origine, nom public, jointure `ingestion_travaux.nom_origine`.'];
$couverts['api:filtre:DocumentFilterSet.custom_field_query'] = [route('GET', '/api/v1/documents'), p($pvFiltres),
    'DSL JSON porté par `Socle\RequeteChamps` (atomes + AND/OR/NOT, bornes profondeur/atomes, refus 400 nommé).'];
$couverts['api:filtre:TagFilterSet.is_root'] = [route('GET', '/api/v1/etiquettes'), p($pvFiltres),
    'Hiérarchie d’étiquettes (`parent`), filtre `is_root` : racine = parente nulle, hors registre = racine.'];

// --- Parseurs bureautiques (Tika) : couvert autrement par la chaîne d’ingestion --------------------
$couverts['chaine:parseur:TikaDocumentParser.extract_metadata'] = [service('Ged' . $bs . 'Ingestion' . $bs . 'ParseurPiece'), p($pvTikaMeta),
    '`ParseurPiece::metadonnees` rend mime/famille/pages/texte_lu : la famille bureautique (docx/xlsx/odt/rtf) est convertie (LibreOffice) avant OCR, équivalent Tika.'];
$couverts['chaine:parseur:TikaDocumentParser.get_thumbnail'] = [service('Ged' . $bs . 'Ingestion' . $bs . 'ParseurPiece'), p($pvTikaVignette),
    '`ParseurPiece::vignette` délègue à `Apercu` (vraie vignette, message nommé sinon).'];
$couverts['chaine:parseur:TikaDocumentParser.parse'] = [service('Ged' . $bs . 'Ingestion' . $bs . 'ParseurPiece'), p($pvTikaTexte),
    '`ParseurPiece::texte` lit le texte (UTF-8 réparé) ; la famille bureautique suit la même chaîne.'];

// --- Commandes -------------------------------------------------------------------------------------
$couverts['commande:documents:decrypt_documents'] = [service('Ged' . $bs . 'Ingestion' . $bs . 'DechiffreurPdf'), p($pvDecrypt),
    'Outil CLI `tools/decrypter-documents.php` + service : outil externe (`PDF_DECHIFFREUR`), refus nommé s’il est absent.'];
$couverts['commande:documents:prune_audit_logs'] = [service('Ged' . $bs . 'Securite' . $bs . 'PurgeAudit'), p($pvPurge),
    'Outil CLI `tools/purger-journal-audit.php` + `PurgeAudit` : rétention, ligne illisible conservée, écriture atomique.'];

// --- Champs Paperless du Document ------------------------------------------------------------------
$couverts['modele:champ:Document.archive_serial_number'] = [route('GET', '/api/v1/documents/{id:[0-9]+}'), p($pvFiche),
    '`archive_serial_number` exposé par la fiche (meta.asn) et filtrable (`asn`).'];
$couverts['modele:champ:Document.page_count'] = [route('GET', '/api/v1/documents/{id:[0-9]+}'), p($pvFiche),
    '`page_count` compté sur le fichier réel (`Ingestion\ParseurPiece`), jamais inventé.'];
$couverts['modele:champ:Document.storage_type'] = [route('GET', '/api/v1/documents/{id:[0-9]+}'), p($pvFiche),
    '`storage_type` = `unencrypted` : GEDv2 stocke le fichier lisible, jamais chiffré par le produit.'];

// --- Droit d’acquittement des tâches ---------------------------------------------------------------
$couverts['permissions:classe:AcknowledgeTasksPermissions'] = [service('Ged' . $bs . 'Securite' . $bs . 'PermissionsTaches'), p($pvTaches),
    'Droit rattaché à la file `ingestion_travaux` (`Socle\Taches`) : administrateur, ou permission fine `taches:acquitter` ; un gestionnaire est refusé 403.'];

// --- Statut temps réel (WebSocket → SSE léger) -----------------------------------------------------
$couverts['api:route:anon:ws-status-statusconsumer-as-asgi'] = [route('GET', '/api/v1/taches/statut/evenements'), p($pvTaches),
    'Pas de WebSocket en mutualisé : équivalent fonctionnel LÉGER, un événement SSE `statut` (état réel des tâches) puis fermeture — un appel court, sans Redis.'];
$statutsAutrement = ['api:route:anon:ws-status-statusconsumer-as-asgi',
    'chaine:parseur:TikaDocumentParser.extract_metadata', 'chaine:parseur:TikaDocumentParser.get_thumbnail',
    'chaine:parseur:TikaDocumentParser.parse'];

// ---------------------------------------------------------------------------------------------------
$liste = $racine . '/docs/lots/final-porter.txt';
$brut = (string) file_get_contents($liste);
$ids = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $brut))), static fn (string $l): bool => $l !== ''));
$idsLot = array_fill_keys($ids, true);

$inconnus = [];
$traites = 0;
foreach ($couverts as $id => [$gedv2, $preuve, $note]) {
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    $statut = in_array($id, $statutsAutrement, true) ? 'couvert-autrement' : 'couvert';
    $elements[$id] = ['statut' => $statut, 'gedv2' => $gedv2, 'preuve' => $preuve, 'note' => $note];
    ++$traites;
}

if ($inconnus !== []) {
    fwrite(STDERR, "ids absents de l'inventaire : " . implode(', ', $inconnus) . "\n");
    exit(1);
}
$nonAssignes = array_diff_key($idsLot, $couverts);
if ($nonAssignes !== []) {
    fwrite(STDERR, "ids du lot NON assignés : " . implode(', ', array_keys($nonAssignes)) . "\n");
    exit(1);
}
if (count($couverts) !== count($idsLot)) {
    fwrite(STDERR, 'le correcteur déclare ' . count($couverts) . ' ids pour ' . count($idsLot) . " attendus\n");
    exit(1);
}

$document['elements'] = $elements;
file_put_contents($chemin, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("OK: %d elements final-1 declares (couverts %d, couvert-autrement %d)\n",
    $traites, $traites - count($statutsAutrement), count($statutsAutrement));
