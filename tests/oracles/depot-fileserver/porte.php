<?php

declare(strict_types=1);

/**
 * Porte « archivage / versions file-server » du lot ged-mfiles-oracle-1, rejouable par le juge
 * sans PHPUnit :
 *
 *   php tests/oracles/depot-fileserver/porte.php
 *
 * Rejoue les clauses de docs/ARCHIVAGE-FILESERVER.md sur un fonds JETABLE (aucune donnée réelle,
 * aucune base) : scan initial avec hash de tous les fichiers, modification / déplacement /
 * suppression hors GED retrouvés et tracés, versions dans un dossier masqué doublées d'un dépôt
 * séparé, réversibilité vers une arborescence classique. Chaque clause dit OK ou ÉCHEC ; le verdict
 * part en code de sortie (0 = franchie).
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';

use Ged\DepotFileserver\Arborescence;
use Ged\DepotFileserver\DepotSepare;
use Ged\DepotFileserver\HorlogeFigee;
use Ged\DepotFileserver\Manifeste;
use Ged\DepotFileserver\Reversibilite;
use Ged\DepotFileserver\Scanner;
use Ged\DepotFileserver\Veille;
use Ged\DepotFileserver\Versions;
use Ged\Tests\DepotFileserver\SupportDepot;

$echecs = 0;
$fonds = SupportDepot::dossier('porte-fonds');
$garantie = SupportDepot::dossier('porte-garantie');
$export = SupportDepot::dossier('porte-export');

$etape = function (string $nom, callable $clause) use (&$echecs): void {
    try {
        $detail = $clause();
        echo "OK    $nom" . ($detail !== null ? " — $detail" : '') . "\n";
    } catch (Throwable $e) {
        ++$echecs;
        echo "ÉCHEC $nom — {$e->getMessage()}\n";
    }
};

function exiger(bool $vrai, string $cause): void
{
    if (!$vrai) {
        throw new RuntimeException($cause);
    }
}

$veille = static fn (string $instant): Veille => new Veille($fonds, new DepotSepare($garantie), new HorlogeFigee($instant));

try {
    // Clause 1 — scan initial : empreinte de TOUS les fichiers, garantis et versionnés.
    $etape('scan initial (hash de tous les fichiers)', static function () use ($fonds, $garantie): string {
        SupportDepot::ecrire($fonds . '/2026/facture.txt', 'facture-v1');
        SupportDepot::ecrire($fonds . '/2026/contrat.txt', 'contrat-v1');
        SupportDepot::ecrire($fonds . '/divers/notes.md', 'notes');
        $stats = (new Veille($fonds, new DepotSepare($garantie), new HorlogeFigee('2026-10-13T10:00:00+02:00')))->passer();
        exiger($stats['fichiers'] === 3, 'trois fichiers attendus, vu ' . $stats['fichiers']);
        exiger($stats['versions'] === 3, 'trois versions v001 attendues');
        $depot = new DepotSepare($garantie);
        exiger($depot->aCopie(hash('sha256', 'facture-v1')), 'facture non garantie');
        exiger($depot->aCopie(hash('sha256', 'contrat-v1')), 'contrat non garanti');

        return '3 fichiers empreintés, 3 versions, 3 garanties';
    });

    // Clause 2 — modification hors GED : l'ancienne version reste, la nouvelle est garantie.
    $etape('modification hors GED (version masquée + garantie)', static function () use ($fonds, $garantie, $veille): string {
        SupportDepot::ecrire($fonds . '/2026/facture.txt', 'facture-v2');
        $stats = $veille('2026-10-13T11:00:00+02:00')->passer();
        exiger($stats['modifies'] === 1, 'une modification attendue');
        $dossier = $fonds . '/2026/.facture.txt';
        exiger(is_file($dossier . '/v001_' . substr(hash('sha256', 'facture-v1'), 0, 8) . '.txt'), 'v001 absente du dossier masqué');
        exiger(is_file($dossier . '/v002_' . substr(hash('sha256', 'facture-v2'), 0, 8) . '.txt'), 'v002 absente');
        $depot = new DepotSepare($garantie);
        exiger($depot->aCopie(hash('sha256', 'facture-v1')) && $depot->aCopie(hash('sha256', 'facture-v2')), 'les deux contenus doivent être garantis');

        return 'v001 et v002 dans .facture.txt, doublées dans le dépôt séparé';
    });

    // Clause 3 — déplacement hors GED : retrouvé par empreinte, tracé.
    $etape('déplacement hors GED (retrouvé par empreinte)', static function () use ($fonds, $garantie, $veille): string {
        SupportDepot::ecrire($fonds . '/2026/archives/contrat.txt', 'contrat-v1');
        @unlink($fonds . '/2026/contrat.txt');
        $stats = $veille('2026-10-13T12:00:00+02:00')->passer();
        exiger($stats['deplaces'] === 1, 'un déplacement attendu');
        $journal = (new Veille($fonds, new DepotSepare($garantie), new HorlogeFigee('2026-10-13T12:00:00+02:00')))->journal();
        exiger(($journal[0]['type'] ?? '') === 'deplace', 'le journal doit nommer le déplacement');
        exiger(($journal[0]['ancien_chemin'] ?? '') === '2026/contrat.txt', 'l’emplacement précédent doit être tracé');
        $manifeste = (new Manifeste($fonds))->lire()['fichiers'];
        exiger(($manifeste['2026/archives/contrat.txt']['empreinte'] ?? '') === hash('sha256', 'contrat-v1'), 'le contenu doit être retrouvé à sa nouvelle place');

        return 'trace 2026/contrat.txt → 2026/archives/contrat.txt, même empreinte';
    });

    // Clause 4 — suppression hors GED : datée, jamais effacée, contenu garanti.
    $etape('suppression hors GED (datée, contenu garanti)', static function () use ($fonds, $garantie, $veille): string {
        @unlink($fonds . '/divers/notes.md');
        $stats = $veille('2026-10-13T13:00:00+02:00')->passer();
        exiger($stats['supprimes'] === 1, 'une suppression attendue');
        $manifeste = (new Manifeste($fonds))->lire()['fichiers'];
        exiger(($manifeste['divers/notes.md']['etat'] ?? '') === 'absent', 'la ligne doit être datée absente');
        exiger((new DepotSepare($garantie))->aCopie(hash('sha256', 'notes')), 'le contenu supprimé doit rester garanti');

        return 'divers/notes.md absent au manifeste, contenu conservé';
    });

    // Clause 5 — vérification de la garantie puis réversibilité vers une arborescence classique.
    $etape('garantie vérifiée et réversibilité', static function () use ($fonds, $veille, $export): string {
        $verification = $veille('2026-10-13T14:00:00+02:00')->verifierGarantie();
        exiger($verification['manquants'] === [], 'aucun contenu ne doit manquer à la garantie');
        $reversibilite = new Reversibilite($fonds);
        $reversibilite->exporter($export);
        $controle = $reversibilite->verifier($export);
        exiger($controle['ecarts'] === [], 'l’export doit être identique : ' . implode(', ', $controle['ecarts']));
        exiger(!is_dir($export . '/' . Arborescence::INTERNE), 'l’export ne doit pas contenir le dossier interne');

        return $controle['identiques'] . ' fichier(s) exportés, 0 écart';
    });

    // Clause 6 — restauration d'une version sans perte (le contenu courant est archivé d'abord).
    $etape('restauration d’une version sans perte', static function () use ($fonds): string {
        $fichier = $fonds . '/2026/facture.txt';
        $versions = new Versions();
        $v1 = substr(hash('sha256', 'facture-v1'), 0, 8);
        $versions->restaurer($fichier, $v1);
        exiger((string) file_get_contents($fichier) === 'facture-v1', 'la v1 doit être restaurée');
        $empreintes = array_column($versions->lister($fichier), 'empreinte');
        exiger(in_array($v1, $empreintes, true), 'la v1 doit rester archivée');
        exiger(in_array(substr(hash('sha256', 'facture-v2'), 0, 8), $empreintes, true), 'la v2 courante doit être archivée avant restauration');

        return 'contenu courant archivé, v1 restaurée';
    });
} finally {
    SupportDepot::supprimer($fonds);
    SupportDepot::supprimer($garantie);
    SupportDepot::supprimer($export);
}

echo $echecs === 0 ? "PORTE FRANCHIE : 6/6 clauses\n" : "PORTE ROUGE : $echecs clause(s) en échec\n";
exit($echecs === 0 ? 0 : 1);
