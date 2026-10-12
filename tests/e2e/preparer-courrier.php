<?php

declare(strict_types=1);

/**
 * Prépare le geste E2E « administration du courrier » (lot ged-parite-modele-2) : un compte administrateur,
 * une boîte de test EN MÉMOIRE (fichier JSON, aucun serveur réel) et un journal vidé. L'utilisateur crée son
 * compte de courrier, teste la connexion sur le faux, crée une règle, traite la boîte et lit le journal.
 *
 * Rien n'est réel : adresses et sujets sont inventés.
 */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Ingestion\Courrier\Coffre;
use Ged\Socle\Schema as SchemaSocle;

$donnees = getenv('COURRIER_DONNEES');
$storage = getenv('STORAGE_ROOT');
if (!is_string($donnees) || trim($donnees) === '' || !is_string($storage) || trim($storage) === '') {
    fwrite(STDERR, "COURRIER_DONNEES et STORAGE_ROOT explicites obligatoires pour la préparation courrier\n");
    exit(1);
}
$attenduDonnees = $racine . '/var/oracles/courrier/donnees';
$attenduFonds = $racine . '/var/oracles/courrier/documents';
if (strtolower(rtrim(str_replace('\\', '/', $donnees), '/')) !== strtolower(str_replace('\\', '/', $attenduDonnees))
    || strtolower(rtrim(str_replace('\\', '/', $storage), '/')) !== strtolower(str_replace('\\', '/', $attenduFonds))) {
    fwrite(STDERR, "les dossiers courrier doivent viser var/oracles/courrier\n");
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

    // Remise à zéro du dossier de configuration courrier et du fonds isolé.
    viderDossier($donnees);
    viderDossier($storage);
    if (!is_dir($donnees)) {
        mkdir($donnees, 0775, true);
    }
    if (!is_dir($storage)) {
        mkdir($storage, 0775, true);
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaIngestion::installer($pdo);
    remettreEtatFondsE2e($pdo);   // index du fonds synthétique remis à zéro (documents, journal, travaux)

    $identifiant = 'courrier-admin@exemple.test';
    $motDePasse = 'MotDePasse-Courrier-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte=VALUES(empreinte), role=VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    // Le coffre de test : clé stable (fichier local) pour que le faux serveur se rejoue tel quel.
    Coffre::depuisConfig($config, $donnees);

    // Boîte en mémoire : deux courriels, dont une facture « Invoice » avec sa pièce jointe.
    $boite = [
        'nom' => 'boite-e2e@exemple.test',
        'messages' => [[
            'uid' => 1, 'sujet' => 'Invoice E2E', 'de' => 'fournisseur@exemple.test', 'de_nom' => 'Fournisseur Fictif',
            'a' => ['ged@exemple.test'], 'corps' => 'Voici la facture du mois.', 'vu' => false, 'signale' => false,
            'pieces' => [['nom' => 'facture-e2e.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment', 'contenu' => '%PDF-1.4 facture synthetique']],
        ], [
            'uid' => 2, 'sujet' => 'Publicité', 'de' => 'promo@ailleurs.test', 'de_nom' => '', 'a' => ['ged@exemple.test'],
            'corps' => 'offre spéciale', 'vu' => false, 'signale' => false,
            'pieces' => [['nom' => 'promo.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment', 'contenu' => '%PDF-1.4 promotion']],
        ]],
    ];
    file_put_contents($donnees . '/boite-fausse.json', (string) json_encode($boite, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode(['compte' => $identifiant, 'mot_de_passe' => $motDePasse], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation courrier : ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

function viderDossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
