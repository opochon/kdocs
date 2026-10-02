<?php

declare(strict_types=1);

/**
 * Prépare le fonds de l'oracle « À traiter » (Playwright tests/e2e/a-traiter.spec.ts).
 *
 *   STORAGE_ROOT=<dossier absolu> php tests/oracles/interface/preparer-a-traiter.php
 *
 * - impose gedv2_test / APP_ENV=test et prend le verrou partagé (comme PHPUnit et la sonde) ;
 * - ne touche AUCUNE table d'un autre secteur : le fonds d'essai vit dans STORAGE_ROOT, un dossier
 *   recréé à neuf (jamais tests/corpus) ; l'index suit le dossier ;
 * - dépose TROIS pièces synthétiques via `Ged\Socle\DepotFichiers` (le vrai chemin de dépôt), toutes en
 *   statut « a_controler », même émetteur, type « Facture » proposé par la règle — de quoi former une
 *   habitude en trois validations humaines ;
 * - laisse une quatrième pièce du MÊME émetteur dans le dossier d'essai, à classer après coup.
 *
 * Sortie JSON : { preparation, emetteur, ids, staging, piece4 }.
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;

putenv('DB_NAME=gedv2_test');   // l'oracle n'écrit que la base de test, quoi qu'en dise l'environnement
putenv('APP_ENV=test');

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('cet oracle n’écrit que sur gedv2_test');
    }
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/interface/documents');
    $staging = dirname($fonds) . '/a-classer';

    supprimerDossier($fonds);
    supprimerDossier($staging);
    if (!mkdir($fonds, 0775, true) && !is_dir($fonds)) {
        throw new RuntimeException("préparation : impossible de créer $fonds");
    }
    if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
        throw new RuntimeException("préparation : impossible de créer $staging");
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Ingestion', 'Classement'] as $secteur) {
        ('Ged\\' . $secteur . '\\Schema')::installer($pdo);
    }
    remettreEtatFondsE2e($pdo);

    // Contenu réel d'un PDF synthétique du corpus : l'aperçu a un vrai fichier à rendre.
    $modele = $racine . '/tests/corpus/apercu/documents/201-facture-an-5001.pdf';
    $contenu = is_file($modele) ? (string) file_get_contents($modele) : "%PDF-1.4\n%%EOF\n";

    // Émetteur unique par passage, sans chiffre : `Regles::lireEmetteur` le lit comme un nom.
    $emetteur = 'Emetteur Oracle ' . tokenLettres(6);
    $depot = new DepotFichiers($config);
    $ids = [];
    $source = $staging . '/.source.pdf';
    for ($i = 1; $i <= 3; $i++) {
        $date = sprintf('2026-01-%02d', $i);
        $texte = $emetteur . "\n"
            . "Facture FICTIVE-ORACLE-$i\n"
            . "Concerne : Facture oracle $i\n"
            . "En votre aimable règlement : CHF " . (100 + $i) . ".00\n";
        file_put_contents($source, $contenu);
        $id = $depot->deposer($source, [
            'titre' => "Facture oracle $i — $emetteur",
            'type' => 'Facture',
            'emetteur' => $emetteur,
            'date' => $date,
            'montant' => 100.0 + $i,
            'etiquettes' => ['oracle'],
            'statut' => 'a_controler',
            'source' => 'dossier',
            'texte' => $texte,
            'raison' => 'confiance IA insuffisante (0.62 < 0.8)',
            'classement' => ['source' => 'regle', 'confiance' => 0.62, 'preuve' => 'mot « facture » dans le texte'],
            'origine' => ['lot' => 0, 'pages' => '1'],
        ]);
        // L'index écrit le fichier sans identifiant : on rend le `.meta.json` auto-descriptible, comme
        // le demande déjà le secteur Workflows (l'aperçu réel résout la pièce par son `id`).
        $depot->mettreAJour($id, ['id' => $id], 'oracle');
        $ids[] = $id;
    }
    @unlink($source);

    // La quatrième pièce, du même émetteur, sera classée APRÈS les trois validations.
    $texte4 = $emetteur . "\n"
        . "Facture FICTIVE-ORACLE-4\n"
        . "Concerne : Facture oracle 4\n"
        . "En votre aimable règlement : CHF 104.00\n";
    file_put_contents($staging . '/piece-4.pdf', $contenu);
    file_put_contents($staging . '/piece-4.txt', $texte4);

    echo json_encode([
        'preparation' => 'prete',
        'emetteur' => $emetteur,
        'ids' => $ids,
        'staging' => $staging,
        'piece4' => ['fichier' => $staging . '/piece-4.pdf', 'texte' => $staging . '/piece-4.txt'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

function tokenLettres(int $longueur): string
{
    $jeton = '';
    for ($i = 0; $i < $longueur; $i++) {
        $jeton .= chr(random_int(65, 90));
    }

    return $jeton;
}

/** Efface un dossier d'essai créé par ce script (jamais un dossier du corpus versionné). */
function supprimerDossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($chemin);
}
