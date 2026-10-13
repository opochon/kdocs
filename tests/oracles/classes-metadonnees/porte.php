<?php

declare(strict_types=1);

/**
 * Porte « classes d'objets, métadonnées dynamiques, listes de valeurs nommées » du lot
 * ged-mfiles-classes-1, rejouable par le juge sans PHPUnit :
 *
 *   php tests/oracles/classes-metadonnees/porte.php
 *
 * Rejoue les clauses de la famille M-Files nº 1 sur un fonds JETABLE (aucune donnée réelle, base
 * `gedv2_test` uniquement) : classes et listes dans le fonds (configuration portable), carte
 * dynamique d'un document, validation par type / obligatoire / liste nommée, retrait d'une valeur
 * tracé (le document garde sa valeur), définition portable et réversible. Chaque clause dit OK ou
 * ÉCHEC ; le verdict part en code de sortie (0 = franchie).
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\ClassesMetadata\Carte;
use Ged\ClassesMetadata\Definition;
use Ged\Config;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Listes;
use Ged\Socle\Nomenclature;
use Ged\Socle\Registre;
use Ged\Socle\Schema;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');
$config = new Config($racine);
if ($config->get('DB_NAME') !== 'gedv2_test') {
    fwrite(STDERR, "porte classes-métadonnées : base de test imposée (gedv2_test)\n");
    exit(1);
}

$fonds = sys_get_temp_dir() . '/gedv2-porte-classes-' . bin2hex(random_bytes(4));
mkdir($fonds, 0775, true);
putenv('STORAGE_ROOT=' . $fonds);

$echecs = 0;
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

try {
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    $pdo->exec('DROP TABLE IF EXISTS documents, document_versions, document_deplacements');
    Schema::installer($pdo);

    $nomenclature = new Nomenclature($config);
    $listes = new Listes($config);
    $champs = new Champs($config);
    $carte = new Carte($config);
    $depot = new DepotFichiers($config);

    $etape('classes et listes livrées dans le fonds', static function () use ($nomenclature, $listes, $fonds): string {
        $nomenclature->assurer();
        $listes->assurer();
        exiger(in_array('Contrat', $nomenclature->types(), true), 'la classe Contrat doit être livrée');
        exiger($listes->parCle('statut') !== null, 'la liste Statut doit être livrée');
        exiger(is_file($fonds . '/.ged/nomenclature.json'), 'la définition des classes vit dans le fonds');
        exiger(is_file($fonds . '/.ged/listes.json'), 'les listes nommées vivent dans le fonds');

        return count($nomenclature->types()) . ' classes, ' . count($listes->lister()) . ' listes';
    });

    $cleStatut = null;
    $valeurRetiree = 0;
    $etape('liste nommée : création, valeur, retrait tracé', static function () use ($listes, &$cleStatut, &$valeurRetiree): string {
        $id = $listes->ajouter('Service');
        $listes->ajouterValeur($id, 'Audit');
        $listes->ajouterValeur($id, 'Révision');
        $cleStatut = 'service';
        $audit = $listes->valeurParLibelle($id, 'Audit');
        exiger($audit !== null, 'Audit doit exister');
        $listes->retirerValeur($id, (int) $audit['id'], 'porte@gedv2.test');
        $valeurRetiree = (int) $audit['id'];
        exiger(!$listes->estValeurActive('service', 'Audit'), 'une valeur retirée n’est plus proposée');
        exiger(in_array('Audit', $listes->valeursDe('service', false), true), 'la valeur retirée reste traçable');
        $relue = $listes->valeur($id, $valeurRetiree);
        exiger($relue !== null && ($relue['retire_le'] ?? '') !== '', 'le retrait doit être daté');
        exiger(($relue['retire_par'] ?? '') === 'porte@gedv2.test', 'le retrait doit être attribué');

        return 'valeur Audit retirée le ' . (string) $relue['retire_le'] . ' par ' . $relue['retire_par'];
    });

    $typeContrat = $nomenclature->idDuType('Contrat');
    exiger($typeContrat !== null, 'la classe Contrat doit exister');
    $etape('propriété obligatoire référençant la liste nommée', static function () use ($champs, $carte, $typeContrat): string {
        $champs->ajouter((int) $typeContrat, 'Service responsable', 'liste', [], true, 'service');
        $definition = $champs->pourType('Contrat')[0];
        exiger($definition['liste'] === 'service', 'la propriété référence la liste par sa clé');
        exiger($definition['obligatoire'] === true, 'la propriété est obligatoire');
        exiger(in_array('Révision', $definition['options'], true), 'les valeurs viennent de la liste nommée');
        exiger(!in_array('Audit', $definition['options'], true), 'une valeur retirée n’est plus proposée');
        $carteClasse = $carte->pourClasse('Contrat');
        exiger(array_column($carteClasse['proprietes'], 'cle') === ['service-responsable'], 'la carte de la classe porte la propriété');

        return 'carte de Contrat : ' . implode(', ', array_column($carteClasse['proprietes'], 'cle'));
    });

    $chemin = null;
    $etape('carte dynamique d’un document, valeur dans le .meta.json', static function () use ($depot, $carte, $fonds, &$chemin): string {
        $source = $fonds . '/source-contrat.txt';
        file_put_contents($source, "contrat synthétique\n");
        $id = $depot->deposer($source, ['titre' => 'Contrat synthétique', 'type' => 'Contrat', 'date' => '2026-09-01']);
        $chemin = (string) $depot->fiche($id)['chemin_relatif'];
        $carte->poser($chemin, 'service-responsable', 'Révision');
        $document = $carte->pourDocument($chemin);
        exiger($document['classe'] === 'Contrat', 'la carte suit la classe du document');
        exiger($document['proprietes'][0]['valeur'] === 'Révision', 'la valeur se relit');
        $meta = json_decode((string) file_get_contents($fonds . '/' . $chemin . '.meta.json'), true);
        exiger(($meta['champs']['service-responsable'] ?? null) === 'Révision', 'la valeur vit dans le .meta.json');

        return $chemin;
    });

    $etape('validation : obligatoire et liste nommée', static function () use ($carte): string {
        try {
            $carte->valider('Contrat', []);
            throw new RuntimeException('une propriété obligatoire vide devait être refusée');
        } catch (RuntimeException $e) {
            exiger(str_contains($e->getMessage(), 'obligatoire'), 'le refus doit nommer l’obligation');
        }
        try {
            $carte->valider('Contrat', ['service-responsable' => 'Inventé']);
            throw new RuntimeException('une valeur hors liste devait être refusée');
        } catch (RuntimeException $e) {
            exiger(str_contains($e->getMessage(), 'service'), 'le refus doit nommer la liste');
        }
        exiger($carte->valider('Contrat', ['service-responsable' => 'Révision']) === ['service-responsable' => 'Révision'],
            'une valeur active passe');

        return 'obligatoire et liste nommée contrôlées';
    });

    $etape('le document garde la valeur retirée (aucune perte)', static function () use ($listes, $carte, $chemin): string {
        $service = $listes->parCle('service');
        exiger($service !== null, 'la liste service existe');
        $revision = $listes->valeurParLibelle((int) $service['id'], 'Révision');
        exiger($revision !== null, 'Révision existe');
        $listes->retirerValeur((int) $service['id'], (int) $revision['id'], 'porte@gedv2.test');
        exiger($carte->valeur((string) $chemin, 'service-responsable') === 'Révision',
            'le document qui portait la valeur la garde, la valeur est traçable');
        try {
            $carte->valider('Contrat', ['service-responsable' => 'Révision']);
            throw new RuntimeException('une valeur retirée ne doit plus être proposable');
        } catch (RuntimeException $e) {
            exiger(str_contains($e->getMessage(), 'service'), 'le refus nomme la liste');
        }

        return 'valeur retirée conservée sur le document';
    });

    $etape('définition portable et réversible', static function () use ($fonds): string {
        $definition = new Definition(new Config(dirname(__DIR__, 3)));
        $export = $definition->exporter();
        @unlink($fonds . '/.ged/nomenclature.json');
        @unlink($fonds . '/.ged/listes.json');
        Registre::oublier();
        $definition->importer($export);
        $relu = $definition->exporter();
        exiger($export === $relu, 'export → import → export doit rendre la même définition');

        return count($export['classes']) . ' classes, ' . count($export['listes']) . ' listes reversibles';
    });
} finally {
    Registre::oublier();
    nettoyer($fonds);
    putenv('STORAGE_ROOT');
}

echo $echecs === 0 ? "PORTE FRANCHIE : 7/7 clauses\n" : "PORTE ROUGE : $echecs clause(s) en échec\n";
exit($echecs === 0 ? 0 : 1);

function nettoyer(string $chemin): void
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
