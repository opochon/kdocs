<?php

declare(strict_types=1);

/**
 * Purge le journal d'audit par rétention (équivalent fonctionnel de la commande Paperless
 * `prune_audit_logs`) :
 *
 *   php tools/purger-journal-audit.php [--retention=90] [--fichier=<chemin>] [--json]
 *
 * Seules les entrées d'audit PLUS ANCIENNES que la rétention sont retirées ; une entrée illisible est
 * CONSERVÉE (jamais de destruction silencieuse). Aucune donnée utilisateur (documents, versions, corbeille)
 * n'est concernée. Le fichier vient de `SECURITE_DOSSIER/audit.ndjson` (ou de `--fichier`).
 */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Config;
use Ged\Securite\PurgeAudit;

$retention = 90;
$fichier = null;
$json = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--retention=(\d+)$/', $argument, $m) === 1) {
        $retention = (int) $m[1];
    } elseif (str_starts_with($argument, '--fichier=')) {
        $fichier = substr($argument, 10);
    } elseif ($argument === '--json') {
        $json = true;
    }
}

$config = new Config($racine);
$fichier ??= $config->chemin('SECURITE_DOSSIER', 'var/securite') . '/audit.ndjson';

try {
    $bilan = (new PurgeAudit())->purger($fichier, $retention);
} catch (RuntimeException $e) {
    fwrite(STDERR, "purge du journal d'audit refusée : " . $e->getMessage() . "\n");
    exit(2);
}

if ($json) {
    echo json_encode($bilan + ['fichier' => $fichier, 'retention_jours' => $retention], JSON_UNESCAPED_SLASHES), "\n";
} else {
    printf(
        "Journal d'audit : %d entrée(s) lue(s), %d conservée(s), %d purgée(s) (rétention %d jours)\n",
        $bilan['avant'],
        $bilan['apres'],
        $bilan['supprimes'],
        $retention,
    );
}

exit(0);
