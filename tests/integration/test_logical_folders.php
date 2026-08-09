<?php
/**
 * Oracle du secteur recherche-transverse — les vues dynamiques.
 *
 * C'est l'equivalent des vues M-Files sur un stockage disque : un dossier
 * « Factures » rassemble toutes les factures ou qu'elles soient, sans deplacer
 * un fichier. L'infrastructure existe depuis longtemps (logical_folders porte
 * filter_type et filter_config) et elle est cablee — LogicalFolder est appele
 * par DocumentsApiController, DocumentsController et templates/documents/index.php.
 * Elle n'avait simplement aucun oracle : le secteur pouvait casser sans que
 * rien ne rougisse.
 *
 * Cette sonde execute le chemin reel contre la base, elle ne le simule pas.
 *
 * Usage: php tests/integration/test_logical_folders.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Models\LogicalFolder;

echo "\n";
echo "+==============================================================+\n";
echo "|        K-DOCS - VUES DYNAMIQUES (dossiers filtres)           |\n";
echo "+==============================================================+\n\n";

$db = Database::getInstance();
$passed = 0;
$failed = 0;

function test(string $name, bool $condition, string $detail = ''): bool {
    global $passed, $failed;
    echo $condition ? "\033[32m[OK]\033[0m $name" : "\033[31m[KO]\033[0m $name";
    $condition ? $passed++ : $failed++;
    if ($detail !== '') echo " - $detail";
    echo "\n";
    return $condition;
}

// ---------------------------------------------------------------------------
// 1. Le registre des vues
// ---------------------------------------------------------------------------
echo "--- 1. REGISTRE DES VUES ---\n\n";

$folders = LogicalFolder::getAll();
test('Au moins une vue dynamique declaree', count($folders) > 0, count($folders) . ' vue(s)');

$parType = [];
foreach ($folders as $f) {
    $parType[$f['filter_type']] = ($parType[$f['filter_type']] ?? 0) + 1;
}
foreach ($parType as $t => $n) {
    echo "    $t : $n\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 2. Un filtre par type rend exactement ce qu'il annonce
// ---------------------------------------------------------------------------
echo "--- 2. FILTRE PAR TYPE DE DOCUMENT ---\n\n";

$vueType = null;
foreach ($folders as $f) {
    if ($f['filter_type'] === 'document_type') { $vueType = $f; break; }
}

if ($vueType === null) {
    test('Une vue filtrant par type existe', false, 'aucune vue document_type dans logical_folders');
} else {
    $cfg  = json_decode($vueType['filter_config'] ?? '{}', true);
    $code = $cfg['document_type_code'] ?? null;
    $tid  = $cfg['document_type_id'] ?? null;

    if ($tid === null && $code !== null) {
        $st = $db->prepare('SELECT id FROM document_types WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $tid = $st->fetchColumn() ?: null;
    }

    test(
        "La vue « {$vueType['name']} » resout son type",
        $tid !== null,
        $tid !== null ? "type_id=$tid" : "code '$code' introuvable dans document_types"
    );

    if ($tid !== null) {
        $docs = LogicalFolder::getDocuments((int) $vueType['id'], 500, 0);

        // Le controle qui compte : AUCUN document d'un autre type ne doit entrer.
        $intrus = [];
        foreach ($docs as $d) {
            if ((int) ($d['document_type_id'] ?? 0) !== (int) $tid) {
                $intrus[] = $d['id'] . ' (type ' . ($d['document_type_id'] ?? 'null') . ')';
            }
        }
        test(
            'Aucun document d un autre type dans la vue',
            $intrus === [],
            $intrus === [] ? count($docs) . ' document(s), tous du bon type'
                           : count($intrus) . ' intrus : ' . implode(', ', array_slice($intrus, 0, 5))
        );

        // Reciproque : la vue ne perd rien. Elle doit ramener tout ce qui existe.
        $st = $db->prepare(
            "SELECT COUNT(*) FROM documents
             WHERE document_type_id = ? AND deleted_at IS NULL
               AND (status IS NULL OR status != 'pending')"
        );
        $st->execute([$tid]);
        $attendu = (int) $st->fetchColumn();

        test(
            'La vue ne perd aucun document de son type',
            count($docs) === $attendu,
            'vue=' . count($docs) . ' attendu=' . $attendu
        );

        // countDocuments() doit dire la meme chose que getDocuments().
        test(
            'Le compteur de la vue concorde avec son contenu',
            LogicalFolder::countDocuments((int) $vueType['id']) === $attendu,
            'compteur=' . LogicalFolder::countDocuments((int) $vueType['id']) . ' attendu=' . $attendu
        );
    }
}

// ---------------------------------------------------------------------------
// 3. La vue « tout » ne filtre rien, mais exclut corbeille et brouillons
// ---------------------------------------------------------------------------
echo "\n--- 3. VUE FILESYSTEM (tous les documents) ---\n\n";

$vueTout = null;
foreach ($folders as $f) {
    if ($f['filter_type'] === 'filesystem') { $vueTout = $f; break; }
}

if ($vueTout === null) {
    test('Une vue filesystem existe', false, 'aucune vue filesystem declaree');
} else {
    $attendu = (int) $db->query(
        "SELECT COUNT(*) FROM documents
         WHERE deleted_at IS NULL AND (status IS NULL OR status != 'pending')"
    )->fetchColumn();

    test(
        'La vue « tout » compte tous les documents visibles',
        LogicalFolder::countDocuments((int) $vueTout['id']) === $attendu,
        'vue=' . LogicalFolder::countDocuments((int) $vueTout['id']) . ' attendu=' . $attendu
    );

    // Invariant du depot : un document en corbeille n'apparait dans aucune vue.
    $enCorbeille = (int) $db->query('SELECT COUNT(*) FROM documents WHERE deleted_at IS NOT NULL')->fetchColumn();
    $total       = (int) $db->query('SELECT COUNT(*) FROM documents')->fetchColumn();

    test(
        'Les documents en corbeille restent hors des vues',
        LogicalFolder::countDocuments((int) $vueTout['id']) <= $total - $enCorbeille,
        "$enCorbeille en corbeille sur $total, jamais listes"
    );
}

// ---------------------------------------------------------------------------
// 4. Une vue inexistante ne casse rien
// ---------------------------------------------------------------------------
echo "\n--- 4. ROBUSTESSE ---\n\n";

test('Une vue inexistante rend une liste vide, sans erreur', LogicalFolder::getDocuments(999999, 10, 0) === []);
test('Le compteur d une vue inexistante vaut 0', LogicalFolder::countDocuments(999999) === 0);

// ---------------------------------------------------------------------------
echo "\n";
echo "==============================================================\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo "==============================================================\n";

if ($failed > 0) {
    echo "\n\033[31mDes tests ont echoue.\033[0m\n";
    exit(1);
}

echo "\n\033[32mTous les tests passent!\033[0m\n";
exit(0);
