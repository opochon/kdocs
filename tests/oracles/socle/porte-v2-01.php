<?php

declare(strict_types=1);

/**
 * Porte V2-01 du secteur Socle, rejouable par le juge (Codex) sans PHPUnit :
 *
 *   php tests/oracles/socle/porte-v2-01.php
 *
 * Rejoue les six clauses du lot sur la base de test gedv2_test et un fonds éphémère (aucune donnée
 * réelle) : installation, dépôt, version par modification hors application, index suivant le dossier,
 * reconstruction identique depuis le dossier, corbeille sans suppression. Chaque clause dit OK ou
 * ÉCHEC avec sa cause ; le verdict final part en code de sortie (0 = franchie). La base de test est
 * remise en installation fraîche à la fin.
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Export;
use Ged\Socle\Indexeur;

$echecs = 0;
$tablesAttendues = [
    'classement_choix', 'comptes', 'document_collaboration_evenements', 'document_deplacements',
    'document_liens', 'document_notes', 'document_partages', 'document_versions', 'documents',
    'ingestion_messages', 'ingestion_travaux', 'installation', 'jonctions_jetons', 'recherche_textes',
    'reconstruction_index_jobs', 'securite_dossiers', 'securite_dossiers_droits', 'securite_groupes',
    'securite_groupes_comptes', 'securite_permissions', 'securite_roles', 'securite_roles_permissions',
    'workflow_definitions', 'workflow_instances', 'workflow_journal',
];

/** Exécute une clause de la porte : affiche OK ou ÉCHEC avec la cause. */
$etape = function (string $nom, callable $clause) use (&$echecs): void {
    try {
        $detail = $clause();
        echo "OK    $nom" . ($detail !== null ? " — $detail" : '') . "\n";
    } catch (Throwable $e) {
        $echecs++;
        echo "ÉCHEC $nom — {$e->getMessage()}\n";
    }
};

/** Vérifie une affirmation ; sinon lève avec la cause (une erreur se nomme). */
function exiger(bool $vrai, string $cause): void
{
    if (!$vrai) {
        throw new RuntimeException($cause);
    }
}

/** Vide la base de TEST de toutes ses tables (les secteurs recréent les leurs dans leurs tests). */
function viderBase(Config $config): void
{
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `$table`");
    }
}

/** @return array{int, string} code de sortie, sortie */
function commande(string $cheminRel, string $args): array
{
    $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/' . $cheminRel)
        . ' gedv2_test ' . $args . ' 2>&1';
    exec($commande, $sorties, $code);

    return [$code, implode("\n", $sorties)];
}

// --- Fonds éphémère et base de test ------------------------------------------------------------
$fonds = sys_get_temp_dir() . '/gedv2-porte-v2-01-' . bin2hex(random_bytes(4));
mkdir("$fonds/depots", 0775, true);
putenv('STORAGE_ROOT=' . $fonds . '/fonds');
putenv('DB_NAME=gedv2_test');   // jamais une autre base que celle des tests, ici ni ailleurs
$config = new Config($racine);
exiger($config->get('DB_NAME') === 'gedv2_test', 'la porte ne tourne que sur gedv2_test (DB_NAME=' . $config->get('DB_NAME') . ')');

echo "Porte V2-01 — base gedv2_test, fonds éphémère $fonds/fonds\n";

// --- Les six clauses ---------------------------------------------------------------------------
$pdo = null;

$etape('1. installateur : schéma neuf, admin posé, base peuplée refusée sans --remplacer', function () use (&$pdo, $config, $racine, $tablesAttendues): ?string {
    viderBase($config);
    [$code, $sortie] = commande('tools/installer.php', '--admin=admin@gedv2.test');
    exiger($code === 0, "installation échouée : $sortie");
    $pdo = Base::pdo($config);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    exiger($tables === $tablesAttendues, 'tables inattendues : ' . implode(', ', $tables));
    exiger((int) $pdo->query("SELECT COUNT(*) FROM comptes WHERE identifiant = 'admin@gedv2.test'")->fetchColumn() === 1,
        'le compte admin manque');

    [$codeRefus, $refus] = commande('tools/installer.php', '--admin=autre@gedv2.test');
    exiger($codeRefus !== 0, 'une base peuplée doit être refusée sans --remplacer');
    exiger(str_contains($refus, 'peuplée'), "le refus doit se nommer : $refus");

    return count($tables) . ' tables, admin posé';
});

$depot = new DepotFichiers($config);
$indexeur = new Indexeur($config);

$etape('2. deposer range, écrit le .meta.json, crée la v1, indexe', function () use ($depot, $fonds, &$pdo): ?string {
    file_put_contents("$fonds/depots/facture-an-5001.pdf", '%PDF-1.4 facture AN-5001');
    $id = $depot->deposer("$fonds/depots/facture-an-5001.pdf", [
        'titre' => 'Facture AN-5001', 'type' => 'facture', 'emetteur' => 'Atelier X',
        'date' => '2026-09-29', 'montant' => 512.4, 'etiquettes' => ['urgent'], 'source' => 'ecran',
    ]);
    $rel = '2026/facture/Atelier_X/Facture_AN-5001.pdf';
    exiger(is_file($fonds . '/fonds/' . $rel), "fichier non rangé : $rel");
    exiger(is_file($fonds . '/fonds/' . $rel . '.meta.json'), '.meta.json absent à côté du fichier');
    exiger(is_file(dirname($fonds . '/fonds/' . $rel) . '/.versions/' . basename($rel)
        . '/v001_' . substr(hash('sha256', '%PDF-1.4 facture AN-5001'), 0, 8) . '.pdf'), 'la version 1 manque');
    $ligne = $pdo->query('SELECT empreinte, supprime_le FROM documents WHERE id = ' . $id)->fetch();
    exiger($ligne !== false && $ligne['supprime_le'] === null, 'la ligne d’index manque');

    return "document $id rangé en $rel";
});

