<?php

declare(strict_types=1);

/**
 * Prépare le geste E2E « copie d'archivage PDF/A » (lot ged-pdfa-1) : un fonds isolé de deux PDF texte
 * synthétiques (sans copie PDF/A) et d'un fichier `.txt` non applicable. L'utilisateur lance le rattrapage
 * depuis l'écran Application, puis ouvre la fiche et télécharge la copie.
 *
 * Rien n'est réel : documents et noms sont inventés.
 */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation pdfa\n");
    exit(1);
}
$attendu = $racine . '/var/oracles/pdfa/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé pdfa\n");
    exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "verrou gedv2_test indisponible\n");
    exit(1);
}
try {
    putenv('DB_NAME=gedv2_test');
    putenv('APP_ENV=test');
    $config = new Config($racine);
    if ($config->get('DB_NAME') !== 'gedv2_test') {
        throw new RuntimeException('préparation limitée à gedv2_test');
    }
    effacerFondsPdfa($racineDemandee);
    mkdir($racineDemandee, 0775, true);
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    SchemaIngestion::installer($pdo);
    remettreEtatFondsE2e($pdo);

    $identifiant = 'pdfa-admin@exemple.test';
    $motDePasse = 'MotDePasse-Pdfa-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte=VALUES(empreinte), role=VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    $source = dirname($racineDemandee) . '/depots';
    if (!is_dir($source)) {
        mkdir($source, 0775, true);
    }
    $depot = new DepotFichiers($config);
    $commun = ['type' => 'Facture', 'emetteur' => 'Atelier Pdfa SA', 'date' => '2026-10-11',
        'statut' => 'valide', 'texte' => 'document synthétique copie PDF/A'];
    $documents = [];
    foreach (['Copie pdfa une', 'Copie pdfa deux'] as $n => $titre) {
        $fichier = $source . '/pdfa-' . ($n + 1) . '.pdf';
        pdfValidePdfa($fichier, strtoupper(str_replace(' ', '-', $titre)));
        $id = $depot->deposer($fichier, $commun + ['titre' => $titre]);
        $documents[] = ['id' => $id, 'chemin' => (string) $depot->fiche($id)['chemin_relatif'], 'titre' => $titre];
    }
    $note = $source . '/note.txt';
    file_put_contents($note, "note synthétique, aucune copie PDF/A attendue\n");
    $noteId = $depot->deposer($note, ['titre' => 'Note pdfa', 'statut' => 'valide', 'texte' => 'non applicable']);

    echo json_encode(['compte' => $identifiant, 'mot_de_passe' => $motDePasse, 'note_id' => $noteId,
        'total' => count($documents), 'documents' => $documents], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation pdfa : ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

/** Un PDF texte d'une page, valide (xref incluse) : Ghostscript peut le convertir en PDF/A. */
function pdfValidePdfa(string $fichier, string $texte): void
{
    $flux = "BT /F1 18 Tf 60 780 Td (" . $texte . ") Tj ET\n";
    $objets = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => "<< /Length " . strlen($flux) . " >>\nstream\n" . $flux . "endstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objets as $n => $corps) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "$n 0 obj\n$corps\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objets) + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= count($objets); $n++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objets) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($fichier, $pdf);
}

function effacerFondsPdfa(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($chemin);
}
