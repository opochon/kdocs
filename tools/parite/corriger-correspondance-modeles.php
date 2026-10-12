<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-modele-1.
 *
 * Le curateur global (`corriger-correspondance.php`) reconstruit tout le fichier et ne connaît ni les
 * éléments `chaine` (lot ged-parite-chaine-1) ni les éléments `api` (lot ged-parite-api-1) : le rejouer
 * les reclasserait « absent ». Ce correcteur ADDITIF ne touche que les éléments `modele` de la famille
 * WORKFLOWS (Workflow, WorkflowTrigger, WorkflowAction et ses variantes, WorkflowRun, ScheduleDateField)
 * et laisse tout le reste intact.
 *
 * Chaque élément est marqué `couvert-autrement` avec le service GEDv2 qui le porte et une preuve PHPUnit
 * rejouée au dernier passage. Les énumérations de la famille COURRIER (TitleSource, ConsumptionScope,
 * CorrespondentSource — qui appartiennent à MailRule) ne sont PAS touchées : lot suivant.
 *
 * Usage : php tools/parite/corriger-correspondance-modeles.php
 */

$racine = dirname(__DIR__, 2);
$chemin = $racine . '/docs/parite/correspondance.json';
$inventaire = json_decode((string) file_get_contents($racine . '/docs/parite/inventaire-paperless.json'), true, 512, JSON_THROW_ON_ERROR);
$document = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($document) || !is_array($document['elements'] ?? null) || !is_array($inventaire['elements'] ?? null)) {
    fwrite(STDERR, "correspondance.json ou inventaire illisible\n");
    exit(1);
}

/** @var array<string, array<string, mixed>> $elements */
$elements = $document['elements'];

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

$declencheur = 'Ged\\Workflows\\Declencheur';
$action = 'Ged\\Workflows\\Action';
$depot = 'Ged\\Workflows\\DepotWorkflows';
$executions = 'Ged\\Workflows\\Executions';
$testsDeclencheur = 'Ged\\Tests\\Workflows\\DeclencheurTest';
$testsAction = 'Ged\\Tests\\Workflows\\ActionTest';
$testsApplicateur = 'Ged\\Tests\\Workflows\\ApplicateurTest';

/**
 * Preuve et service pour un élément `modele` de la famille WORKFLOWS.
 *
 * @return array{0:array{type:string, classe:string}, 1:array{type:string, cible:string}, 2:string}|null
 */
