<?php
/**
 * K-Docs — Test d'évaluation complet (gros fichiers réels + personas).
 *
 * Source  : dossier original du dépôt précédent (clearmydocs-v3 / doc analyse).
 * Couvre  : ingestion → OCR → classification → attribution IA (règles) → recherche → personas.
 *
 * Usage :
 *   php tools/eval-full.php                 # run complet
 *   php tools/eval-full.php --no-ocr        # saute l'OCR (rapide, heuristiques nom/fichier)
 *   php tools/eval-full.php --clean         # réinitialise le lot + personas avant le run
 *
 * Sécurité :
 *   - N'opère que sur le dossier relatif « eval/lot-original » (soft-delete des anciennes lignes).
 *   - Règles d'attribution créées puis supprimées (aucune pollution durable).
 *   - Personas = utilisateurs de test préfixés « eval_ » (idempotents).
 *
 * Exit code : 0 si toutes les gates passent, 1 sinon.
 */
declare(strict_types=1);

define('KDOCS_ROOT', dirname(__DIR__));
require KDOCS_ROOT . '/vendor/autoload.php';
require_once KDOCS_ROOT . '/app/helpers.php';
\KDocs\Core\Config::load();

use KDocs\Core\Database;
use KDocs\Services\FilesystemReader;
use KDocs\Services\FolderIndexerService;
use KDocs\Services\DocumentProcessor;
use KDocs\Services\Classification\IngestClassificationService;
use KDocs\Services\Attribution\AttributionService;
use KDocs\Models\AttributionRule;
use KDocs\Services\SearchService;
use KDocs\Models\Role;
use KDocs\Services\AutoClassifierService;

// ----------------------------------------------------------------------------
// Configuration
// ----------------------------------------------------------------------------

$TARGET_REL = 'eval/lot-original';

// Dossier original (précédent repository) — gros documents juridiques réels.
$SOURCES = [
    'F:\DATA\DEVELOPPEMENT\clearmydocs-v3\migv4\work_v4\01_inputs',
    'F:\DATA\DEVELOPPEMENT\clearmydocs-v3\doc analyse',
];

// Sélection de gros fichiers représentatifs (types variés). msg exclu (non supporté).
$PICKS = [
    'Courrier au Tribunal civil - envoi.pdf',
    '251014_plainte penale OPO vs VPO.pdf',
    'BILAN 2023-2024.pdf',
    'recu 28.01.26__241231_releve_Credit_agricole.pdf',
    'recu 28.01.26__250512_decision_AI.pdf',
    '141025_VPO_plainte_penale_Annexe01.docx',
    'Arrêt du 05_06_2024.pdf',
    'Demande en divorce du 15_07_2025 signée.pdf',
];

// Personas de test (métier → rôle + scope + plafond montant).
// eval_redx_expert : parcours ECM (types doc, métadonnées, classification) — pas WinBiz.
$PERSONAS = [
    'eval_secretaire' => [
        'label' => 'Secrétaire (Direction)',
        'first' => 'Sandra',
        'last'  => 'Secrétaire',
        'roles' => [
            ['VALIDATOR_L1', '*', 1000.0],
        ],
    ],
    'eval_comptable' => [
        'label' => 'Comptable',
        'first' => 'Claude',
        'last'  => 'Comptable',
        'roles' => [
            ['SAISIE_COMPTA',       'FACTURE', 5000.0],
            ['VALIDATEUR_FACTURE',  'FACTURE', 5000.0],
        ],
    ],
    'eval_rh' => [
        'label' => 'Responsable RH',
        'first' => 'Hélène',
        'last'  => 'RH',
        'roles' => [
            ['VALIDATOR_L1', 'RH',  null],
        ],
    ],
    'eval_employeur' => [
        'label' => 'Employeur / Direction',
        'first' => 'Olivier',
        'last'  => 'Directeur',
        'roles' => [
            ['APPROVER', '*', null],
        ],
    ],
    'eval_redx_expert' => [
        'label' => 'Expert ECM REDX',
        'first' => 'Renaud',
        'last'  => 'Expert',
        'roles' => [
            ['APPROVER', '*', null],
            ['VALIDATOR_L2', 'FACTURE', null],
        ],
    ],
];

