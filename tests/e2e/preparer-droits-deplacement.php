<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright du lot ged-droits-deplacement (tests/e2e/droits-deplacement.spec.ts).
 *
 *   STORAGE_ROOT=<fonds isolé> php tests/e2e/preparer-droits-deplacement.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - un compte MANAGER RÉEL (mot de passe haché, la voie de l'installateur), groupe propre ;
 * - deux documents DÉPOSÉS par le VRAI `DepotFichiers`, rangés année/type/émetteur (rangement piloté par
 *   les métadonnées, pour qu'un changement de type change le CHEMIN) ;
 * - document « refusé » : droits lire+deposer+modifier sur son dossier Facture, RIEN sur le dossier
 *   Contrat d'arrivée ;
 * - document « accepté » : mêmes droits de départ, PLUS lire+deposer sur le dossier Contrat d'arrivée.
 *
 * Sortie JSON : { manager, mot_de_passe, refus, accepte }.
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

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$racineDemandee = getenv('STORAGE_ROOT');
if (!is_string($racineDemandee) || trim($racineDemandee) === '') {
    fwrite(STDERR, "STORAGE_ROOT explicite obligatoire pour la préparation droits-deplacement\n");
    exit(1);
}
$attendu = $racine . '/var/oracles/droits-deplacement/documents';
if (strtolower(rtrim(str_replace('\\', '/', $racineDemandee), '/')) !== strtolower(str_replace('\\', '/', $attendu))) {
    fwrite(STDERR, "STORAGE_ROOT doit viser le fonds isolé var/oracles/droits-deplacement/documents\n");
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

    $bac = sys_get_temp_dir() . '/gedv2-droits-deplacement-' . bin2hex(random_bytes(4));
    mkdir($bac . '/source', 0775, true);

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    remettreEtatFondsE2e($pdo);
    $pdo->prepare('DELETE FROM securite_groupes WHERE nom LIKE ?')->execute(['Droits Deplacement %']);
    $pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['deplacement-%@gedv2.test']);

    $manager = 'deplacement-manager@gedv2.test';
    $motDePasse = 'MotDePasse-Deplacement-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$manager, password_hash($motDePasse, PASSWORD_DEFAULT), 'manager']);
    $idManager = (int) $pdo->lastInsertId();

    (new Nomenclature($config))->assurer();

    $droits = new DroitsDossiers($pdo);
    $groupe = $droits->creerGroupe('Droits Deplacement ' . bin2hex(random_bytes(3)), 'manager');
    $droits->ajouterCompteAuGroupe($idManager, $groupe);

    $depot = new DepotFichiers($config);
    $piece = static function (string $dossier, string $nom, string $texte): string {
        $fichier = $dossier . '/' . $nom;
        file_put_contents($fichier, "%PDF-1.4\n% $texte\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $fichier;
    };

    $suffixe = bin2hex(random_bytes(3));
    $refus = $depot->deposer(
        $piece($bac . '/source', 'refus.pdf', 'Pièce refusée'),
        ['titre' => 'Facture Deplacement Refus', 'type' => 'Facture', 'emetteur' => 'Atelier Deplacement Refus ' . $suffixe,
            'date' => '2026-10-01', 'statut' => 'valide'],
    );
    $accepte = $depot->deposer(
        $piece($bac . '/source', 'accepte.pdf', 'Pièce acceptée'),
        ['titre' => 'Facture Deplacement Accepte', 'type' => 'Facture', 'emetteur' => 'Atelier Deplacement Accepte ' . $suffixe,
            'date' => '2026-10-02', 'statut' => 'valide'],
    );

    $chemin = static function (int $id) use ($pdo): string {
        return (string) $pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
    };
    $departRefus = dirname($chemin($refus));
    $departAccepte = dirname($chemin($accepte));
    $arriveeAccepte = str_replace('/Facture/', '/Contrat/', $departAccepte);

    foreach ([$departRefus, $departAccepte] as $depart) {
        $dossier = $droits->assurerDossierPourChemin($depart . '/piece.pdf');
        if ($dossier === null) {
            throw new RuntimeException("dossier de depart introuvable : $depart");
        }
        foreach (['lire', 'deposer', 'modifier'] as $niveau) {
            $droits->poserRegle($dossier, $groupe, $niveau, true);
        }
    }
    $dossierArrivee = $droits->assurerDossierPourChemin($arriveeAccepte . '/piece.pdf');
    if ($dossierArrivee === null) {
        throw new RuntimeException("dossier d'arrivee introuvable : $arriveeAccepte");
    }
    foreach (['lire', 'deposer'] as $niveau) {
        $droits->poserRegle($dossierArrivee, $groupe, $niveau, true);
    }

    echo json_encode([
        'manager' => $manager, 'mot_de_passe' => $motDePasse,
        'refus' => $refus, 'accepte' => $accepte,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation droits-deplacement : ' . $e->getMessage() . "\n");
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