function declaration(string $id): ?array
{
    global $declencheur, $action, $depot, $executions, $testsDeclencheur, $testsAction, $testsApplicateur;
    $nom = substr($id, strrpos($id, ':') + 1);

    if (str_starts_with($id, 'modele:classe:')) {
        return match ($nom) {
            'Workflow', 'WorkflowTrigger' => [service($depot), p($testsApplicateur . '::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees'), ''],
            'WorkflowAction', 'WorkflowActionEmail', 'WorkflowActionWebhook' => [service($action), p($testsAction . '::testChaqueChampDAffectationEstApplique'), ''],
            'WorkflowRun' => [service($executions), p($testsApplicateur . '::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution'), ''],
            default => null,
        };
    }

    if (str_starts_with($id, 'modele:type:WorkflowTriggerType.')
        || str_starts_with($id, 'modele:type:WorkflowTriggerMatching.')) {
        return [service($declencheur), p($testsDeclencheur . '::testLesEnumerationsSontCellesDuModelePaperless'), ''];
    }
    if (str_starts_with($id, 'modele:type:WorkflowActionType.')) {
        return [service($action), p($testsAction . '::testLesEnumerationsDeActionSontCellesDuModele'), ''];
    }
    if (str_starts_with($id, 'modele:type:ScheduleDateField.')) {
        return [service($declencheur), p($testsDeclencheur . '::testLaPlanificationSurLeChampDeDateEtLeDecalage'), ''];
    }

    if (!str_starts_with($id, 'modele:champ:Workflow')) {
        return null;
    }

    if (str_starts_with($id, 'modele:champ:WorkflowActionEmail.')) {
        return [service($action), p($testsAction . '::testLesChampsCourrielDeWorkflowActionEmail'), ''];
    }
    if (str_starts_with($id, 'modele:champ:WorkflowActionWebhook.')) {
        return [service($action), p($testsAction . '::testLesChampsWebhookDeWorkflowActionWebhook'), ''];
    }
    if (str_starts_with($id, 'modele:champ:WorkflowRun.')) {
        return [service($executions), p($testsApplicateur . '::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution'), ''];
    }
    if (str_starts_with($id, 'modele:champ:WorkflowAction.')) {
        if ($nom === 'type') {
            return [service($action), p($testsAction . '::testLesEnumerationsDeActionSontCellesDuModele'), ''];
        }
        if ($nom === 'order') {
            return [service($action), p($testsAction . '::testLOrdreDesActionsEstPorte'), ''];
        }
        if ($nom === 'email') {
            return [service($action), p($testsAction . '::testLesChampsCourrielDeWorkflowActionEmail'), ''];
        }
        if ($nom === 'webhook') {
            return [service($action), p($testsAction . '::testLesChampsWebhookDeWorkflowActionWebhook'), ''];
        }
        if (str_starts_with($nom, 'assign_')) {
            return [service($action), p($testsAction . '::testChaqueChampDAffectationEstApplique'), ''];
        }

        return [service($action), p($testsAction . '::testChaqueChampDeRetraitEstApplique'), ''];
    }
    if (str_starts_with($id, 'modele:champ:WorkflowTrigger.')) {
        return match (true) {
            in_array($nom, ['match', 'matching_algorithm', 'is_insensitive', 'filter_filename'], true)
                => [service($declencheur), p($testsDeclencheur . '::testLesModesDeCorrespondanceDeContenu'), ''],
            in_array($nom, ['filter_has_tags', 'filter_has_all_tags', 'filter_has_not_tags', 'filter_has_correspondent',
                'filter_has_not_correspondents', 'filter_has_document_type', 'filter_has_not_document_types',
                'filter_has_storage_path', 'filter_has_not_storage_paths'], true)
                => [service($declencheur), p($testsDeclencheur . '::testLesFiltresDExistenceSurUnDocumentExistant'), ''],
            $nom === 'filter_custom_field_query'
                => [service($declencheur), p($testsDeclencheur . '::testLaRequeteDeChampsPersonnalises'), ''],
            in_array($nom, ['schedule_is_recurring', 'schedule_recurring_interval_days'], true)
                => [service($declencheur), p($testsDeclencheur . '::testLaRecurrenceRespecteLaDerniereExecution'), ''],
            str_starts_with($nom, 'schedule_')
                => [service($declencheur), p($testsDeclencheur . '::testLaPlanificationSurLeChampDeDateEtLeDecalage'), ''],
            default
                => [service($declencheur), p($testsDeclencheur . '::testLesFiltresDeConsommation'), ''],
        };
    }
    if (str_starts_with($id, 'modele:champ:Workflow.')) {
        return [service($depot), p($testsApplicateur . '::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees'), ''];
    }

    return null;
}

$declares = 0;
$inconnus = [];
foreach ($inventaire['elements'] as $element) {
    $id = (string) ($element['id'] ?? '');
    $declaration = declaration($id);
    if ($declaration === null) {
        continue;
    }
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    [$gedv2, $preuve, $note] = $declaration;
    $elements[$id] = array_filter([
        'statut' => 'couvert-autrement',
        'gedv2' => $gedv2,
        'preuve' => $preuve,
        'note' => $note,
    ], static fn ($v): bool => $v !== '');
    $declares++;
}

if ($inconnus !== []) {
    fwrite(STDERR, "ids absents de la correspondance : " . implode(', ', $inconnus) . "\n");
    exit(1);
}

$document['elements'] = $elements;
file_put_contents($chemin, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("OK: %d éléments modele/workflows déclarés (le reste de la correspondance est intact)\n", $declares);