// Requêtes de recherche (termes juridiques / financiers présents dans les noms + contenus).
$QUERIES = ['tribunal', 'divorce', 'plainte', 'bilan', 'credit agricole', '2024'];

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------

$opts = ['no-ocr' => false, 'clean' => false];
foreach ($argv as $a) {
    if ($a === '--no-ocr') $opts['no-ocr'] = true;
    if ($a === '--clean')  $opts['clean']  = true;
}

$timings = [];
function t(string $key): void { global $timings; $timings[$key]['start'] = microtime(true); }
function tstop(string $key): float { global $timings; $e = microtime(true) - $timings[$key]['start']; $timings[$key]['elapsed'] = $e; return $e; }
function line(string $s = ''): void { echo $s . PHP_EOL; }
function step(string $s): void { line(); line('── ' . $s . ' ──'); }
function ok(string $s): void   { echo "  \033[32m✓\033[0m $s" . PHP_EOL; }
function warn(string $s): void { echo "  \033[33m!\033[0m $s" . PHP_EOL; }
function fail(string $s): void { echo "  \033[31m✗\033[0m $s" . PHP_EOL; }
function bytes(int $n): string { return $n >= 1048576 ? round($n/1048576,1).' Mo' : round($n/1024,1).' Ko'; }

/** Types documentaires pour identification ECM (facture, note de crédit, etc.) — idempotent. */
function ensureDocumentTypes(\PDO $db): int {
    $types = [
        ['NOTE_CREDIT', 'Note de crédit'],
        ['RECU', 'Reçu'],
        ['COURRIER', 'Courrier'],
    ];
    $added = 0;
    foreach ($types as [$code, $label]) {
        $s = $db->prepare("SELECT id FROM document_types WHERE code = ? OR LOWER(label) = LOWER(?) LIMIT 1");
        $s->execute([$code, $label]);
        if (!$s->fetchColumn()) {
            $db->prepare("INSERT INTO document_types (code, label) VALUES (?, ?)")->execute([$code, $label]);
            $added++;
        }
    }
    return $added;
}

$gates = [];
function gate(string $name, bool $pass, string $detail = ''): void {
    global $gates; $gates[$name] = $pass;
    if ($pass) ok("GATE $name" . ($detail !== '' ? " — $detail" : ''));
    else       fail("GATE $name" . ($detail !== '' ? " — $detail" : ''));
}

// ----------------------------------------------------------------------------
// Setup
// ----------------------------------------------------------------------------

step('0. Initialisation');
$fs = new FilesystemReader();
$basePath = $fs->getBasePath();
$targetDir = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $TARGET_REL);
$db = Database::getInstance();
echo "  Base storage : $basePath\n";
echo "  Dossier cible : $TARGET_REL\n";
echo "  OCR : " . ($opts['no-ocr'] ? 'désactivé' : 'activé') . "\n";

