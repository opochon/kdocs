<?php

declare(strict_types=1);

/** Fonds V2-12a : six pages synthétiques, dont une tournée et une blanche, sans corpus réel. */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false) {
    fwrite(STDERR, "verrou gedv2_test introuvable\n");
    exit(1);
}
// Verrou borne : un detenteur fige (process orphelin) ne doit jamais faire attendre
// a l'infini, sinon chaque run expire et laisse un nouvel orphelin tenant le verrou.
$limite = microtime(true) + 60.0;
while (!flock($verrou, LOCK_EX | LOCK_NB)) {
    if (microtime(true) >= $limite) {
        fwrite(STDERR, "verrou gedv2_test tenu par un autre processus apres 60 s\n");
        fclose($verrou);
        exit(1);
    }
    usleep(200000);
}
try {
    $config = new Config($racine);
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/edition-pages/documents');
    supprimer($fonds);
    mkdir($fonds, 0775, true);
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Ingestion', 'Classement'] as $secteur) {
        ('Ged\\' . $secteur . '\\Schema')::installer($pdo);
    }
    SchemaSecurite::installer($pdo);
    remettreEtatFondsE2e($pdo);
    purgerEditionPrecedente($pdo);
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute(['testeur@gedv2.test', password_hash('MotDePasse-Test-2026!', PASSWORD_DEFAULT), 'admin']);

    $jeton = bin2hex(random_bytes(5));
    $lot = random_int(1_000_000, 9_999_999);
    $source = dirname($fonds) . '/lot-six-pages-' . $jeton . '.pdf';
    creerPdf($config, $source, 6);
    $depot = new DepotFichiers($config);
    $id = $depot->deposer($source, [
        'titre' => 'Courrier synthétique ' . $jeton . ' — deux documents collés', 'type' => 'Courrier',
        'emetteur' => 'Papeterie Fictive SA', 'date' => '2026-10-02', 'statut' => 'a_controler',
        'texte' => "Courrier synthétique\nPage 3 était à l’envers\nPage 6 blanche", 'source' => 'dossier',
        'raison' => 'découpe à contrôler',
        'classement' => ['source' => 'regle', 'confiance' => 0.4, 'preuve' => 'oracle V2-12a'],
        'origine' => ['lot' => $lot, 'pages' => '1-6', 'pages_liste' => [1, 2, 3, 4, 5, 6]],
    ]);
    echo json_encode(['id' => $id, 'lot' => $lot, 'fonds' => $fonds, 'original' => hash_file('sha256', $source)], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation édition pages : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

function creerPdf(Config $config, string $fichier, int $pages): void
{
    $script = "import pymupdf as fitz\ndoc=fitz.open()\n[doc.new_page(width=595,height=842) for _ in range($pages)]\ndoc.save(r'"
        . str_replace('\\', '\\\\', $fichier) . "')\n";
    $processus = proc_open([$config->get('CMD4_PYTHON'), '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($processus)) throw new RuntimeException('Python indisponible');
    fclose($pipes[1]);
    $erreur = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($processus) !== 0) throw new RuntimeException(trim($erreur));
}

function supprimer(string $dossier): void
{
    if (!is_dir($dossier)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dossier);
}

/**
 * Le dossier isolé est remplacé à chaque parcours. Son index de test doit l'être
 * aussi, sinon la pièce produite par une scission précédente retient son chemin
 * unique alors que son fichier n'existe plus. Le marqueur est propre à ce corpus.
 */
function purgerEditionPrecedente(PDO $pdo): void
{
    $texte = "Courrier synthétique\nPage 3 était à l’envers\nPage 6 blanche";
    $selection = $pdo->prepare("SELECT id FROM documents WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.texte')) = ?");
    $selection->execute([$texte]);
    $ids = array_map(static fn(array $ligne): int => (int) $ligne['id'], $selection->fetchAll(PDO::FETCH_ASSOC));
    if ($ids === []) return;

    $marques = implode(',', array_fill(0, count($ids), '?'));
    foreach (['document_versions', 'document_deplacements'] as $table) {
        $pdo->prepare("DELETE FROM {$table} WHERE document_id IN ({$marques})")->execute($ids);
    }
    $pdo->prepare("DELETE FROM documents WHERE id IN ({$marques})")->execute($ids);
}
