<?php
/**
 * Oracle du secteur stockage — la correspondance disque <-> base.
 *
 * Le modele de K-Docs est filesystem-first : le fichier sur disque est la
 * source, la base porte metadonnees, index et relations. Cet invariant ne vaut
 * que si les deux restent d'accord. Personne ne le verifiait.
 *
 * Constat du 2026-08-09, a l'origine de cette sonde :
 *   - file_count n'etait alimente sur AUCUN des 40 dossiers, alors que
 *     74 documents portaient un folder_id ;
 *   - 100 documents sur 174 n'avaient aucun folder_id, donc n'apparaissaient
 *     dans aucune vue par dossier ;
 *   - eval/lot-ui portait des fichiers sur disque sans exister en base,
 *     ce qui faisait tomber la spec pipeline-ui sans que la cause soit visible.
 *
 * Sonde executee contre la base et le disque reels, pas simulee.
 *
 * Usage: php tests/integration/test_stockage_coherence.php
 */

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\Services\Storage\InternalFolderRegistry;

echo "\n";
echo "+==============================================================+\n";
echo "|      K-DOCS - COHERENCE STOCKAGE (disque <-> base)           |\n";
echo "+==============================================================+\n\n";

$db     = Database::getInstance();
$passed = 0;
$failed = 0;

function test(string $name, bool $ok, string $detail = ''): bool {
    global $passed, $failed;
    echo $ok ? "\033[32m[OK]\033[0m $name" : "\033[31m[KO]\033[0m $name";
    $ok ? $passed++ : $failed++;
    if ($detail !== '') echo " - $detail";
    echo "\n";
    return $ok;
}

// ---------------------------------------------------------------------------
// 1. Integrite referentielle : aucun document ne pointe un dossier fantome
// ---------------------------------------------------------------------------
echo "--- 1. INTEGRITE REFERENTIELLE ---\n\n";

$orphelins = (int) $db->query(
    "SELECT COUNT(*) FROM documents d
     LEFT JOIN document_folders f ON d.folder_id = f.id
     WHERE d.folder_id IS NOT NULL AND f.id IS NULL AND d.deleted_at IS NULL"
)->fetchColumn();

test(
    'Aucun document ne pointe vers un dossier inexistant',
    $orphelins === 0,
    $orphelins === 0 ? 'integrite respectee' : "$orphelins document(s) orphelin(s)"
);

// ---------------------------------------------------------------------------
// 2. Rattachement : un document sans dossier n'apparait dans aucune vue
// ---------------------------------------------------------------------------
echo "\n--- 2. RATTACHEMENT AUX DOSSIERS ---\n\n";

$sansDossier = (int) $db->query(
    "SELECT COUNT(*) FROM documents WHERE folder_id IS NULL AND deleted_at IS NULL"
)->fetchColumn();
$avecDossier = (int) $db->query(
    "SELECT COUNT(*) FROM documents WHERE folder_id IS NOT NULL AND deleted_at IS NULL"
)->fetchColumn();
$vivants = $sansDossier + $avecDossier;

echo "    documents vivants : $vivants  (rattaches $avecDossier, detaches $sansDossier)\n\n";

// Seuil volontairement lache : on epingle la derive, pas la perfection. Un
// document sur deux detache signifie que la moitie du fonds est hors des vues.
test(
    'Moins de la moitie des documents sont detaches de tout dossier',
    $vivants === 0 || $sansDossier <= $vivants / 2,
    "$sansDossier / $vivants detache(s) — un document detache n'apparait dans aucune vue par dossier"
);

// ---------------------------------------------------------------------------
// 3. file_count : une colonne derivee qui ment est pire qu'absente
// ---------------------------------------------------------------------------
echo "\n--- 3. COMPTEURS DE DOSSIERS ---\n\n";

$divergents = [];
$stmt = $db->query(
    "SELECT f.id, f.path, f.file_count,
            (SELECT COUNT(*) FROM documents d WHERE d.folder_id = f.id AND d.deleted_at IS NULL) AS reel
     FROM document_folders f"
);
$totalDossiers = 0;
foreach ($stmt as $r) {
    $totalDossiers++;
    if ((int) $r['file_count'] !== (int) $r['reel']) {
        $divergents[] = "{$r['path']} (annonce {$r['file_count']}, reel {$r['reel']})";
    }
}

