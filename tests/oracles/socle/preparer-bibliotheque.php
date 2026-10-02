<?php

declare(strict_types=1);

/**
 * Prépare le fonds de l'oracle « Bibliothèque » du lot V2-04 (Playwright tests/e2e/bibliotheque.spec.ts).
 *
 *   STORAGE_ROOT=<dossier absolu> php tests/oracles/socle/preparer-bibliotheque.php
 *
 * - impose gedv2_test / APP_ENV=test et prend le verrou partagé (comme PHPUnit et les autres oracles) ;
 * - ne touche AUCUNE table d'un autre secteur : la configuration (nomenclature, vues) vit dans le
 *   fonds (`.ged/`) qui est recréé à neuf — la nomenclature des six types y est reposée ; l'index
 *   des documents suit les dépôts, sans jamais troncer les tables partagées ;
 * - le fonds d'essai vit dans STORAGE_ROOT (var/oracles/socle/documents), un dossier recréé à neuf :
 *   trois documents synthétiques déposés par le VRAI chemin (DepotFichiers::deposer), avec leur `id`
 *   rendu auto-descriptif dans le `.meta.json` pour la visionneuse du secteur Aperçu.
 *
 * Sortie JSON : { preparation, ids: { essai, maintenance, facture }, titre_essai }.
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Schema;

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
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/socle/documents');

    supprimerDossier($fonds);
    if (!is_dir(dirname($fonds)) && !mkdir(dirname($fonds), 0775, true) && !is_dir(dirname($fonds))) {
        throw new RuntimeException("préparation : impossible de créer " . dirname($fonds));
    }
    mkdir($fonds, 0775, true);

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    Schema::installer($pdo);
    remettreEtatFondsE2e($pdo);

    // La configuration de la GED (nomenclature, vues) vit dans LE fonds (`.ged/`, V2-04) : le recréer
    // à neuf l'a déjà remise à zéro — champs et vues d'un passage précédent ont disparu avec lui.
    // La graine des six types se repose ; AUCUNE table de la base partagée n'est effacée.
    (new Nomenclature($config))->assurer();

    // Mes lignes d'index d'essai seulement (chemins déterministes sous mon émetteur) : les titres ne
    // changent pas d'un passage à l'autre, il faut libérer les chemins avant de redéposer. Jamais
    // une ligne d'un autre parcours — la base est partagée.
    $miennes = $pdo->prepare('SELECT id FROM documents WHERE chemin_relatif LIKE ?');
    $miennes->execute(['2026/%/Atelier_Numérique_Exemple_Sàrl/%']);
    $idsAvant = $miennes->fetchAll(PDO::FETCH_COLUMN);
    if ($idsAvant !== []) {
        $liste = implode(',', array_map('intval', $idsAvant));
        $pdo->exec("DELETE FROM document_versions WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM document_deplacements WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM documents WHERE id IN ($liste)");
    }

    // Contenu réel du PDF synthétique du corpus : la visionneuse a un vrai fichier à rendre.
    $modele = $racine . '/tests/corpus/apercu/documents/201-facture-an-5001.pdf';
    $contenu = is_file($modele) ? (string) file_get_contents($modele) : "%PDF-1.4\n%%EOF\n";

    $depot = new DepotFichiers($config);
    $deposer = static function (string $titre, string $type, array $supplements) use ($depot, $contenu): int {
        $source = sys_get_temp_dir() . '/gedv2-socle-piece.pdf';
        file_put_contents($source, $contenu);
        $id = $depot->deposer($source, ['titre' => $titre, 'type' => $type,
            'emetteur' => 'Atelier Numérique Exemple Sàrl', ...$supplements]);
        // Le `.meta.json` devient auto-descriptif : la visionneuse résout la pièce par son `id`.
        $depot->mettreAJour($id, ['id' => $id], 'oracle');
        @unlink($source);

        return $id;
    };

    $ids = [
        'essai' => $deposer('Contrat d’essai — vue à échéance', 'Contrat', ['date' => '2026-09-05']),
        'maintenance' => $deposer('Contrat de maintenance CM-2026-04', 'Contrat', ['date' => '2026-02-05']),
        'facture' => $deposer('Facture AN-5001', 'Facture', ['date' => '2026-01-12', 'montant' => 4244.74]),
    ];

    echo json_encode(['preparation' => 'prete', 'ids' => $ids, 'titre_essai' => 'Contrat d’essai — vue à échéance'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
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
