<?php

declare(strict_types=1);

/**
 * Vérifie tous les scellements d'un fonds documentaire, depuis le DOSSIER seul (aucune base, aucun réseau).
 *
 *   php tools/verifier-scellements.php [fonds]
 *
 * Sans argument, la racine documentaire vient de la configuration (STORAGE_ROOT). Pour chaque scellement
 * `.scellement/<fichier>.json`, on rejoue l'empreinte du fichier, celle des métadonnées, l'empreinte
 * composite scellée et le jeton TSA. Un fonds sain rend 0 écart ; un octet changé est trouvé et nommé.
 * Code de sortie : 0 si 0 écart, 1 sinon (transmissible à un audit).
 */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Archivage\Verificateur;
use Ged\Config;

$fonds = $argv[1] ?? (new Config($racine))->chemin('STORAGE_ROOT', 'storage/documents');
$fonds = rtrim(str_replace('\\', '/', $fonds), '/');

if (!is_dir($fonds)) {
    fwrite(STDERR, "vérification : racine documentaire introuvable ($fonds)\n");
    exit(2);
}

$bilan = (new Verificateur($fonds))->verifierFonds();

echo "Fonds : $fonds\n";
foreach ($bilan['details'] as $detail) {
    if ($detail['ok']) {
        echo 'OK    ' . $detail['scellement'] . " — scellement vérifié\n";
        continue;
    }
    foreach ($detail['ecarts'] as $ecart) {
        echo 'ÉCART ' . $detail['scellement'] . ' — ' . $ecart . "\n";
    }
}

$nombre = count($bilan['ecarts']);
echo $nombre === 0
    ? 'VERDICT : 0 écart sur ' . $bilan['scellements'] . " scellement(s)\n"
    : 'VERDICT : ' . $nombre . " écart(s) sur " . $bilan['scellements'] . " scellement(s)\n";
exit($nombre === 0 ? 0 : 1);