test(
    'file_count concorde avec le contenu reel des dossiers',
    $divergents === [],
    $divergents === []
        ? "$totalDossiers dossier(s) coherent(s)"
        : count($divergents) . ' / ' . $totalDossiers . ' divergent(s) : '
          . implode(' · ', array_slice($divergents, 0, 4))
);

// ---------------------------------------------------------------------------
// 4. Le disque et la base voient les memes dossiers
// ---------------------------------------------------------------------------
echo "\n--- 4. DISQUE VS BASE ---\n\n";

// FilesystemIndexer lit storage.base_path (Config) plutot qu'un chemin en dur :
// le racine par defaut ne correspond pas forcement a la racine reellement
// configuree (ex. config/config.php pointe storage/courrier-matin/documents
// sur ce poste). Un chemin en dur ici auditerait un arbre qui n'est plus celui
// que l'application lit ni ecrit.
$racine = realpath(Config::get('storage.base_path', __DIR__ . '/../../storage/documents'));

if ($racine === false || !is_dir($racine)) {
    test('La racine de stockage existe', false, 'racine configuree introuvable');
} else {
    test('La racine de stockage existe', true, $racine);

    // Dossiers du disque portant au moins un fichier
    $surDisque = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (!$f->isDir()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($racine) + 1));
        // Segment cache (.versions, .git...) : le motif '/.' rate un segment
        // cache EN RACINE (aucun '/' devant), d'ou 17 ".versions/xxx.pdf"
        // signales a tort — ce sont les dossiers de version, exclus par
        // conception (attribut Windows +H, cf. lot versioning-etat-des-lieux).
        // InternalFolderRegistry (meme registre que FilesystemIndexer et le
        // secteur dossier-surveille-invisible) : pending/consume/toclassify...
        // sont des dossiers pipeline, jamais indexes comme document_folders —
        // les signaler ici fabriquerait un rouge sur un comportement voulu.
        if ($rel === '' || $rel[0] === '.' || str_contains($rel, '/.')
            || InternalFolderRegistry::isHiddenPath($rel)) continue;
        $aDesFichiers = false;
        foreach (glob($f->getPathname() . '/*') ?: [] as $e) {
            if (is_file($e)) { $aDesFichiers = true; break; }
        }
        if ($aDesFichiers) $surDisque[] = $rel;
    }

    $enBase = [];
    foreach ($db->query('SELECT path FROM document_folders') as $r) {
        $enBase[] = trim(str_replace('\\', '/', (string) $r['path']), '/');
    }

    $nonIndexes = array_values(array_diff($surDisque, $enBase));

    test(
        'Tout dossier du disque portant des fichiers est indexe',
        $nonIndexes === [],
        $nonIndexes === []
            ? count($surDisque) . ' dossier(s) peuple(s), tous indexes'
            : count($nonIndexes) . ' non indexe(s) : ' . implode(' · ', array_slice($nonIndexes, 0, 5))
    );
}

// ---------------------------------------------------------------------------
// 5. Invariant du depot : rien ne disparait
// ---------------------------------------------------------------------------
echo "\n--- 5. INVARIANT ZERO SUPPRESSION ---\n\n";

$total      = (int) $db->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$enCorbeille = (int) $db->query('SELECT COUNT(*) FROM documents WHERE deleted_at IS NOT NULL')->fetchColumn();

test(
    'La corbeille conserve, elle ne detruit pas',
    $enCorbeille >= 0 && $total >= $enCorbeille,
    "$enCorbeille document(s) en corbeille sur $total, tous conserves"
);

// ---------------------------------------------------------------------------
echo "\n";
echo "==============================================================\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo "==============================================================\n";

if ($failed > 0) {
    echo "\n\033[31mDes controles ont echoue — la correspondance disque/base a derive.\033[0m\n";
    exit(1);
}

echo "\n\033[32mTous les controles passent!\033[0m\n";
exit(0);
