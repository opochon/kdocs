<?php

declare(strict_types=1);

/**
 * GEDv2 — fabrication HORS requête des miniatures de première page (secteur Recherche & aperçu).
 *
 *   php tools/miniatures.php
 *
 * Aucune miniature n'est produite pendant une requête HTTP : la route `/documents/{id}/miniature` se
 * contente de servir l'artefact déjà présent dans `.apercu/`, à côté des documents. Une absence d'outil
 * (LibreOffice, Ghostscript…) n'est pas une erreur du produit : c'est un message nommé.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Ged\Apercu\Miniature;
use Ged\Apercu\Outils;
use Ged\Apercu\Source;
use Ged\Config;

$config = new Config(dirname(__DIR__));
$source = Source::depuis($config);
$dossier = Miniature::dossier($source);
if (!is_dir($dossier) && !mkdir($dossier, 0777, true) && !is_dir($dossier)) {
    fwrite(STDERR, "Impossible de créer le dossier de vignettes : $dossier\n");
    exit(1);
}

$miniature = new Miniature();
$faites = 0;
$sansOutil = 0;

echo "Miniatures d'aperçu — {$source->racine()}\n";
foreach ($source->toutes() as $piece) {
    $dest = Miniature::cheminVignette($piece, $dossier);
    $raison = $miniature->generer($piece, $dest);
    if ($raison === null) {
        $faites++;
        echo sprintf("  ok   %5d  %s\n", $piece->id, $piece->nom());
    } else {
        $sansOutil++;
        echo sprintf("  --   %5d  %s  (%s)\n", $piece->id, $piece->nom(), $raison);
    }

    // Un TIFF n'est pas affichable par un navigateur : on prépare aussi une version PNG plein format.
    if ($piece->estTiff()) {
        $apercu = Miniature::cheminApercuImage($piece, $dossier);
        $resultat = Outils::imageVersPng($piece->chemin, $apercu, 1200);
        echo $resultat === null
            ? sprintf("  ok   %5d  aperçu image %s\n", $piece->id, $piece->nom())
            : sprintf("  --   %5d  %s  (%s)\n", $piece->id, $piece->nom(), $resultat);
    }
}

echo "Bilan : $faites vignette(s) écrite(s), $sansOutil pièce(s) sans outil de rendu.\n";
