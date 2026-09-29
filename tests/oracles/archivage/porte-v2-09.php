<?php

declare(strict_types=1);

/**
 * Porte V2-09 du secteur Archivage, rejouable par le juge (Claude Code) sans PHPUnit :
 *
 *   php tests/oracles/archivage/porte-v2-09.php
 *
 * Rejoue les quatre clauses du lot F11 sur la base de test gedv2_test et un fonds éphémère (aucune donnée
 * réelle) : scellement horodaté vérifiable, mutations refusées au niveau du service par toutes les voies,
 * vérificateur qui trouve l'octet changé, rétention juste sur horloge figée sans aucune suppression.
 * La TSA est SIMULÉE ici (aucun réseau) ; en recette, `Scelleur::depuisConfig` utilise `TSA_URL`.
 * Chaque clause dit OK ou ÉCHEC avec sa cause ; le verdict part en code de sortie (0 = franchie).
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';

use Ged\Archivage\ErreurScellement;
use Ged\Archivage\HorlogeFigee;
use Ged\Archivage\Retention;
use Ged\Archivage\Scellement;
use Ged\Archivage\Scelleur;
use Ged\Archivage\TsaSimulee;
use Ged\Archivage\Verificateur;
use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Indexeur;
use Ged\Workflows\Moteur;
use Ged\Workflows\Regles;

$echecs = 0;

$etape = function (string $nom, callable $clause) use (&$echecs): void {
    try {
        $detail = $clause();
        echo "OK    $nom" . ($detail !== null ? " — $detail" : '') . "\n";
    } catch (Throwable $e) {
        $echecs++;
        echo "ÉCHEC $nom — {$e->getMessage()}\n";
    }
};

function exiger(bool $vrai, string $cause): void
{
    if (!$vrai) {
        throw new RuntimeException($cause);
    }
}

/** @return array{0:int, 1:string} code de sortie, sortie de la commande. */
function commande(string $cheminRel, string $args = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/' . $cheminRel) . ($args !== '' ? ' ' . $args : '') . ' 2>&1';
    exec($cmd, $sorties, $code);

    return [$code, implode("\n", $sorties)];
}

// --- Fonds éphémère, base de test, horloge figée -------------------------------------------------
$bac = sys_get_temp_dir() . '/gedv2-porte-v2-09-' . bin2hex(random_bytes(4));
$fonds = $bac . '/fonds';
mkdir($bac . '/depots', 0775, true);
putenv('STORAGE_ROOT=' . $fonds);
putenv('DB_NAME=gedv2_test');   // jamais une autre base que celle des tests
$config = new Config($racine);
exiger($config->get('DB_NAME') === 'gedv2_test', 'la porte ne tourne que sur gedv2_test (DB_NAME=' . $config->get('DB_NAME') . ')');
Base::assurerBase($config);
$pdo = Base::pdo($config);
\Ged\Socle\Schema::installer($pdo);
\Ged\Workflows\Schema::installer($pdo);

$horloge = new HorlogeFigee('2026-04-01 08:00:00');
$depot = new DepotFichiers($config);
$retention = new Retention($fonds, $horloge);
$scelleur = new Scelleur($depot, new TsaSimulee($horloge), $horloge, $retention);

$poses = [];
$deposer = function (string $nom, array $meta) use ($depot, $bac, &$poses): int {
    $meta['emetteur'] = (string) ($meta['emetteur'] ?? 'Émetteur Fictif') . ' ' . bin2hex(random_bytes(3));
    $source = $bac . '/depots/' . $nom;
    file_put_contents($source, 'contenu synthétique ' . $nom);
    $id = $depot->deposer($source, $meta);
    $poses[] = $id;

    return $id;
};
$absolu = static fn (int $id): string => $fonds . '/' . $pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();

echo "Porte V2-09 — base gedv2_test, fonds éphémère $fonds\n";

// --- Clause 1 : scellement horodaté vérifiable --------------------------------------------------
$idA = $deposer('facture-porte-a.pdf', ['titre' => 'Facture porte A', 'type' => 'facture', 'date' => '2026-03-01']);
$etape('1. scellement : jeton TSA dans le dossier, empreinte et échéance justes', function () use ($scelleur, $absolu, $idA): ?string {
    $resultat = $scelleur->sceller($idA, 'olivia');
    $scellement = Scellement::lire($absolu($idA), $idA);
    exiger($scellement !== null, 'justificatif de scellement absent');
    exiger(is_file(Scellement::cheminJeton($absolu($idA))), 'jeton TSA absent du dossier');
    exiger(\Ged\Archivage\Empreintes::scellee($scellement['empreinte_fichier'], $scellement['empreinte_meta'])
        === $scellement['empreinte_scellee'], 'empreinte scellée incohérente');
    exiger($scellement['echeance'] === '2036-03-01', 'échéance fausse : ' . $scellement['echeance']);
    exiger(!$resultat['already_sealed'], 'premier scellement marqué déjà scellé');

    return 'empreinte ' . substr($scellement['empreinte_scellee'], 0, 12) . '… échéance 2036-03-01';
});

// --- Clause 3a : vérificateur, 0 écart sur un fonds sain ----------------------------------------
$etape('3a. verifier-scellements.php : 0 écart sur un fonds sain', function () use ($racine): ?string {
    [$code, $sortie] = commande('tools/verifier-scellements.php');
    exiger($code === 0, "le vérificateur a signalé un écart : $sortie");
    exiger(str_contains($sortie, '0 écart'), "verdict inattendu : $sortie");

    return trim((string) strrchr($sortie, 'VERDICT'));
});

