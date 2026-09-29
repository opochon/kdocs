<?php

declare(strict_types=1);

/**
 * Classe la QUATRIÈME pièce de l'oracle « À traiter », après que les trois premières ont été validées
 * à la main depuis l'écran (choix humains -> habitude). Elle passe par le VRAI classeur
 * (`Ged\Classement\ClasseurGed`) avec un client IA inerte : sans habitude, la pièce serait « règle » ;
 * avec l'habitude de l'émetteur, elle doit arriver en source « habitude ».
 *
 *   STORAGE_ROOT=<dossier absolu> php tests/oracles/interface/classer-piece-4.php
 *
 * Sortie JSON : { source, type, valide, emetteur, id }.
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Classement\Habitudes;
use Ged\Config;
use Ged\Socle\DepotFichiers;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "classement : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/interface/documents');
    $staging = dirname($fonds) . '/a-classer';
    $fichier = $staging . '/piece-4.pdf';
    $texteFichier = $staging . '/piece-4.txt';
    if (!is_file($fichier) || !is_file($texteFichier)) {
        throw new RuntimeException('la quatrième pièce préparée est introuvable');
    }
    $texte = (string) file_get_contents($texteFichier);

    $pdo = Base::pdo($config);
    $classeur = new ClasseurGed($config, $pdo, new ClientIaNul(), new Habitudes($pdo));
    $proposition = $classeur->proposer($texte, [], 'piece-4.pdf');

    $depot = new DepotFichiers($config);
    $id = $depot->deposer($fichier, [
        'titre' => $proposition['titre'],
        'type' => $proposition['type'],
        'emetteur' => $proposition['emetteur'],
        'date' => $proposition['date'],
        'montant' => $proposition['montant'],
        'etiquettes' => $proposition['etiquettes'],
        'statut' => ($proposition['valide'] ?? false) ? 'valide' : 'a_controler',
        'source' => 'dossier',
        'texte' => $texte,
        'raison' => $proposition['raison'],
        'classement' => [
            'source' => $proposition['source'],
            'confiance' => $proposition['confiance'],
            'preuve' => $proposition['preuve'],
        ],
    ]);
    $depot->mettreAJour($id, ['id' => $id], 'oracle');

    echo json_encode([
        'source' => $proposition['source'],
        'type' => $proposition['type'],
        'valide' => $proposition['valide'],
        'emetteur' => $proposition['emetteur'],
        'preuve' => $proposition['preuve'],
        'id' => $id,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'classement : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}
