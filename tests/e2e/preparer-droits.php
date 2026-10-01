<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright « droits réels » (lot R1-droits, tests/e2e/droits-reels.spec.ts).
 *
 *   STORAGE_ROOT=<dossier> php tests/e2e/preparer-droits.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - crée un compte RÉEL comme en production (mot de passe haché, rôle admin — la voie de
 *   l'installateur), jamais le compte synthétique qui contourne les droits de dossier ;
 * - fait ingérer un document par le VRAI `Pilote` : un lot synthétique est reçu, découpé (moteur
 *   simulé, aucune dépendance à ClearMyDocs) puis rangé par le VRAI `DepotFichiers` ;
 * - vérifie que le document rangé a bien un dossier de droits et rend son identifiant.
 *
 * Sortie JSON : { preparation, compte, mot_de_passe, document, chemin, dossier_acl }.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Contrats\Classeur;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Ingestion\SourceDossier;
use Ged\Ingestion\Travaux;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test');   // l'oracle n'écrit que la base de test, quoi qu'en dise l'environnement
putenv('APP_ENV=test');

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

    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/droits/documents');
    supprimerDossier($fonds);
    if (!mkdir($fonds, 0775, true) && !is_dir($fonds)) {
        throw new RuntimeException("préparation : impossible de créer $fonds");
    }

    $bac = sys_get_temp_dir() . '/gedv2-r1-e2e-' . bin2hex(random_bytes(4));
    mkdir($bac . '/entree', 0775, true);
    mkdir($bac . '/travail', 0775, true);
    putenv('INGESTION_DOSSIER=' . $bac . '/entree');
    putenv('INGESTION_TRAVAIL=' . $bac . '/travail');
    putenv('INGESTION_IMAP_HOTE=');

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaIngestion::installer($pdo);
    SchemaSecurite::installer($pdo);

    // Compte RÉEL, créé comme par l'installateur : identifiant = adresse de courriel, mot de passe haché.
    $identifiant = 'recette-droits@gedv2.test';
    $motDePasse = 'MotDePasse-Droits-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    $suffixe = bin2hex(random_bytes(4));

    // Pièce source : un PDF synthétique RÉEL du corpus, rendu unique par un commentaire (dédoublonnage d'ingestion).
    $modele = $racine . '/tests/corpus/apercu/documents/204-contrat-maintenance.pdf';
    $contenu = is_file($modele) ? (string) file_get_contents($modele) : "%PDF-1.4\n%%EOF\n";
    $contenu = (string) preg_replace('/^(%PDF-[0-9.]+)/', '$1' . "\n% R1-" . $suffixe, $contenu, 1);
    file_put_contents($bac . '/entree/courrier-synthetique.pdf', $contenu);

    $decoupeur = new class implements Decoupeur {
        public function decouper(string $fichier, string $dossierSortie): array
        {
            if (!is_dir($dossierSortie) && !mkdir($dossierSortie, 0775, true) && !is_dir($dossierSortie)) {
                throw new RuntimeException('découpe simulée : dossier de sortie indisponible');
            }
            $piece = $dossierSortie . '/piece1.pdf';
            copy($fichier, $piece);

            return [['fichier' => $piece, 'pages' => [1, 2, 3],
                'texte' => 'Courrier synthétique de la recette R1-droits', 'analyse' => ['origine' => null]]];
        }
    };

    $classeur = new class($suffixe) implements Classeur {
        public function __construct(private readonly string $suffixe)
        {
        }

        public function proposer(string $texte, array $analyse, string $nomFichier): array
        {
            return ['titre' => 'Courrier de test R1 ' . $this->suffixe, 'type' => 'Courrier',
                'emetteur' => 'Droits Reels ' . $this->suffixe, 'date' => date('Y-m-d'), 'montant' => null,
                'etiquettes' => [], 'confiance' => 0.9, 'source' => 'regle', 'preuve' => 'recette R1',
                'valide' => true, 'raison' => null];
        }
    };

    $depot = new DepotFichiers($config);
    $source = new SourceDossier($bac . '/entree', $bac . '/travail/recus', $bac . '/travail/etat-dossier.json');
    $pilote = new Pilote(new Travaux($pdo), [$source], $decoupeur, $depot, $classeur, $bac . '/travail');
    for ($i = 0; $i < 10; $i++) {
        $bilan = $pilote->tic(30);
        $restant = (int) $pdo->query("SELECT COUNT(*) FROM ingestion_travaux WHERE etat IN ('recu','pret')")->fetchColumn();
        if ($i > 0 && $restant === 0 && $bilan['recus'] + $bilan['decoupes'] + $bilan['ranges'] === 0) {
            break;
        }
    }

    $stmt = $pdo->prepare('SELECT id, chemin_relatif FROM documents WHERE titre = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['Courrier de test R1 ' . $suffixe]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($document === false) {
        throw new RuntimeException('le Pilote n’a rangé aucun document');
    }

    // Oracle embarqué : un document rangé doit avoir son dossier de droits, sinon la garde le refuse à tout compte réel.
    $dossierAcl = (new DroitsDossiers($pdo))->dossierPourDocument((int) $document['id']);
    if ($dossierAcl === null) {
        throw new RuntimeException('document rangé sans dossier de droits : la garde refusera son aperçu');
    }

    echo json_encode([
        'preparation' => 'prete',
        'compte' => $identifiant,
        'mot_de_passe' => $motDePasse,
        'document' => (int) $document['id'],
        'chemin' => (string) $document['chemin_relatif'],
        'dossier_acl' => $dossierAcl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation : ' . $e->getMessage() . "\n");
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
