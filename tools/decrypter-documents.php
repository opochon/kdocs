<?php

declare(strict_types=1);

/**
 * Déchiffre les PDF chiffrés du fonds (équivalent fonctionnel de la commande Paperless `decrypt_documents`) :
 *
 *   php tools/decrypter-documents.php [--appliquer] [--racine=<dossier>] [--json]
 *
 * Sans `--appliquer`, la commande liste ce qu'elle FERAIT (lecture seule). Avec `--appliquer`, elle
 * déchiffre sur place chaque PDF chiffré via l'outil externe `PDF_DECHIFFREUR` (qpdf par défaut). Un outil
 * absent ou un échec est NOMMÉ, jamais silencieux ; aucun octet du fonds n'est modifié par la liste.
 *
 * Le fonds vient de `STORAGE_ROOT` (ou de `--racine`), jamais d'un chemin en dur.
 */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Config;
use Ged\Ingestion\DechiffreurPdf;

$appliquer = false;
$json = false;
$dossier = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--appliquer') {
        $appliquer = true;
    } elseif ($argument === '--json') {
        $json = true;
    } elseif (str_starts_with($argument, '--racine=')) {
        $dossier = substr($argument, 9);
    }
}

$config = new Config($racine);
$fonds = $dossier ?? $config->chemin('STORAGE_ROOT', 'storage/documents');
if (!is_dir($fonds)) {
    fwrite(STDERR, "dossier du fonds introuvable : $fonds\n");
    exit(2);
}

$dechiffreur = DechiffreurPdf::depuis();
$resultats = [];
$parcours = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fonds, FilesystemIterator::SKIP_DOTS));
foreach ($parcours as $fichier) {
    if (!$fichier instanceof SplFileInfo || !$fichier->isFile()) {
        continue;
    }
    $chemin = $fichier->getPathname();
    if (strtolower($fichier->getExtension()) !== 'pdf' || !DechiffreurPdf::estChiffre($chemin)) {
        continue;
    }
    $raison = $appliquer ? $dechiffreur->dechiffrer($chemin) : 'à déchiffrer (liste seule)';
    $resultats[] = ['fichier' => $chemin, 'resultat' => $raison ?? 'déchiffré'];
}

if ($json) {
    echo json_encode(['total' => count($resultats), 'resultats' => $resultats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo $appliquer ? "Déchiffrement appliqué\n" : "PDF chiffrés repérés (aucune écriture)\n";
    foreach ($resultats as $resultat) {
        echo ' - ' . $resultat['fichier'] . ' : ' . $resultat['resultat'] . "\n";
    }
    echo count($resultats) . " fichier(s) chiffré(s)\n";
}

exit(0);
