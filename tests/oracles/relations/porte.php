<?php

declare(strict_types=1);

/**
 * Porte « relations entre objets, composition parent-enfant, références croisées » du lot
 * ged-mfiles-relations-1, rejouable par le juge sans PHPUnit :
 *
 *   php tests/oracles/relations/porte.php
 *
 * Rejoue la famille M-Files nº 2 sur un fonds JETABLE (aucune donnée réelle, base `gedv2_test`
 * uniquement) : définitions de types dans le fonds (configuration portable), valeurs dans le
 * `.meta.json`, liens typés navigables dans les deux sens, composition parent-enfant et détachement,
 * intégrité des références croisées (objet déplacé retrouvé par empreinte, objet supprimé traçable),
 * définition portable et réversible. Chaque clause dit OK ou ÉCHEC ; le verdict part en code de sortie.
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Registre;
use Ged\Socle\Relations;
use Ged\Socle\Schema;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');
$config = new Config($racine);
if ($config->get('DB_NAME') !== 'gedv2_test') {
    fwrite(STDERR, "porte relations : base de test imposée (gedv2_test)\n");
    exit(1);
}

$fonds = sys_get_temp_dir() . '/gedv2-porte-relations-' . bin2hex(random_bytes(4));
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

    $relations = new Relations($config);
    $depot = new DepotFichiers($config);

    $deposer = static function (string $titre, string $type) use ($depot, $fonds): array {
        $source = $fonds . '/source-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($source, "pièce synthétique $titre\n");
        $id = $depot->deposer($source, ['titre' => $titre, 'type' => $type, 'date' => '2026-09-01']);

        return [$id, (string) $depot->fiche($id)['chemin_relatif']];
    };

    $etape('définitions de types livrées dans le fonds', static function () use ($relations, $fonds): string {
        $relations->assurer();
        $noms = array_column($relations->types(), 'nom');
        exiger($noms === ['Référence', 'Dépendance', 'Affectation', 'Sous-objet'], 'les quatre types M-Files sont livrés');
        exiger(is_file($fonds . '/.ged/relations.json'), 'la définition des types vit dans le fonds');
        $tables = Base::pdo(new Config(dirname(__DIR__, 3)))->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        exiger(!in_array('socle_relations', $tables, true), 'aucune table nouvelle (plafond de 20)');

        return implode(', ', $noms);
    });

    $a = ''; $b = '';
    $etape('lien typé navigable dans les deux sens', static function () use ($deposer, $relations, &$a, &$b): string {
        [, $a] = $deposer('Facture A', 'Facture');
        [, $b] = $deposer('Contrat B', 'Contrat');
        $relations->lier($a, $b, 'reference', 'porte@gedv2.test');
        $source = $relations->pourDocument($a);
        $cible = $relations->pourDocument($b);
        exiger(count($source) === 1 && count($cible) === 1, 'le lien doit être visible des deux côtés');
        exiger($source[0]['sens'] === 'sortante' && $cible[0]['sens'] === 'entrante', 'les sens sont nommés');
        exiger($source[0]['type_nom'] === 'Référence', 'le type est nommé');
        exiger($relations->compter($a) === 1 && $relations->compter($b) === 1, 'le comptage suit les deux sens');

        return 'A → B : Référence';
    });

    $etape('retrait daté et attribué, la trace reste', static function () use ($deposer, $relations, $fonds, &$a): string {
        [, $c] = $deposer('Reçu C', 'Reçu');
        $id = $relations->lier($a, $c, 'reference');
        $relations->retirer($a, $id, 'porte@gedv2.test');
        exiger($relations->compter($a) === 1, 'le lien retiré n’est plus actif (il reste le lien A → B)');
        $meta = json_decode((string) file_get_contents($fonds . '/' . $a . '.meta.json'), true);
        $relique = null;
        foreach ((array) ($meta['relations'] ?? []) as $entree) {
            if ((int) ($entree['id'] ?? 0) === $id) {
                $relique = $entree;
            }
        }
        exiger(is_array($relique) && ($relique['actif'] ?? true) === false, 'la relation retirée reste dans le dossier');
        exiger(($relique['retire_le'] ?? '') !== '' && ($relique['retire_par'] ?? '') === 'porte@gedv2.test', 'retrait daté et attribué');

        return 'relation nº ' . $id . ' retirée le ' . (string) $relique['retire_le'];
    });

    $dossier = ''; $piece = '';
    $etape('composition parent-enfant et détachement', static function () use ($deposer, $relations, &$dossier, &$piece): string {
        [, $dossier] = $deposer('Dossier client', 'Courrier');
        [, $piece] = $deposer('Pièce A', 'Facture');
        $relations->lier($dossier, $piece, 'sous-objet');
        exiger($relations->compterEnfants($dossier) === 1, 'le dossier contient une pièce');
        exiger(($relations->parents($piece)[0]['chemin'] ?? '') === $dossier, 'la pièce appartient à son dossier');
        $detaches = $relations->detacher($dossier, $piece, 'porte@gedv2.test');
        exiger($detaches === 1 && $relations->compterEnfants($dossier) === 0, 'détacher rend la pièce indépendante');

        return 'sous-objet détaché, geste tracé';
    });

    $etape('référence vers un objet déplacé retrouvée par empreinte', static function () use ($deposer, $depot, $relations): string {
        [$id, $chemin] = $deposer('Contrat déplacé', 'Facture');
        [, $source] = $deposer('Facture source', 'Facture');
        $relations->lier($source, $chemin, 'reference');
        $depot->mettreAJour($id, ['type' => 'Contrat'], 'porte@gedv2.test');
        $nouveau = (string) $depot->fiche($id)['chemin_relatif'];
        $carte = $relations->pourDocument($source);
        exiger(($carte[0]['etat'] ?? '') === 'deplace' && ($carte[0]['cible'] ?? '') === $nouveau, 'le lien suit le déplacement');

        return 'cible retrouvée en ' . $nouveau;
    });

    $etape('référence vers un objet supprimé reste traçable', static function () use ($deposer, $depot, $relations): string {
        [$id, $chemin] = $deposer('Contrat à la corbeille', 'Contrat');
        [, $source] = $deposer('Facture source 2', 'Facture');
        $relations->lier($source, $chemin, 'reference');
        $depot->miseALaCorbeille($id, 'porte@gedv2.test');
        $carte = $relations->pourDocument($source);
        exiger(count($carte) === 1, 'le lien n’est pas effacé en silence');
        exiger(($carte[0]['etat'] ?? '') === 'corbeille', 'l’état dit la corbeille');
        exiger(($carte[0]['cible_titre'] ?? '') !== '' && ($carte[0]['empreinte'] ?? '') !== '', 'dernier titre et empreinte conservés');

        return 'cible à la corbeille, titre « ' . (string) $carte[0]['cible_titre'] . ' » conservé';
    });

    $etape('définition portable et réversible', static function () use ($relations, $fonds): string {
        $definition = $relations->exporter();
        @unlink($fonds . '/.ged/relations.json');
        Registre::oublier();
        $relations->importer($definition);
        exiger($definition === $relations->exporter(), 'export → import → export rend la même définition');

        return count($definition['types']) . ' types réversibles';
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
