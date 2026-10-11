<?php

declare(strict_types=1);

/**
 * Prépare le geste E2E « reconstruction d'index » (lot ged-socle-reconstruction) : un fonds isolé de
 * 27 documents synthétiques dont UN a son fichier retiré du disque (son `.meta.json` et sa ligne
 * d'index restent). Le total relisible attendu est donc 26, avec 1 absent nommé.
 */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation reconstruction-index\n"); exit(1);
}
$env = is_file($racine . '/.env') ? (file($racine . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
foreach ($env as $ligne) {
    if (preg_match('/^\s*STORAGE_ROOT\s*=\s*(.+?)\s*$/', $ligne, $m) === 1) {
        $racineEnv = trim($m[1], " \t\"'");
        $absolueEnv = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $racineEnv) ? $racineEnv : $racine . '/' . $racineEnv;
        if (strtolower(rtrim(str_replace('\\', '/', $absolueEnv), '/')) === strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/'))) {
            fwrite(STDERR, "STORAGE_ROOT de .env refusée : fournissez le fonds isolé du geste\n"); exit(1);
        }
    }
}
$attendu = $racine . '/var/oracles/reconstruction-index/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé reconstruction-index\n"); exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) { fwrite(STDERR, "verrou gedv2_test indisponible\n"); exit(1); }
try {
    putenv('DB_NAME=gedv2_test'); putenv('APP_ENV=test');
    $config = new Config($racine);
    if ($config->get('DB_NAME') !== 'gedv2_test') throw new RuntimeException('préparation limitée à gedv2_test');
    effacerFonds($racineDemandee); mkdir($racineDemandee, 0775, true);
    Base::assurerBase($config); $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo); SchemaSecurite::installer($pdo); SchemaRecherche::installer($pdo);
    remettreEtatFondsE2e($pdo);

    $identifiant = 'reconstruction-admin@exemple.test'; $motDePasse = 'MotDePasse-Reconstruction-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte=VALUES(empreinte), role=VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    $source = dirname($racineDemandee) . '/depots'; if (!is_dir($source)) mkdir($source, 0775, true);
    $depot = new DepotFichiers($config);
    $commun = ['type' => 'Facture', 'emetteur' => 'Atelier Reconstruction SA', 'date' => '2026-10-11',
        'statut' => 'valide', 'texte' => 'document synthétique reconstruction'];
    $identifiants = [];
    for ($n = 1; $n <= 27; $n++) {
        $identifiants[] = $depot->deposer(piece($source, 'reconstruction-' . $n . '.pdf', 'Reconstruction ' . $n),
            $commun + ['titre' => 'Pièce reconstruction ' . $n]);
    }

    // UN document vivant dont le fichier disparaît du disque : ses métadonnées et sa ligne d'index restent.
    $absent = (string) $depot->fiche($identifiants[26])['chemin_relatif'];
    unlink($racineDemandee . '/' . $absent);
    if (is_file($racineDemandee . '/' . $absent)) throw new RuntimeException('le fichier absent devait disparaître');

    echo json_encode(['compte' => $identifiant, 'mot_de_passe' => $motDePasse,
        'total' => 26, 'absents' => 1, 'nom_absent' => $absent], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation reconstruction-index : ' . $e->getMessage() . PHP_EOL); exit(1);
} finally {
    flock($verrou, LOCK_UN); fclose($verrou);
}

function piece(string $dossier, string $nom, string $texte): string
{
    $fichier = $dossier . '/' . $nom;
    file_put_contents($fichier, "%PDF-1.4\n% $texte\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return $fichier;
}

function effacerFonds(string $chemin): void
{
    if (!is_dir($chemin)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($chemin);
}
