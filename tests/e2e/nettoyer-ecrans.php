<?php

declare(strict_types=1);

/**
 * Remet le fonds de test dans son état committé après les parcours ged-parite-ecrans-1.
 *
 * Ne supprime QUE les pièces synthétiques créées par `preparer-ecrans.php` (identifiants et chemins
 * relus de var/e2e-ecrans.json) et les préférences/vues/tâches du parcours (.ged). Le corpus committé
 * (201..213) n'est jamais touché. Base gedv2_test seulement.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$fichier = $racine . '/var/e2e-ecrans.json';
$etat = is_file($fichier) ? json_decode((string) file_get_contents($fichier), true) : [];
if (!is_array($etat)) {
    $etat = [];
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "nettoyage écrans : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('nettoyage écrans : seule gedv2_test est admise');
    }
    $fonds = rtrim(str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents')), '/');

    // Groupes synthétiques du parcours utilisateurs : toujours retirés, même sans état de fonds.
    Base::assurerBase($config);
    Base::pdo($config)->exec("DELETE FROM securite_groupes WHERE nom LIKE 'E2E groupe%'");

    $ids = [];
    $relatifs = [];
    foreach (['a_classer', 'corbeille', 'archive'] as $cle) {
        if (isset($etat[$cle]['id'])) {
            $ids[] = (int) $etat[$cle]['id'];
        }
        if (isset($etat[$cle]['relatif'])) {
            $relatifs[] = (string) $etat[$cle]['relatif'];
        }
    }

    if ($ids !== []) {
        Base::assurerBase($config);
        $pdo = Base::pdo($config);
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM document_versions WHERE document_id IN ($marqueurs)")->execute($ids);
        $pdo->prepare("DELETE FROM document_deplacements WHERE document_id IN ($marqueurs)")->execute($ids);
        $pdo->prepare("DELETE FROM documents WHERE id IN ($marqueurs)")->execute($ids);
        if (isset($etat['jeton'])) {
            $pdo->prepare('DELETE FROM ingestion_messages WHERE message_id = ?')->execute(['e2e-message-' . (string) $etat['jeton']]);
        }
    }

    foreach ($relatifs as $relatif) {
        supprimer($fonds . '/' . $relatif);
        supprimer($fonds . '/' . $relatif . '.meta.json');
        supprimer($fonds . '/.corbeille/' . $relatif);
        supprimer($fonds . '/.corbeille/' . $relatif . '.meta.json');
        // `.versions/<fichier>/` vit À CÔTÉ du document (dans son dossier de rangement).
        supprimerDossier(dirname($fonds . '/' . $relatif) . '/.versions/' . basename($relatif));
        supprimerDossier(dirname($fonds . '/.corbeille/' . $relatif) . '/.versions/' . basename($relatif));
    }
    // Préférences, vues et tâches du parcours : jamais autre chose que l'état du fonds de test.
    supprimerDossier($fonds . '/.ged');
    // Dossiers de rangement du parcours (année/type/émetteur) et corbeille : le corpus committé n'a que
    // des fichiers à la racine et `.apercu/`. On ne retire QUE des dossiers d'année et la corbeille.
    foreach (glob($fonds . '/20[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $annee) {
        supprimerDossier($annee);
    }
    supprimerDossier($fonds . '/.corbeille');

    @unlink($fichier);
    echo "nettoyé : " . count($ids) . " document(s)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'nettoyage écrans : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

function supprimer(string $chemin): void
{
    if (is_file($chemin)) {
        @unlink($chemin);
    }
}

function supprimerDossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $elements = scandir($chemin) ?: [];
    foreach ($elements as $element) {
        if ($element === '.' || $element === '..') {
            continue;
        }
        $complet = $chemin . '/' . $element;
        if (is_dir($complet)) {
            supprimerDossier($complet);
        } else {
            @unlink($complet);
        }
    }
    @rmdir($chemin);
}
