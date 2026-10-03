<?php

declare(strict_types=1);

/** Essai réseau volontairement hors harness : une empreinte synthétique par TSA publique, puis vérification CMS. */

$racine = dirname(__DIR__);
require $racine . '/vendor/autoload.php';

use Ged\Archivage\ErreurScellement;
use Ged\Archivage\TsaReelle;

$urls = ['http://timestamp.digicert.com', 'https://freetsa.org/tsr'];
$echecs = 0;
foreach ($urls as $url) {
    try {
        $tsa = new TsaReelle($url);
        $jeton = $tsa->horodater(hash('sha256', 'GEDv2 essai TSA synthétique ' . $url, true));
        echo "OK    $url — " . strlen($jeton) . " octets, signature CMS et nonce vérifiés\n";
    } catch (ErreurScellement $e) {
        $echecs++;
        fwrite(STDERR, "ECHEC $url — {$e->getMessage()}\n");
    }
}
exit($echecs === 0 ? 0 : 1);
