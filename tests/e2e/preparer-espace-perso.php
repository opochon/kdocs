<?php

declare(strict_types=1);

/**
 * Prépare le parcours E2E « espace perso & partage » (lot ged-mfiles-espace-1), dans son fonds isolé,
 * sous le verrou gedv2_test commun. Aucune donnée réelle : noms et corpus fictifs.
 */
$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\SourceRecherche;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation espace-perso\n"); exit(1);
}
$attendu = $racine . '/var/oracles/espace-perso/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé var/oracles/espace-perso/documents\n"); exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) { fwrite(STDERR, "verrou gedv2_test indisponible\n"); exit(1); }
try {
    putenv('DB_NAME=gedv2_test'); putenv('APP_ENV=test');
    $config = new Config($racine);
    if ($config->get('DB_NAME') !== 'gedv2_test') throw new RuntimeException('préparation limitée à gedv2_test');
    effacerFondsEspace($racineDemandee); mkdir($racineDemandee, 0775, true);
    Base::assurerBase($config); $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo); SchemaSecurite::installer($pdo); SchemaRecherche::installer($pdo);
    remettreEtatFondsE2e($pdo);
    $pdo->exec('DELETE FROM document_collaboration');

    $identifiant = 'espace-perso@exemple.test'; $motDePasse = 'MotDePasse-Espace-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte=VALUES(empreinte), role=VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    $source = dirname($racineDemandee) . '/depots'; if (!is_dir($source)) mkdir($source, 0775, true);
    $depot = new DepotFichiers($config);
    $commun = ['type' => 'Facture', 'emetteur' => 'Atelier Espace SA', 'date' => '2026-10-03', 'statut' => 'valide', 'texte' => 'document synthétique espace-perso'];
    $premier = $depot->deposer(pieceEspace($source, 'premier.pdf', 'Premier document espace'), $commun + ['titre' => 'Facture Espace']);
    $second = $depot->deposer(pieceEspace($source, 'second.pdf', 'Second document espace'), $commun + ['titre' => 'Contrat Espace']);
    (new IndexPleinTexte($pdo, new SourceRecherche($racineDemandee)))->synchroniser();

    echo json_encode([
        'compte' => $identifiant, 'mot_de_passe' => $motDePasse,
        'document' => $premier, 'cible' => $second,
    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'préparation espace-perso : ' . $e->getMessage() . PHP_EOL); exit(1); }
finally { flock($verrou, LOCK_UN); fclose($verrou); }

function pieceEspace(string $dossier, string $nom, string $texte): string
{
    $fichier = $dossier . '/' . $nom;
    file_put_contents($fichier, "%PDF-1.4\n% $texte\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return $fichier;
}

function effacerFondsEspace(string $chemin): void
{
    if (!is_dir($chemin)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($chemin);
}
