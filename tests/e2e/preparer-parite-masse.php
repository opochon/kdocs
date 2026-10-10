<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright du lot ged-parite-masse (tests/e2e/parite-masse.spec.ts).
 *
 *   STORAGE_ROOT=<fonds isolé> php tests/e2e/preparer-parite-masse.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - deux comptes RÉELS (mot de passe haché, la voie de l'installateur) : un MANAGER (dossier A en
 *   modification, dossier B en lecture seule) et un ADMINISTRATEUR (écrans d'administration) ;
 * - documents DÉPOSÉS par le VRAI `DepotFichiers` (le dossier fait foi : `.meta.json`, versions, ACL) ;
 * - un partage interne vers le manager, pour l'écran « Partagés avec moi ».
 *
 * Sortie JSON : { manager, mot_de_passe_manager, admin, mot_de_passe_admin, modifiables[], lecture,
 * partage }.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test');   // l'oracle n'écrit que la base de test, quoi qu'en dise l'environnement
putenv('APP_ENV=test');

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation parite-masse\n");
    exit(1);
}
$attendu = $racine . '/var/oracles/parite-masse/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé var/oracles/parite-masse/documents\n");
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

    supprimerDossier($attendu);
    if (!mkdir($attendu, 0775, true) && !is_dir($attendu)) {
        throw new RuntimeException("préparation : impossible de créer $attendu");
    }

    $bac = sys_get_temp_dir() . '/gedv2-parite-masse-' . bin2hex(random_bytes(4));
    mkdir($bac . '/source', 0775, true);

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    remettreEtatFondsE2e($pdo);
    $pdo->exec('DELETE FROM document_collaboration');
    $pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['Parite Masse%']);
    $pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['parite-%@gedv2.test']);

    $manager = 'parite-manager@gedv2.test';
    $motDePasseManager = 'MotDePasse-Masse-2026!';
    $admin = 'parite-admin@gedv2.test';
    $motDePasseAdmin = 'MotDePasse-Masse-Admin-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$manager, password_hash($motDePasseManager, PASSWORD_DEFAULT), 'manager']);
    $idManager = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$admin, password_hash($motDePasseAdmin, PASSWORD_DEFAULT), 'admin']);
    $idAdmin = (int) $pdo->lastInsertId();

    // La nomenclature est posée dans le fonds : le sélecteur « Type cible » de la barre en masse a des types.
    (new Nomenclature($config))->assurer();

    $droits = new DroitsDossiers($pdo);
    $groupe = $droits->creerGroupe('Parite Masse ' . bin2hex(random_bytes(3)), 'manager');
    $droits->ajouterCompteAuGroupe($idManager, $groupe);

    $depot = new DepotFichiers($config);
    $piece = static function (string $dossier, string $nom, string $texte): string {
        $fichier = $dossier . '/' . $nom;
        file_put_contents($fichier, "%PDF-1.4\n% $texte\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $fichier;
    };

    $modifiables = [];
    for ($i = 1; $i <= 3; $i++) {
        $modifiables[] = $depot->deposer(
            $piece($bac . '/source', "modifiable-$i.pdf", "Pièce modifiable $i"),
            ['titre' => "Facture Parite M$i", 'type' => 'Facture', 'emetteur' => 'Atelier Parite SA',
                'date' => '2026-10-01', 'dossier' => 'parite-masse/Modifiable', 'statut' => 'valide'],
        );
    }
    $lecture = $depot->deposer(
        $piece($bac . '/source', 'lecture.pdf', 'Pièce en lecture seule'),
        ['titre' => 'Courrier Parite Lecture', 'type' => 'Courrier', 'emetteur' => 'Atelier Parite SA',
            'date' => '2026-10-02', 'dossier' => 'parite-masse/Lecture', 'statut' => 'valide'],
    );

    $dossierModifiable = $droits->assurerDossierPourChemin('parite-masse/Modifiable/document.pdf');
    $dossierLecture = $droits->assurerDossierPourChemin('parite-masse/Lecture/document.pdf');
    if ($dossierModifiable === null || $dossierLecture === null) {
        throw new RuntimeException('dossiers de droits introuvables');
    }
    foreach (['lire', 'deposer', 'modifier'] as $niveau) {
        $droits->poserRegle($dossierModifiable, $groupe, $niveau, true);
    }
    $droits->poserRegle($dossierLecture, $groupe, 'lire', true);

    // Partage interne vers le manager : « Partagés avec moi » a de quoi montrer.
    $pdo->prepare("INSERT INTO document_collaboration (document_id, type, type_partage, cible_type, cible_id, auteur)"
        . " VALUES (?, 'partage', 'interne', 'compte', ?, 'Manager Parite')")
        ->execute([$modifiables[0], $idManager]);

    echo json_encode([
        'manager' => $manager, 'mot_de_passe_manager' => $motDePasseManager,
        'admin' => $admin, 'mot_de_passe_admin' => $motDePasseAdmin,
        'modifiables' => $modifiables, 'lecture' => $lecture, 'partage' => $modifiables[0],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation parite-masse : ' . $e->getMessage() . "\n");
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
