<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright du designer de workflows (tests/e2e/workflows.spec.ts).
 *
 *   STORAGE_ROOT=<dossier> php tests/e2e/preparer-workflows.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - remet à neuf les deux gabarits de workflow (facture à seuil, contrat à échéance) pour que le
 *   parcours reparte d'un dessin connu, quoi qu'un passage précédent ait laissé ;
 * - crée un compte RÉEL comme à l'installation (courriel + mot de passe haché, rôle admin) ;
 * - ingère par le VRAI `Ged\Ingestion\Pilote` une pièce synthétique de type Facture, montant 12 000 ;
 *
 * Sortie JSON : { compte, mot_de_passe, fonds, id_pilote }.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Config;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Entree;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Source;
use Ged\Ingestion\Travaux;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;

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
        throw new RuntimeException('cet oracle n’écrit que sur gedv2_test');
    }
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/workflows/documents');
    supprimerDossier($fonds);
    if (!mkdir($fonds, 0775, true) && !is_dir($fonds)) {
        throw new RuntimeException("préparation : impossible de créer $fonds");
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Securite', 'Ingestion', 'Jonctions', 'Classement', 'Workflows', 'Recherche', 'Archivage'] as $secteur) {
        ('Ged\\' . $secteur . '\\Schema')::installer($pdo);
    }

    // Les gabarits repartent d'un dessin neuf : le parcours modifie le seuil et ajoute un état.
    $pdo->exec("DELETE FROM workflow_definitions WHERE gabarit IN ('facture_a_seuil', 'contrat_a_echeance')");
    $pdo->exec('DELETE FROM workflow_instances WHERE definition_id NOT IN (SELECT id FROM workflow_definitions)');

    // Compte RÉEL, créé comme par l'installateur : identifiant = adresse de courriel, mot de passe haché.
    $identifiant = 'recette-workflows@gedv2.test';
    $motDePasse = 'MotDePasse-Workflows-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    // La configuration de la GED vit dans le fonds (`.ged/`) : le recréer à neuf l'a remise à zéro.
    (new Nomenclature($config))->assurer();

    // Mes lignes d'index seulement (dossier d'oracle dédié).
    $miennes = $pdo->query("SELECT id FROM documents WHERE chemin_relatif LIKE 'oracle-workflows/%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($miennes !== []) {
        $liste = implode(',', array_map('intval', $miennes));
        $pdo->exec("DELETE FROM document_versions WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM document_deplacements WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM documents WHERE id IN ($liste)");
    }

    $zone = $config->chemin('INGESTION_TRAVAIL', 'var/oracles/workflows/ingestion');
    $jeton = bin2hex(random_bytes(4));
    $emetteurPilote = 'Papeterie Fictive ' . $jeton . ' SA';
    $contenuPilote = sys_get_temp_dir() . '/gedv2-workflows-pilote-' . $jeton . '.pdf';
    ecrirePdf(['FACTURE FICTIVE ' . $emetteurPilote, 'Facture N° F-ORACLE-1', 'Total fictif : CHF 12 000.00'], $contenuPilote);

    $source = new class ($contenuPilote) implements Source {
        private bool $fait = false;

        public function __construct(private readonly string $fichier)
        {
        }

        /** @return list<Entree> */
        public function relever(): array
        {
            if ($this->fait) {
                return [];
            }
            $this->fait = true;

            return [new Entree($this->fichier, 'dossier', 'lot-oracle-workflows.pdf')];
        }
    };

    $decoupeur = new class ($emetteurPilote) implements Decoupeur {
        public function __construct(private readonly string $emetteur)
        {
        }

        /** @return list<array<string, mixed>> */
        public function decouper(string $fichier, string $sortie): array
        {
            if (!is_dir($sortie) && !mkdir($sortie, 0775, true) && !is_dir($sortie)) {
                throw new RuntimeException("moteur simulé : impossible de créer $sortie");
            }
            $piece = $sortie . '/piece-facture.pdf';
            ecrirePdf(['FACTURE FICTIVE ' . $this->emetteur, 'Facture N° F-ORACLE-1', 'Total fictif : CHF 12 000.00'], $piece);

            return [[
                'fichier' => $piece,
                'pages' => [1],
                'texte' => '**' . $this->emetteur . "**\nFacture N° F-ORACLE-1\nDate : 05.01.2026\nTotal fictif : CHF 12 000.00",
                'analyse' => ['origine' => null],
            ]];
        }
    };

    $depot = new DepotFichiers($config);
    $pilote = new Pilote(new Travaux($pdo), [$source], $decoupeur, $depot,
        new ClasseurGed($config, $pdo, new ClientIaNul()), $zone, $config);
    $pilote->tic(20);   // reçoit le lot
    $pilote->tic(20);   // découpe la pièce puis la range par le dépôt réel

    $idPilote = (int) $pdo->query('SELECT id FROM documents WHERE meta LIKE '
        . $pdo->quote('%' . $jeton . '%') . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($idPilote === 0) {
        throw new RuntimeException('ingestion Pilote : le document attendu n’a pas été rangé');
    }
    // La pièce est rangée dans le dossier de l'oracle et complétée en métadonnées FICTIVES : le gabarit
    // « facture à seuil » doit réagir à l'essai à blanc (type Facture, 12 000).
    $depot->mettreAJour($idPilote, ['dossier' => 'oracle-workflows', 'id' => $idPilote, 'type' => 'Facture', 'montant' => 12000.0], 'oracle');
    @unlink($contenuPilote);

    echo json_encode([
        'preparation' => 'prete',
        'compte' => $identifiant,
        'mot_de_passe' => $motDePasse,
        'fonds' => $fonds,
        'id_pilote' => $idPilote,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

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

/** @param list<string> $lignes */
function ecrirePdf(array $lignes, string $fichier): void
{
    $contenu = '';
    $y = 780;
    foreach ($lignes as $ligne) {
        $contenu .= 'BT /F1 14 Tf 60 ' . $y . ' Td (' . pdfEchappe($ligne) . ") Tj ET\n";
        $y -= 24;
    }
    $objet3 = "<< /Length " . strlen($contenu) . " >>\nstream\n" . $contenu . "endstream";
    $sortie = "%PDF-1.4\n";
    $offsets = [];
    foreach ([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        4 => $objet3,
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ] as $n => $corps) {
        $offsets[$n] = strlen($sortie);
        $sortie .= "$n 0 obj\n$corps\nendobj\n";
    }
    $xref = strlen($sortie);
    $sortie .= "xref\n0 6\n0000000000 65535 f \n";
    for ($n = 1; $n <= 5; $n++) {
        $sortie .= sprintf("%010d 00000 n \n", $offsets[$n]);
    }
    $sortie .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($fichier, $sortie);
}

function pdfEchappe(string $texte): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte);
}
