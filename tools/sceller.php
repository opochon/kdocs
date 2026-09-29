<?php

declare(strict_types=1);

/**
 * Scelle un document du fonds (archivage légal V2-09) :
 *
 *   php tools/sceller.php <idDocument> [--auteur=<utilisateur>] [<base>]
 *
 * Le document est retrouvé par son identifiant d'index ; le scellement est écrit dans le dossier, à côté
 * du document (`.scellement/<fichier>.json` + `.tsr`). La TSA réelle vient de `TSA_URL` : sans elle, le
 * scellement échoue en le nommant (jamais d'horodatage silencieux). Base autorisée : gedv2 ou gedv2_test.
 */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Archivage\ErreurScellement;
use Ged\Archivage\Scelleur;
use Ged\Config;

$id = null;
$auteur = 'ligne-de-commande';
$base = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--auteur=')) {
        $auteur = substr($arg, 9);
    } elseif (str_starts_with($arg, '--')) {
        // ignoré
    } elseif ($id === null && ctype_digit($arg)) {
        $id = (int) $arg;
    } else {
        $base = $arg;
    }
}

try {
    if ($base !== null) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $base)) {
            throw new RuntimeException("nom de base invalide : $base");
        }
        putenv("DB_NAME=$base");
    }
    $config = new Config($racine);
    $nomBase = $config->get('DB_NAME', 'gedv2');
    if (!in_array($nomBase, ['gedv2', 'gedv2_test'], true)) {
        throw new RuntimeException("le scellement ne tourne que sur gedv2 ou gedv2_test, pas « $nomBase »");
    }
    if ($id === null || $id < 1) {
        throw new RuntimeException('usage : php tools/sceller.php <idDocument> [--auteur=<utilisateur>] [<base>]');
    }

    $resultat = Scelleur::depuisConfig($config)->sceller($id, $auteur);
    echo 'Scellement ' . ($resultat['already_sealed'] ? 'déjà posé' : 'posé') . ' — document ' . $id
        . ' « ' . ($resultat['chemin_relatif'] ?? '?') . ' », échéance ' . ($resultat['echeance'] ?? '?')
        . ', TSA ' . ($resultat['tsa_url'] ?? '?') . "\n";
    exit(0);
} catch (ErreurScellement|RuntimeException $e) {
    fwrite(STDERR, 'scellement : ' . $e->getMessage() . "\n");
    exit(1);
}
