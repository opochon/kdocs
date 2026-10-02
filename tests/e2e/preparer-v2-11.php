<?php

declare(strict_types=1);

/** Fonds et compte entièrement synthétiques de l'oracle « plus aucun bouton mort ». */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Archivage\Scelleur;
use Ged\Base;
use Ged\Config;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "verrou gedv2_test indisponible\n");
    exit(1);
}
try {
    $config = new Config($racine);
    if ($config->get('DB_NAME') !== 'gedv2_test') {
        throw new RuntimeException('cet oracle n’écrit que dans gedv2_test');
    }
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/v2-11/documents');
    supprimerDossier($fonds);
    mkdir($fonds, 0775, true);
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    $compte = 'v2-11@gedv2.test';
    $motDePasse = 'MotDePasse-V2-11-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute([$compte, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    $suffixe = bin2hex(random_bytes(5));
    $source = sys_get_temp_dir() . '/gedv2-v2-11-' . $suffixe;
    mkdir($source, 0775, true);
    $pdf = $source . '/original.pdf';
    $pdfOctets = "%PDF-1.4\n% V2-11 synthétique\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    file_put_contents($pdf, $pdfOctets);
    $image = $source . '/original.png';
    $imageOctets = hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8cfc0f01f00050001ff89993d1d0000000049454e44ae426082');
    file_put_contents($image, $imageOctets);
    $depot = new DepotFichiers($config);
    $commun = ['type' => 'Facture', 'emetteur' => 'Atelier V2 Onze SA', 'date' => '2026-10-02', 'statut' => 'valide', 'source' => 'ecran'];
    $document = $depot->deposer($pdf, $commun + ['titre' => 'Facture V2-11 PDF ' . $suffixe]);
    $imageId = $depot->deposer($image, $commun + ['titre' => 'Image V2-11 PNG ' . $suffixe]);
    $scelle = $depot->deposer($pdf, $commun + ['titre' => 'Facture V2-11 scellée ' . $suffixe]);
    (new Scelleur($depot, new \Ged\Archivage\TsaSimulee(), new \Ged\Archivage\HorlogeSysteme(), new \Ged\Archivage\Retention($depot->racine(), new \Ged\Archivage\HorlogeSysteme())))->sceller($scelle, $compte);
    echo json_encode(['compte' => $compte, 'mot_de_passe' => $motDePasse, 'document' => $document, 'image' => $imageId,
        'scelle' => $scelle, 'pdf_octets' => base64_encode($pdfOctets), 'image_octets' => base64_encode($imageOctets)], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation V2-11 : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

function supprimerDossier(string $chemin): void
{
    if (!is_dir($chemin)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($chemin);
}
