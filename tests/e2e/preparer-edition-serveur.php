<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright du lot ged-edition-serveur (tests/e2e/edition-serveur.spec.ts).
 *
 *   STORAGE_ROOT=<fonds isolé> php tests/e2e/preparer-edition-serveur.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - deux comptes RÉELS (mot de passe haché, la voie de l'installateur) sur le MÊME dossier :
 *   un MANAGER (lire + déposer + modifier) et un LECTEUR (lire seulement) ;
 * - deux documents déposés par le VRAI `DepotFichiers` (Word .docx, Excel .xlsx), synthétiques ;
 * - un jeton d'APPAREIL pour le manager (comme l'agent local enrôlé), rendu au parcours.
 *
 * Sortie JSON : { manager, mot_de_passe_manager, lecteur, mot_de_passe_lecteur, docx, xlsx, jeton, poste }.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Schema as SchemaJonctions;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test');   // l'oracle n'écrit que la base de test, quoi qu'en dise l'environnement
putenv('APP_ENV=test');

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation edition-serveur\n");
    exit(1);
}
$attendu = $racine . '/var/oracles/edition-serveur/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé var/oracles/edition-serveur/documents\n");
    exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('ce parcours n’écrit que sur gedv2_test');
    }

    // Fonds ET espace de secours recréés à neuf (hors du dépôt, aucun fichier versionné touché).
    supprimerDossier(dirname($attendu));
    if (!mkdir($attendu, 0775, true) && !is_dir($attendu)) {
        throw new RuntimeException("préparation : impossible de créer $attendu");
    }
    putenv('SECOURS_ROOT=' . dirname($attendu) . '/secours');
    mkdir(dirname($attendu) . '/secours', 0775, true);

    $bac = sys_get_temp_dir() . '/gedv2-edition-e2e-' . bin2hex(random_bytes(4));
    mkdir($bac . '/source', 0775, true);

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    SchemaJonctions::installer($pdo);
    remettreEtatFondsE2e($pdo);
    $pdo->exec('DELETE FROM document_collaboration');
    $pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['Edition E2E%']);
    $pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['edition-e2e-%@gedv2.test']);

    $manager = 'edition-e2e-manager@gedv2.test';
    $motDePasseManager = 'MotDePasse-Edition-Manager-2026!';
    $lecteur = 'edition-e2e-lecteur@gedv2.test';
    $motDePasseLecteur = 'MotDePasse-Edition-Lecteur-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$manager, password_hash($motDePasseManager, PASSWORD_DEFAULT), 'manager']);
    $idManager = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$lecteur, password_hash($motDePasseLecteur, PASSWORD_DEFAULT), 'lecteur']);
    $idLecteur = (int) $pdo->lastInsertId();

    (new Nomenclature($config))->assurer();

    $droits = new DroitsDossiers($pdo);
    $groupeManager = $droits->creerGroupe('Edition E2E Managers ' . bin2hex(random_bytes(3)), 'manager');
    $droits->ajouterCompteAuGroupe($idManager, $groupeManager);
    $groupeLecteur = $droits->creerGroupe('Edition E2E Lecteurs ' . bin2hex(random_bytes(3)), 'lecteur');
    $droits->ajouterCompteAuGroupe($idLecteur, $groupeLecteur);

    $depot = new DepotFichiers($config);
    $source = static function (string $nom) use ($racine): string {
        return $racine . '/tests/corpus/apercu/documents/' . $nom;
    };
    // Corpus synthétique du dépôt (aucun nom de client) : un vrai .docx et un vrai .xlsx.
    $docx = $depot->deposer($source('209-attestation-travail.docx'),
        ['titre' => 'Rapport edition E2E', 'type' => 'Rapport', 'date' => '2026-10-01',
            'dossier' => 'edition-serveur/Documents', 'statut' => 'valide']);
    $xlsx = $depot->deposer($source('213-tableau-charges.xlsx'),
        ['titre' => 'Tableau edition E2E', 'type' => 'Tableau', 'date' => '2026-10-02',
            'dossier' => 'edition-serveur/Documents', 'statut' => 'valide']);

    $dossier = $droits->dossierPourDocument($docx);
    if ($dossier === null) {
        throw new RuntimeException('dossier de droits introuvable après dépôt');
    }
    foreach (['lire', 'deposer', 'modifier'] as $niveau) {
        $droits->poserRegle($dossier, $groupeManager, $niveau, true);
    }
    $droits->poserRegle($dossier, $groupeLecteur, 'lire', true);

    $poste = 'POSTE-E2E-F13';
    $jeton = (new Jetons($pdo))->creerAppairage('kdocs', $poste, $idManager, $manager);

    echo json_encode([
        'manager' => $manager, 'mot_de_passe_manager' => $motDePasseManager,
        'lecteur' => $lecteur, 'mot_de_passe_lecteur' => $motDePasseLecteur,
        'docx' => $docx, 'xlsx' => $xlsx, 'jeton' => $jeton, 'poste' => $poste,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation edition-serveur : ' . $e->getMessage() . "\n");
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
