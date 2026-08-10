<?php
/**
 * Instantane initial du versioning — a lancer UNE FOIS sur un fonds existant.
 *
 * Pourquoi c'est un outil separe et non une etape de l'indexation : il copie
 * l'integralite du fonds documentaire dans les sous-dossiers `.versions/`.
 * C'est le prix du versioning sur un stockage accessible en filesystem, et
 * personne ne doit le payer par surprise au detour d'une indexation de routine.
 *
 * Decision d'architecture du 2026-08-09 (direction Karbonic) : c'est le hash qui
 * determine qu'une version existe. Pour comparer un hash, il faut une base de
 * comparaison — sans instantane initial, un fichier modifie hors de la GED est
 * detecte comme change mais son etat d'avant est deja perdu.
 *
 * Un fonds auquel la GED seule ecrit n'a pas besoin de cette passe : elle
 * archive au moment du write. C'est le mode d'acces qui decide, pas la
 * fonctionnalite.
 *
 *   php tools/versioning-snapshot.php --dry-run   ce qui serait fait, sans ecrire
 *   php tools/versioning-snapshot.php             execute
 */

require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Services\FilesystemIndexer;

$dryRun = in_array('--dry-run', $argv, true);

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - INSTANTANE INITIAL DU VERSIONING                  |\n";
echo "+==============================================================+\n\n";

$db = Database::getInstance();

$aTraiter = (int) $db->query(
    "SELECT COUNT(*) FROM documents d
     WHERE d.deleted_at IS NULL AND d.file_path IS NOT NULL
       AND COALESCE(d.relative_path, '') NOT LIKE 'eval/%'
       AND d.file_path NOT LIKE '%\\eval\\%'
       AND NOT EXISTS (SELECT 1 FROM document_versions v WHERE v.document_id = d.id)"
)->fetchColumn();

$dejaVersionnes = (int) $db->query('SELECT COUNT(DISTINCT document_id) FROM document_versions')->fetchColumn();

$poids = 0;
$stmt = $db->query(
    "SELECT COALESCE(SUM(d.file_size), 0) FROM documents d
     WHERE d.deleted_at IS NULL AND d.file_path IS NOT NULL
       AND COALESCE(d.relative_path, '') NOT LIKE 'eval/%'
       AND d.file_path NOT LIKE '%\\eval\\%'
       AND NOT EXISTS (SELECT 1 FROM document_versions v WHERE v.document_id = d.id)"
);
$poids = (int) $stmt->fetchColumn();

printf("  documents sans aucune version : %d\n", $aTraiter);
printf("  documents deja versionnes     : %d\n", $dejaVersionnes);
printf("  espace disque supplementaire  : %.1f Mo (copie complete)\n\n", $poids / 1048576);

if ($aTraiter === 0) {
    echo "  Rien a faire : tous les documents ont deja une version de reference.\n\n";
    exit(0);
}

if ($dryRun) {
    echo "  --dry-run : aucune ecriture. Relancer sans l'option pour executer.\n\n";
    exit(0);
}

echo "  Execution...\n\n";
$t0 = microtime(true);

$indexer = new FilesystemIndexer();
$stats   = $indexer->snapshotInitial(true);

printf("\n  termine en %.1fs\n", microtime(true) - $t0);
printf("  documents parcourus : %d\n", $stats['documents']);
printf("  archives creees     : %d\n", $stats['archives']);
printf("  ignores (absents)   : %d\n", $stats['ignores']);
printf("  erreurs             : %d\n\n", $stats['erreurs']);

exit($stats['erreurs'] > 0 ? 1 : 0);