// --- Clause 4 : rétention juste, aucune suppression ---------------------------------------------
$etape('4. rétention : échéance juste sur horloge figée, aucune suppression automatique', function () use ($retention, $horloge, $absolu, $idA): ?string {
    exiger($retention->aEcheance() === [], 'une facture de 2026 ne peut pas être à échéance en 2026');
    $horloge->regler('2036-03-01 00:00:00');
    $liste = $retention->aEcheance();
    exiger(count($liste) === 1 && $liste[0]['document_id'] === $idA, 'la liste « à échéance » ne contient pas la facture');
    $horloge->regler('2100-01-01 00:00:00');
    exiger(is_file($absolu($idA)) && is_file(Scellement::cheminJson($absolu($idA))), 'rien ne doit disparaître sans décision humaine');
    $horloge->regler('2026-04-01 08:00:00');

    return '2036-03-01, toujours rien d’effacé';
});

// --- Clause 2 : mutations refusées par toutes les voies -----------------------------------------
$etape('2a. service : mise à jour et corbeille refusées et nommées', function () use ($depot, $absolu, $idA): ?string {
    foreach (['mise à jour' => static fn () => $depot->mettreAJour($idA, ['titre' => 'pirate'], 'olivia'),
        'corbeille' => static fn () => $depot->miseALaCorbeille($idA, 'olivia')] as $libelle => $geste) {
        try {
            $geste();
            exiger(false, "$libelle acceptée sur un document scellé");
        } catch (ErreurScellement $e) {
            exiger(str_contains($e->getMessage(), 'scellé') && $e->getCode() === 403, 'refus non nommé : ' . $e->getMessage());
        }
    }
    exiger(is_file($absolu($idA)), 'le fichier a bougé malgré le refus');

    return 'mise à jour et corbeille refusées (403)';
});

$etape('2b. workflow : la règle ne peut pas écrire dans un document scellé', function () use ($pdo, $depot, $idA): ?string {
    $regles = new Regles($pdo);
    $regles->creer(['nom' => 'porte-v2-09-' . bin2hex(random_bytes(4)), 'declencheur' => 'document_modifie',
        'conditions' => [], 'actions' => [['type' => 'poser', 'champ' => 'titre', 'valeur' => 'écrit par la règle']], 'actif' => true]);
    $fiche = $depot->fiche($idA);
    $plan = (new Moteur($pdo, $depot))->executer('document_modifie', $fiche, 'workflow');
    exiger($plan['erreurs'] !== [], 'le workflow a écrit sans erreur sur un document scellé');
    exiger($depot->fiche($idA)['titre'] === $fiche['titre'], 'le titre a changé malgré le scellement');

    return count($plan['erreurs']) . ' action(s) refusée(s), titre intact';
});

$etape('2c. indexeur : fichier altéré → alerte « scellé altéré », jamais de nouvelle version', function () use ($config, $absolu, $idA): ?string {
    $indexeur = new Indexeur($config);
    $avant = count(glob(dirname($absolu($idA)) . '/.versions/' . basename($absolu($idA)) . '/*') ?: []);
    file_put_contents($absolu($idA), (string) file_get_contents($absolu($idA)) . 'X');
    $indexeur->passer();
    $stats = $indexeur->passer();
    exiger($stats['scelles_alteres'] === 1, 'alerte « scellé altéré » non levée');
    exiger($stats['versions'] === 0, 'une nouvelle version a été créée silencieusement');
    $apres = count(glob(dirname($absolu($idA)) . '/.versions/' . basename($absolu($idA)) . '/*') ?: []);
    exiger($avant === $apres, 'le dossier .versions/ a bougé');
    exiger(is_file(dirname($absolu($idA)) . '/.scellement/alertes.ndjson'), 'l’alerte n’est pas écrite dans le dossier');

    return 'alerte tracée, aucune version silencieuse';
});

// --- Clause 3b : un octet changé est trouvé -----------------------------------------------------
$etape('3b. verifier-scellements.php : un octet changé est trouvé', function () use ($racine, $scelleur, $deposer, $absolu): ?string {
    $idB = $deposer('facture-porte-b.pdf', ['titre' => 'Facture porte B', 'type' => 'facture', 'date' => '2026-02-01']);
    $scelleur->sceller($idB, 'marc');
    $octets = (string) file_get_contents($absolu($idB));
    file_put_contents($absolu($idB), substr_replace($octets, 'z', 3, 1));
    [$code, $sortie] = commande('tools/verifier-scellements.php');
    exiger($code !== 0, 'le vérificateur n’a pas vu l’octet changé');
    exiger(stripos($sortie, 'fichier') !== false, "l'écart n'est pas nommé : $sortie");

    return trim((string) strrchr($sortie, 'VERDICT'));
});

// --- Remise en état -----------------------------------------------------------------------------
foreach ($poses as $id) {
    $pdo->exec('DELETE FROM document_deplacements WHERE document_id = ' . $id);
    $pdo->exec('DELETE FROM document_versions WHERE document_id = ' . $id);
    $pdo->exec('DELETE FROM documents WHERE id = ' . $id);
}
putenv('STORAGE_ROOT');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bac, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($bac);

echo $echecs === 0 ? "PORTE V2-09 : franchie\n" : "PORTE V2-09 : REFUSÉE ($echecs clause(s) en échec)\n";
exit($echecs === 0 ? 0 : 1);