$etape('3. modification hors application : v2 archivée, v1 intacte', function () use ($depot, $indexeur, $fonds): ?string {
    $rel = '2026/facture/Atelier_X/Facture_AN-5001.pdf';
    file_put_contents($fonds . '/fonds/' . $rel, '%PDF-1.4 facture AN-5001 CORRIGÉE');
    $indexeur->passer();
    $r = $indexeur->passer();   // un relevé pour la stabilité, un pour le traitement

    $dossier = dirname($fonds . '/fonds/' . $rel) . '/.versions/' . basename($rel);
    $archives = glob($dossier . '/*') ?: [];
    sort($archives);
    exiger(count($archives) === 2, count($archives) . ' archive(s) au lieu de 2 (v1 + v2)');
    exiger(str_contains((string) file_get_contents($archives[0]), 'AN-5001 CORRIGÉE') === false
        && str_contains((string) file_get_contents($archives[0]), 'facture AN-5001'), 'la v1 n’est pas intacte');
    exiger(str_contains((string) file_get_contents($archives[1]), 'CORRIGÉE'), 'la v2 n’a pas le contenu modifié');
    exiger($r['versions'] === 1, 'le passage n’a pas constaté exactement une version');

    return 'v1 intacte, v2 archivée, index à jour';
});

$etape('4. dossier renommé hors application : l’index suit, même identifiant, déplacement tracé', function () use ($indexeur, $fonds, &$pdo): ?string {
    $avant = (int) $pdo->query("SELECT id FROM documents WHERE chemin_relatif = '2026/facture/Atelier_X/Facture_AN-5001.pdf'")->fetchColumn();
    exiger($avant > 0, 'document introuvable avant renommage');

    rename($fonds . '/fonds/2026/facture/Atelier_X', $fonds . '/fonds/2026/facture/Atelier_Renommé');
    $indexeur->passer();
    $r = $indexeur->passer();

    $apresRel = '2026/facture/Atelier_Renommé/Facture_AN-5001.pdf';
    $apres = (int) $pdo->query('SELECT id FROM documents WHERE chemin_relatif = ' . $pdo->quote($apresRel))->fetchColumn();
    exiger($apres === $avant, "l’index n’a pas suivi (id $avant → $apres)");
    exiger((int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 1, 'un doublon est né du renommage');
    $trace = $pdo->query('SELECT chemin_avant, cause FROM document_deplacements WHERE document_id = ' . $avant)->fetch();
    exiger($trace !== false && $trace['cause'] === 'indexeur', 'le déplacement n’est pas tracé comme constat');
    exiger($r['deplaces'] === 1, 'le passage n’a pas constaté exactement un déplacement');

    return "id $avant suivi de Atelier_X vers Atelier_Renommé";
});

$etape('6. corbeille : dossier .corbeille/ + drapeau, restauration, zéro suppression', function () use ($depot, $fonds, &$pdo): ?string {
    $rel = '2026/facture/Atelier_Renommé/Facture_AN-5001.pdf';
    $id = (int) $pdo->query('SELECT id FROM documents WHERE chemin_relatif = ' . $pdo->quote($rel))->fetchColumn();
    $depot->miseALaCorbeille($id, 'olivia');
    exiger(is_file($fonds . '/fonds/.corbeille/' . $rel), 'le fichier n’est pas sous .corbeille/');
    exiger(!is_file($fonds . '/fonds/' . $rel), 'le fichier est encore dans le fonds');
    exiger($pdo->query('SELECT supprime_le FROM documents WHERE id = ' . $id)->fetchColumn() !== null, 'pas de drapeau corbeille');

    $depot->restaurer($id);
    exiger(is_file($fonds . '/fonds/' . $rel), 'la restauration n’a pas remis le fichier');
    exiger(str_contains((string) file_get_contents($fonds . '/fonds/' . $rel), 'CORRIGÉE'), 'le contenu a changé en corbeille');
    exiger($pdo->query('SELECT supprime_le FROM documents WHERE id = ' . $id)->fetchColumn() === null, 'drapeau non levé');

    return 'corbeille puis restauration, contenu identique';
});

$etape('5. reconstruire-index : base vidée puis reconstruite identique (hash de l’export)', function () use (&$pdo): ?string {
    $avant = Export::hacher($pdo);
    [$code, $sortie] = commande('tools/reconstruire-index.php', '');
    exiger($code === 0, "la commande a échoué : $sortie");
    exiger(str_contains($sortie, 'identique'), "verdict manquant : $sortie");
    exiger(Export::hacher($pdo) === $avant, 'l’index reconstruit ne rend pas le même hash');

    return substr($avant, 0, 12) . '… identique après reconstruction';
});

// --- Remise en état ----------------------------------------------------------------------------
viderBase($config);
commande('tools/installer.php', '--admin=admin');   // la suite retrouve une installation fraîche
putenv('STORAGE_ROOT');   // la porte rend l’environnement qu’elle a emprunté
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fonds, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
rmdir($fonds);

echo $echecs === 0 ? "PORTE V2-01 : franchie\n" : "PORTE V2-01 : REFUSÉE ($echecs clause(s) en échec)\n";
exit($echecs === 0 ? 0 : 1);