if ($opts['clean']) {
    if (is_dir($targetDir)) {
        foreach (glob($targetDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }
    $prefix = $TARGET_REL . '/';
    $del = $db->prepare("UPDATE documents SET deleted_at = NOW() WHERE deleted_at IS NULL AND relative_path LIKE ? AND relative_path NOT LIKE ?");
    $del->execute([$prefix . '%', $prefix . '%/%']);
    echo "  [clean] " . $del->rowCount() . " ancienne(s) ligne(s) archivée(s)\n";
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
    fwrite(STDERR, "Impossible de créer $targetDir\n"); exit(1);
}

// ----------------------------------------------------------------------------
// Étape 1 — Ingestion (copie de gros fichiers réels)
// ----------------------------------------------------------------------------

step('1. Ingestion — gros fichiers réels (dépôt précédent)');

// Construire un index nom => chemin des sources.
$sourceIndex = [];
foreach ($SOURCES as $src) {
    if (!is_dir($src)) { warn("Source manquante : $src"); continue; }
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $src . DIRECTORY_SEPARATOR . $f;
        if (is_file($p) && !isset($sourceIndex[$f])) $sourceIndex[$f] = $p;
    }
}

$copied = 0; $totalBytes = 0; $manifest = [];
foreach ($PICKS as $name) {
    if (!isset($sourceIndex[$name])) { warn("Fichier source absent : $name"); continue; }
    $src = $sourceIndex[$name];
    $dest = $targetDir . DIRECTORY_SEPARATOR . $name;
    if (!copy($src, $dest)) { fail("Copie échouée : $name"); continue; }
    $sz = filesize($dest);
    $totalBytes += $sz; $copied++;
    $manifest[] = ['name' => $name, 'size' => $sz, 'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION))];
    ok(sprintf("%-60s %s", $name, bytes($sz)));
}
echo "  Total copié : $copied fichier(s), " . bytes($totalBytes) . "\n";

if ($copied === 0) { fail("Aucun fichier copié — abandon"); exit(1); }

t('ingest');
$indexer = new FolderIndexerService();
$indexResult = $indexer->indexFolder($TARGET_REL, false);
$ingestElapsed = tstop('ingest');
$indexed = (int)($indexResult['indexed'] ?? 0);
echo "  Indexation : indexed=$indexed total=" . ($indexResult['total'] ?? 0) . " (" . round($ingestElapsed,2) . "s)\n";
gate('G1-ingestion', $indexed >= $copied, "$indexed/$copied documents indexés");

// Lister les docs du lot en BDD.
$prefix = $TARGET_REL . '/';
$stmt = $db->prepare("SELECT id, title, original_filename, relative_path, document_type_id, amount, content
                      FROM documents WHERE deleted_at IS NULL AND relative_path LIKE ? AND relative_path NOT LIKE ?
                      ORDER BY id DESC LIMIT 50");
$stmt->execute([$prefix . '%', $prefix . '%/%']);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "  Documents BDD dans le lot : " . count($docs) . "\n";

// ----------------------------------------------------------------------------
// Étape 2 — OCR
// ----------------------------------------------------------------------------

step('2. OCR / extraction de contenu');
$processor = new DocumentProcessor();
$ocrDone = 0; $ocrErrors = 0;
if ($opts['no-ocr']) {
    warn("OCR désactivé (--no-ocr) — contenu vide, classification sur heuristiques nom/fichier");
} else {
    foreach ($docs as $doc) {
        $id = (int)$doc['id'];
        try {
            t("ocr-$id");
            $processor->processDocument($id);
            tstop("ocr-$id");
            $ocrDone++;
            $e = $timings["ocr-$id"]['elapsed'] ?? 0;
            ok(sprintf("doc #%d %-50s OCR ok (%.1fs)", $id, substr($doc['original_filename'],0,50), $e));
        } catch (\Throwable $e) {
            $ocrErrors++;
            warn("doc #{$id} OCR échec : " . $e->getMessage());
        }
    }
}
gate('G2-ocr', $opts['no-ocr'] || $ocrDone > 0, "$ocrDone OCR ok, $ocrErrors échecs");

// ----------------------------------------------------------------------------
// Étape 3 — Classification (ingest)
// ----------------------------------------------------------------------------

step('3. Classification (ingest classifier + heuristique ECM)');
$ingest = new IngestClassificationService();
$autoClassifier = new AutoClassifierService();
$classified = 0; $classErrors = 0; $typeDistribution = [];
foreach ($docs as $doc) {
    $id = (int)$doc['id'];
    try {
        $res = $ingest->classify($id);
        $classified++;
        // Oracle identification auto (Lot B) : heuristique OCR/nom de fichier → document_type_id
        $rules = $autoClassifier->classifyRules($id);
        if (!empty($rules['document_type_id'])) {
            $db->prepare(
                'UPDATE documents SET document_type_id = ?, classification_confidence = COALESCE(?, classification_confidence), updated_at = NOW() WHERE id = ?'
            )->execute([(int)$rules['document_type_id'], $rules['confidence'] ?? null, $id]);
        }
        // Recharger le type effectif
        $s = $db->prepare("SELECT d.document_type_id, dt.code, dt.label FROM documents d
                           LEFT JOIN document_types dt ON d.document_type_id = dt.id WHERE d.id = ?");
        $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC);
        $label = $row['label'] ?? '(aucun)';
        $typeDistribution[$label] = ($typeDistribution[$label] ?? 0) + 1;
        ok(sprintf("doc #%d → type=%s (rules conf=%.2f)", $id, $label, (float)($rules['confidence'] ?? 0)));
    } catch (\Throwable $e) {
        $classErrors++;
        warn("doc #{$id} classification échec : " . $e->getMessage());
    }
}
echo "  Distribution types : " . json_encode($typeDistribution, JSON_UNESCAPED_UNICODE) . "\n";
gate('G3-classification', $classified > 0, "$classified/$classErrors classés");

// Gate identification auto ECM (GAP-055) — heuristique nom/OCR sur le lot eval.
$ecmLabels = ['Facture', 'Note de crédit', 'Contrat', 'Courrier', 'Reçu'];
$ecmTyped = 0;
foreach ($ecmLabels as $ecmLabel) {
    $ecmTyped += (int)($typeDistribution[$ecmLabel] ?? 0);
}
$aucunCount = (int)($typeDistribution['(aucun)'] ?? 0);
$g7Pass = $ecmTyped >= 5
    && ($typeDistribution['Courrier'] ?? 0) >= 1
    && ($typeDistribution['Reçu'] ?? 0) >= 2
    && ($typeDistribution['Contrat'] ?? 0) >= 1
    && $aucunCount <= 3;
gate(
    'G7-classify-distribution',
    $g7Pass,
    "ECM typés=$ecmTyped/" . count($docs) . " aucun=$aucunCount — " . json_encode($typeDistribution, JSON_UNESCAPED_UNICODE)
);

// ----------------------------------------------------------------------------
// Étape 4 — Attribution IA (règles, mode simulation)
// ----------------------------------------------------------------------------

step('4. Attribution IA — règles (simulation, sans effet de bord)');

// Créer 2 règles temporaires ciblées sur le contenu extrait.
$ruleIds = [];
$rulesCreated = 0;
try {
    $rid = AttributionRule::create([
        'name' => '[eval] Tribunal → correspondant Tribunal civil + tag juridique',
        'description' => 'Règle de test eval-full (supprimée en fin de run)',
        'priority' => 200, 'is_active' => true, 'stop_on_match' => false, 'created_by' => 1,
    ]);
    AttributionRule::addCondition($rid, ['condition_group' => 0, 'field_type' => 'content', 'operator' => 'contains', 'value' => 'tribunal']);
    AttributionRule::addAction($rid, ['action_type' => 'set_correspondent', 'field_name' => null, 'value' => 'Tribunal civil']);
    AttributionRule::addAction($rid, ['action_type' => 'add_tag', 'field_name' => null, 'value' => 'juridique']);
    $ruleIds[] = $rid; $rulesCreated++;
} catch (\Throwable $e) { warn("Création règle tribunal échec : " . $e->getMessage()); }

try {
    $rid2 = AttributionRule::create([
        'name' => '[eval] Bilan/relevé → type AUTRE + tag finance',
        'description' => 'Règle de test eval-full (supprimée en fin de run)',
        'priority' => 150, 'is_active' => true, 'stop_on_match' => false, 'created_by' => 1,
    ]);
    AttributionRule::addCondition($rid2, ['condition_group' => 0, 'field_type' => 'content', 'operator' => 'contains', 'value' => 'bilan']);
    AttributionRule::addCondition($rid2, ['condition_group' => 1, 'field_type' => 'content', 'operator' => 'contains', 'value' => 'relevé']);
    AttributionRule::addAction($rid2, ['action_type' => 'set_document_type', 'field_name' => null, 'value' => 'AUTRE']);
    AttributionRule::addAction($rid2, ['action_type' => 'add_tag', 'field_name' => null, 'value' => 'finance']);
    $ruleIds[] = $rid2; $rulesCreated++;
} catch (\Throwable $e) { warn("Création règle bilan échec : " . $e->getMessage()); }

echo "  Règles temporaires créées : $rulesCreated\n";

$attrService = new AttributionService();
$attrMatched = 0; $attrActions = 0; $attrLogs = [];
foreach ($docs as $doc) {
    $id = (int)$doc['id'];
    try {
        $res = $attrService->process($id, false); // simulation
        if (!empty($res['rules_matched'])) $attrMatched++;
        if (!empty($res['actions_planned'])) {
            $attrActions += $res['actions_planned'];
            foreach ($res['changes'] as $chg) {
                $attrLogs[] = sprintf("doc #%d → %s %s=%s",
                    $id, $chg['action_type'], $chg['field_name'] ?? '', (string)($chg['new_value'] ?? ''));
                ok("doc #{$id} attribution planifiée : {$chg['action_type']} " . ($chg['field_name'] ?? '') . "={$chg['new_value']}");
            }
        }
    } catch (\Throwable $e) {
        warn("doc #{$id} attribution échec : " . $e->getMessage());
    }
}

// Nettoyage des règles temporaires.
foreach ($ruleIds as $rid) { try { AttributionRule::delete($rid); } catch (\Throwable $e) {} }
echo "  Règles temporaires supprimées : " . count($ruleIds) . "\n";

gate('G4-attribution-rules', $rulesCreated === 2, "$rulesCreated/2 règles créées");
gate('G4-attribution-match', $attrMatched >= 1, "$attrMatched doc(s) matchés, $attrActions actions planifiées");

// ----------------------------------------------------------------------------
// Étape 5 — Recherche
// ----------------------------------------------------------------------------

step('5. Recherche (fulltext + fallback sémantique)');
$search = new SearchService();
$searchResults = [];
foreach ($QUERIES as $q) {
    t("search-$q");
    $res = $search->search($q, 25);
    $e = tstop("search-$q");
    $total = $res->total ?? 0;
    $searchResults[$q] = $total;
    $sem = !empty($res->semanticUsed) ? ' (sémantique)' : '';
    ok(sprintf("recherche « %-15s » → %d résultat(s) (%.3fs)%s", $q, $total, $e, $sem));
}
$hasResults = array_sum($searchResults) > 0;
gate('G5-recherche', $hasResults, "total hits=" . array_sum($searchResults));

// ----------------------------------------------------------------------------
// Étape 6 — Personas (rôles, périmètre, plafonds de validation)
// ----------------------------------------------------------------------------

step('6. Personas — rôles & droits de validation');

$typesAdded = ensureDocumentTypes($db);
if ($typesAdded > 0) {
    ok("$typesAdded type(s) documentaire(s) ECM ajouté(s) (Note de crédit, Reçu, Courrier)");
}

// Création / récupération idempotente des utilisateurs de test.
$personaUserIds = [];
foreach ($PERSONAS as $username => $p) {
    $s = $db->prepare("SELECT id FROM users WHERE username = ?");
    $s->execute([$username]); $uid = $s->fetchColumn();
    if (!$uid) {
        $ins = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin) VALUES (?,?,?,?,?,0)");
        $ins->execute([$username, $username . '@eval.local', '', $p['first'], $p['last']]);
        $uid = (int)$db->lastInsertId();
        echo "  Créé utilisateur $username (#$uid)\n";
    } else {
        $uid = (int)$uid;
    }
    $personaUserIds[$username] = $uid;

    // Purger les rôles précédemment assignés (idempotence) puis (ré)assigner.
    $db->prepare("DELETE ur FROM user_roles ur JOIN role_types rt ON ur.role_type_id = rt.id WHERE ur.user_id = ?")->execute([$uid]);
    foreach ($p['roles'] as $r) {
        Role::assignRole($uid, $r[0], $r[1], $r[2]);
    }
    $roles = Role::getUserRoles($uid);
    $roleNames = implode(', ', array_map(fn($x) => $x['code'] . ($x['scope'] !== '*' ? '@' . $x['scope'] : '') . ($x['max_amount'] !== null ? '≤' . $x['max_amount'] : ''), $roles));
    echo "  • {$p['label']} ($username) — rôles : $roleNames\n";
}

// Construire 2 documents représentatifs pour le test de droits :
//  - une FACTURE à 6000 CHF (au-dessus du plafond comptable 5000)
//  - un document RH
$factureDoc = null; $rhDoc = null;
foreach ($docs as $doc) {
    $s = $db->prepare("SELECT d.id, d.amount, dt.code as document_type_code FROM documents d
                       LEFT JOIN document_types dt ON d.document_type_id = dt.id WHERE d.id = ?");
    $s->execute([$doc['id']]); $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) continue;
    if (!$factureDoc && $row['document_type_code'] === 'FACTURE') $factureDoc = $row;
    if (!$rhDoc      && $row['document_type_code'] === 'RH')       $rhDoc = $row;
}

// Si aucune facture n'a été classifiée, on force un scénario synthétique.
if (!$factureDoc) {
    $factureDoc = ['id' => (int)$docs[0]['id'], 'amount' => 6000.0, 'document_type_code' => 'FACTURE'];
    // Surcharger temporairement le type pour le test (et remettre après).
    $db->prepare("UPDATE documents SET document_type_id = (SELECT id FROM document_types WHERE code='FACTURE' LIMIT 1), amount = 6000 WHERE id = ?")
       ->execute([$factureDoc['id']]);
    warn("Aucune facture classifiée — scénario synthétique sur doc #{$factureDoc['id']} (FACTURE 6000 CHF)");
}
if (!$rhDoc) {
    // Forcer un doc en RH pour le test de scope.
    $rhDoc = ['id' => (int)($docs[1]['id'] ?? $docs[0]['id']), 'amount' => 0.0, 'document_type_code' => 'RH'];
    $db->prepare("UPDATE documents SET document_type_id = (SELECT id FROM document_types WHERE code='RH' LIMIT 1) WHERE id = ?")
       ->execute([$rhDoc['id']]);
    warn("Aucun doc RH classifié — scénario synthétique sur doc #{$rhDoc['id']} (RH)");
}

echo "  Document facture test : #{$factureDoc['id']} ({$factureDoc['document_type_code']}, {$factureDoc['amount']} CHF)\n";
echo "  Document RH test      : #{$rhDoc['id']} ({$rhDoc['document_type_code']}, {$rhDoc['amount']} CHF)\n";

// Vérifier les droits de validation par persona.
$personaChecks = [];
foreach ($PERSONAS as $username => $p) {
    $uid = $personaUserIds[$username];
    $cFact = Role::canUserValidateDocument($uid, $factureDoc);
    $cRh   = Role::canUserValidateDocument($uid, $rhDoc);
    $personaChecks[$username] = ['facture' => $cFact, 'rh' => $cRh];
    echo "  • {$p['label']} :\n";
    echo "      facture 6000 CHF : " . ($cFact['can_validate'] ? 'PEUT valider' : 'bloqué — ' . ($cFact['reason'] ?? '?')) . "\n";
    echo "      RH              : " . ($cRh['can_validate'] ? 'PEUT valider' : 'bloqué — ' . ($cRh['reason'] ?? '?')) . "\n";
}

// Attentes métier :
//  - comptable (plafond 5000 sur FACTURE) → bloqué sur facture 6000 (dépassement)
//  - employeur (APPROVER, scope *, pas de plafond) → peut valider facture ET RH
//  - RH (scope RH) → peut valider RH, bloqué sur facture (scope)
//  - secrétaire (VALIDATOR_L1 *, plafond 1000) → bloqué sur facture 6000 (dépassement)
$expComptableBloque = !$personaChecks['eval_comptable']['facture']['can_validate'];
$expEmployeurTout   =  $personaChecks['eval_employeur']['facture']['can_validate']
                     && $personaChecks['eval_employeur']['rh']['can_validate'];
$expRhOkRhSeulement =  $personaChecks['eval_rh']['rh']['can_validate']
                     && !$personaChecks['eval_rh']['facture']['can_validate'];
$expSecretBloque    = !$personaChecks['eval_secretaire']['facture']['can_validate'];

gate('G6-persona-comptable',  $expComptableBloque,  'comptable bloqué > plafond 5000 sur facture 6000');
gate('G6-persona-employeur',  $expEmployeurTout,    'employeur (APPROVER) valide facture + RH');
gate('G6-persona-rh',         $expRhOkRhSeulement,  'RH valide RH, bloqué sur facture (scope)');
gate('G6-persona-secretaire', $expSecretBloque,     'secrétaire bloquée > plafond 1000 sur facture 6000');

// Expert REDX : même pouvoir validation qu'employeur sur facture + RH (métadonnées ECM).
$expRedxExpert = $personaChecks['eval_redx_expert']['facture']['can_validate']
              && $personaChecks['eval_redx_expert']['rh']['can_validate'];
gate('G6-persona-redx-expert', $expRedxExpert, 'expert REDX (APPROVER) valide facture + RH — parcours ECM');

// Types documentaires identifiables (oracle identification — prérequis avant WinBiz plugin).
$typeLabels = $db->query("SELECT label FROM document_types ORDER BY label")->fetchAll(PDO::FETCH_COLUMN);
$requiredTypes = ['Facture', 'Note de crédit', 'Contrat', 'Courrier', 'Reçu'];
$missingTypes = array_diff($requiredTypes, $typeLabels);
gate('G6-doc-types-ecm', empty($missingTypes), 'types ECM présents : ' . implode(', ', $requiredTypes)
    . (empty($missingTypes) ? '' : ' — manquants : ' . implode(', ', $missingTypes)));

// Restaurer le type du doc synthétique facture si créé artificiellement (on garde la modif : c'est du test data).
// (Pas de restauration : les docs du lot eval sont jetables.)

// ----------------------------------------------------------------------------
// Bilan
// ----------------------------------------------------------------------------

step('BILAN');
$allPass = true;
foreach ($gates as $name => $pass) {
    echo $pass ? "  \033[32m✓\033[0m $name\n" : "  \033[31m✗\033[0m $name\n";
    if (!$pass) $allPass = false;
}
line();
echo "Résumé :\n";
echo "  Ingestion    : $copied fichiers réels (" . bytes($totalBytes) . "), $indexed indexés\n";
echo "  OCR          : $ocrDone ok / $ocrErrors échecs\n";
echo "  Classification : $classified docs — " . json_encode($typeDistribution, JSON_UNESCAPED_UNICODE) . "\n";
echo "  Attribution  : $attrMatched doc(s) matchés, $attrActions actions planifiées (simulation)\n";
echo "  Recherche    : " . json_encode($searchResults, JSON_UNESCAPED_UNICODE) . "\n";
echo "  Personas     : " . count($PERSONAS) . " créés/testés\n";
line();
echo $allPass ? "\033[32mRÉSULTAT : toutes les gates PASS\033[0m\n" : "\033[31mRÉSULTAT : gates en échec\033[0m\n";
exit($allPass ? 0 : 1);
