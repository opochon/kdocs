<?php

declare(strict_types=1);

/**
 * Prépare le parcours F13 de l'agent local K-Docs (lot ged-agent-poste).
 *
 *   STORAGE_ROOT=<fonds isolé> SECOURS_ROOT=<secours isolé> php agent-poste/tests/parcours/preparer-f13.php
 *
 * - base gedv2_test seulement, verrou partagé ;
 * - un compte MANAGER réel (mot de passe haché, la voie de l'installateur) ;
 * - un dossier « Documents » (lire + déposer + modifier) et un dossier « Secret » SANS droits ;
 * - deux documents synthétiques déposés par le VRAI `DepotFichiers` (.docx, .xlsx) dans Documents,
 *   et un document dans Secret (refus attendu du poste) ;
 * - un appairage EN ATTENTE (comme « Mon profil › Appareils reliés ») : code + code de vérification ;
 * - deux jetons d'APPAREIL (comme l'agent enrôlé) : un principal, un révocable.
 *
 * Sortie JSON : {docx, xlsx, secret, manager, code, verification, poste, jeton_principal, jeton_revocable}.
 */

$racine = dirname(__DIR__, 3);
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

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation F13\n");
    exit(1);
}
$attendu = $racine . '/var/oracles/agent-poste/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé var/oracles/agent-poste/documents\n");
    exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation F13 : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('ce parcours n’écrit que sur gedv2_test');
    }

    supprimerDossier(dirname($attendu));
    if (!mkdir($attendu, 0775, true) && !is_dir($attendu)) {
        throw new RuntimeException("préparation F13 : impossible de créer $attendu");
    }
    putenv('SECOURS_ROOT=' . dirname($attendu) . '/secours');
    mkdir(dirname($attendu) . '/secours', 0775, true);

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    SchemaJonctions::installer($pdo);
    remettreEtatFondsE2e($pdo);
    $pdo->exec('DELETE FROM document_collaboration');
    $pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['Agent F13%']);
    $pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['agent-f13-%@gedv2.test']);

    $manager = 'agent-f13-manager@gedv2.test';
    $motDePasse = 'MotDePasse-Agent-F13-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$manager, password_hash($motDePasse, PASSWORD_DEFAULT), 'manager']);
    $idManager = (int) $pdo->lastInsertId();

    (new Nomenclature($config))->assurer();
    $droits = new DroitsDossiers($pdo);
    $groupe = $droits->creerGroupe('Agent F13 managers ' . bin2hex(random_bytes(3)), 'manager');
    $droits->ajouterCompteAuGroupe($idManager, $groupe);

    $depot = new DepotFichiers($config);
    $source = static fn (string $nom): string => $racine . '/tests/corpus/apercu/documents/' . $nom;
    $docx = $depot->deposer($source('209-attestation-travail.docx'),
        ['titre' => 'Rapport agent F13', 'type' => 'Rapport', 'date' => '2026-10-11',
            'dossier' => 'agent-f13/Documents', 'statut' => 'valide']);
    $xlsx = $depot->deposer($source('213-tableau-charges.xlsx'),
        ['titre' => 'Tableau agent F13', 'type' => 'Tableau', 'date' => '2026-10-11',
            'dossier' => 'agent-f13/Documents', 'statut' => 'valide']);
    // Trois documents .docx de travail SUPPLÉMENTAIRES : les tests qui remplacent le contenu par du
    // texte (hors ligne, conflit, appareil révoqué) ne doivent pas abîmer le document des tests Word.
    $docxLigne = $depot->deposer($source('209-attestation-travail.docx'),
        ['titre' => 'Rapport hors ligne F13', 'type' => 'Rapport', 'date' => '2026-10-11',
            'dossier' => 'agent-f13/Documents', 'statut' => 'valide']);
    $docxConflit = $depot->deposer($source('209-attestation-travail.docx'),
        ['titre' => 'Rapport conflit F13', 'type' => 'Rapport', 'date' => '2026-10-11',
            'dossier' => 'agent-f13/Documents', 'statut' => 'valide']);
    $docxRevoque = $depot->deposer($source('209-attestation-travail.docx'),
        ['titre' => 'Rapport revoque F13', 'type' => 'Rapport', 'date' => '2026-10-11',
            'dossier' => 'agent-f13/Documents', 'statut' => 'valide']);
    $secret = $depot->deposer($source('209-attestation-travail.docx'),
        ['titre' => 'Document sans droits', 'type' => 'Rapport', 'date' => '2026-10-11',
            'dossier' => 'agent-f13/Secret', 'statut' => 'valide']);

    $dossier = $droits->dossierPourDocument($docx);
    if ($dossier === null) {
        throw new RuntimeException('dossier de droits introuvable après dépôt');
    }
    foreach (['lire', 'deposer', 'modifier'] as $niveau) {
        $droits->poserRegle($dossier, $groupe, $niveau, true);
    }
    // Le dossier Secret reste SANS droits pour le manager : le poste doit le refuser.

    $poste = 'POSTE-F13';
    $code = bin2hex(random_bytes(32));
    $verification = 'K7M-4QX';
    $pdo->prepare('INSERT INTO jonctions_jetons (nature, compte_id, compte_identifiant, appairage_application,'
        . ' appairage_code, appairage_verification, appairage_adresse, appairage_cree_le, appairage_expire_le)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))')
        ->execute([SchemaJonctions::NATURE_APPAIRAGE, $idManager, $manager, 'kdocs',
            hash('sha256', $code), $verification, $manager]);

    $jetons = new Jetons($pdo);
    $jetonPrincipal = $jetons->creerAppairage('kdocs', $poste, $idManager, $manager);
    $jetonRevocable = $jetons->creerAppairage('kdocs', $poste . '-REVOCABLE', $idManager, $manager);

    echo json_encode([
        'docx' => $docx, 'xlsx' => $xlsx, 'secret' => $secret,
        'docx_ligne' => $docxLigne, 'docx_conflit' => $docxConflit, 'docx_revoque' => $docxRevoque,
        'manager' => $manager, 'mot_de_passe' => $motDePasse,
        'code' => $code, 'verification' => $verification, 'poste' => $poste,
        'jeton_principal' => $jetonPrincipal, 'jeton_revocable' => $jetonRevocable,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation F13 : ' . $e->getMessage() . "\n");
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
