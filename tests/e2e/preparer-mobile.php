<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright mobile (lot V2-10a, tests/e2e/mobile.spec.ts).
 *
 *   STORAGE_ROOT=<dossier> INGESTION_* php tests/e2e/preparer-mobile.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - crée un compte RÉEL comme en production (mot de passe haché, rôle admin) ;
 * - ingère par le VRAI `Ged\Ingestion\Pilote` une facture d'une page (le moteur de découpe externe
 *   ClearMyDocs vit dans un autre dépôt : le test fournit un découpeur à sa place, l'orchestration,
 *   le classement et le rangement restent ceux de production) ;
 * - dépose un document portant un jeton unique pour que la recherche mobile ait une cible RÉELLE ;
 * - rend le compte, les identifiants et le jeton du parcours.
 *
 * Aucune donnée réelle : corpus synthétique, compte fictif, base gedv2_test.
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
use Ged\Ingestion\Travaux;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation mobile : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('cet oracle n’écrit que sur gedv2_test');
    }
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/mobile/documents');
    supprimerDossier($fonds);
    if (!mkdir($fonds, 0775, true) && !is_dir($fonds)) {
        throw new RuntimeException("préparation mobile : impossible de créer $fonds");
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Securite', 'Ingestion', 'Jonctions', 'Classement'] as $secteur) {
        ('Ged\\' . $secteur . '\\Schema')::installer($pdo);
    }
    SchemaRecherche::installer($pdo);

    // Compte RÉEL, créé comme par l'installateur.
    $identifiant = 'recette-mobile@gedv2.test';
    $motDePasse = 'MotDePasse-Mobile-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);

    // La configuration de la GED vit dans le fonds (`.ged/`) : le recréer à neuf l'a remise à zéro.
    (new Nomenclature($config))->assurer();

    // Mes lignes d'index seulement (dossier d'oracle dédié).
    $miennes = $pdo->query("SELECT id FROM documents WHERE chemin_relatif LIKE 'oracle-mobile/%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($miennes !== []) {
        $liste = implode(',', array_map('intval', $miennes));
        $pdo->exec("DELETE FROM document_versions WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM document_deplacements WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM documents WHERE id IN ($liste)");
    }
    $pdo->exec("DELETE FROM recherche_textes WHERE chemin LIKE 'oracle-mobile/%'");

    $depot = new DepotFichiers($config);
    $jeton = bin2hex(random_bytes(4));
    $zone = $config->chemin('INGESTION_TRAVAIL', 'var/oracles/mobile/ingestion');

    // ---- 1. Une pièce ingérée par le VRAI Pilote (à valider au pouce).
    $emetteur = 'Papeterie Fictive ' . $jeton . ' SA';   // suffixe légal : l'émetteur est lisible
    $contenu = sys_get_temp_dir() . '/gedv2-mobile-lot-' . $jeton . '.pdf';
    ecrirePdf(['FACTURE FICTIVE ' . $jeton], $contenu);

    // Le lot est enregistré explicitement dans `ingestion_travaux` juste après : la table est partagée
    // entre les bancs, et un lot « reçu » explicite est le seul que ce parcours traite, rien d'autre.

    $decoupeur = new class ($emetteur) implements Decoupeur {
        public function __construct(private readonly string $emetteur)
        {
        }

        public function decouper(string $fichier, string $sortie): array
        {
            if (!is_dir($sortie) && !mkdir($sortie, 0775, true) && !is_dir($sortie)) {
                throw new RuntimeException("découpeur simulé : impossible de créer $sortie");
            }
            $piece = $sortie . '/piece-1p.pdf';
            ecrirePdf(['FACTURE FICTIVE ' . $this->emetteur], $piece);

            return [[
                'fichier' => $piece,
                'pages' => [1],
                'texte' => "**FACTURE**\nÉmetteur : " . $this->emetteur . "\nDate : 05.01.2026\nTotal fictif : CHF 101.00",
                'analyse' => ['origine' => null],
            ]];
        }
    };

    $travaux = new Travaux($pdo);
    $lot = $travaux->recevoir(new Entree($contenu, 'dossier', 'lot-mobile-gedv2-mobile-lot-' . $jeton . '.pdf'));
    if ($lot === null) {
        throw new RuntimeException('ingestion mobile : le lot n’a pas pu être enregistré');
    }

    // La table `ingestion_travaux` est partagée : on ne passe pas par la file (« recu »/« pret »), on
    // déroule le VRAI Pilote (découpeur, classeur, dépôt) sur CE lot et SA pièce, rien d'autre.
    $pilote = new Pilote($travaux, [], $decoupeur, $depot,
        new ClasseurGed($config, $pdo, new ClientIaNul()), $zone, $config);
    $idPilote = deplierLotMobile($pilote, $pdo, $lot);
    if ($idPilote === 0) {
        throw new RuntimeException('ingestion Pilote mobile : le document attendu n’a pas été rangé');
    }
    $depot->mettreAJour($idPilote, ['dossier' => 'oracle-mobile', 'id' => $idPilote], 'oracle');
    @unlink($contenu);

    // ---- 2. Un document cherchable (jeton unique dans le titre et le texte).
    $source2 = sys_get_temp_dir() . '/gedv2-mobile-recherche-' . $jeton . '.pdf';
    ecrirePdf(['CONTRAT FICTIF ' . $jeton], $source2);
    $idRecherche = $depot->deposer($source2, [
        'dossier' => 'oracle-mobile',
        'titre' => 'Contrat recherche ' . $jeton,
        'type' => 'Contrat',
        'emetteur' => 'Bailleur Fictif ' . $jeton . ' SA',
        'date' => '2026-03-04',
        'statut' => 'valide',
        'source' => 'dossier',
        'texte' => 'CONTRAT FICTIF ' . $jeton . ' — pièce synthétique de parcours mobile.',
    ]);
    $depot->mettreAJour($idRecherche, ['id' => $idRecherche], 'oracle');
    @unlink($source2);

    // Index de recherche : la ligne est posée ici (le job d'indexation de production balaie SON fonds ;
    // `tests/e2e/indexer-mobile.php` la reprépare juste avant la recherche pour rester isolé des autres bancs).
    $cheminRelatif = (string) $pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $idRecherche)->fetchColumn();
    $fichier = $fonds . '/' . $cheminRelatif;
    $metaFichier = $fichier . '.meta.json';
    $pdo->prepare('INSERT INTO recherche_textes (cle, chemin, document_id, titre, type, emetteur, date_document, montant, contenu, signature)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), titre = VALUES(titre), type = VALUES(type),'
        . ' emetteur = VALUES(emetteur), date_document = VALUES(date_document), contenu = VALUES(contenu), absent_le = NULL')
        ->execute([
            hash('sha256', $cheminRelatif), $cheminRelatif, $idRecherche,
            'Contrat recherche ' . $jeton, 'Contrat', 'Bailleur Fictif ' . $jeton . ' SA', '2026-03-04', 0,
            'CONTRAT FICTIF ' . $jeton . ' — pièce synthétique de parcours mobile.',
            is_file($metaFichier) ? hash('sha256', filesize($metaFichier) . '|' . filemtime($metaFichier)) : hash('sha256', 'mobile'),
        ]);

    echo json_encode([
        'preparation' => 'prete',
        'compte' => $identifiant,
        'mot_de_passe' => $motDePasse,
        'fonds' => $fonds,
        'jeton' => $jeton,
        'id_pilote' => $idPilote,
        'id_recherche' => $idRecherche,
        'titre_recherche' => 'Contrat recherche ' . $jeton,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation mobile : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

/**
 * Déroule le Pilote sur un lot PRÉCIS : découpe le lot, puis range sa pièce par le dépôt réel.
 * Rend l'identifiant du document rangé (0 si rien n'a pu l'être). Aucune réservation partagée : le lot
 * et la pièce sont désignés par leur identifiant, donc aucun travail d'un autre fonds ne peut être pris.
 */
function deplierLotMobile(Pilote $pilote, PDO $pdo, int $lot): int
{
    $classe = new ReflectionClass($pilote);
    $decouper = $classe->getMethod('decouper');
    $ranger = $classe->getMethod('ranger');
    $decouper->setAccessible(true);
    $ranger->setAccessible(true);

    $lire = $pdo->prepare('SELECT * FROM ingestion_travaux WHERE id = ?');
    $lire->execute([$lot]);
    $ligneLot = $lire->fetch(PDO::FETCH_ASSOC);
    if (!is_array($ligneLot)) {
        throw new RuntimeException("lot d'ingestion $lot introuvable");
    }
    $decouper->invoke($pilote, $ligneLot);

    $piece = $pdo->prepare("SELECT id FROM ingestion_travaux WHERE parent_id = ? AND etat = 'pret' ORDER BY id LIMIT 1");
    $piece->execute([$lot]);
    $pieceId = (int) $piece->fetchColumn();
    if ($pieceId === 0) {
        throw new RuntimeException("le lot $lot n'a rendu aucune pièce");
    }
    $lire->execute([$pieceId]);
    $ranger->invoke($pilote, (array) $lire->fetch(PDO::FETCH_ASSOC));

    $document = $pdo->prepare('SELECT document_id FROM ingestion_travaux WHERE id = ?');
    $document->execute([$pieceId]);

    return (int) $document->fetchColumn();
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

/** @param list<string> $pagesTextes */
function ecrirePdf(array $pagesTextes, string $fichier): void
{
    $objets = [];
    $kids = [];
    $numero = 3;
    foreach ($pagesTextes as $i => $texte) {
        $page = $numero++;
        $contenu = $numero++;
        $kids[] = "$page 0 R";
        $flux = 'BT /F1 20 Tf 60 780 Td (' . pdfEchappe($texte) . ") Tj ET\n"
            . "1 w 60 740 m 535 740 l S\n"
            . 'BT /F1 11 Tf 60 715 Td (' . pdfEchappe('Document fictif - page ' . ($i + 1) . ' sur ' . count($pagesTextes)) . ") Tj ET\n";
        $objets[$contenu] = "<< /Length " . strlen($flux) . " >>\nstream\n" . $flux . "endstream";
        $objets[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 9 0 R >> >> /Contents $contenu 0 R >>";
    }
    $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objets[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pagesTextes) . ' >>';
    $objets[9] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    ksort($objets);

    $sortie = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objets as $n => $corps) {
        $offsets[$n] = strlen($sortie);
        $sortie .= "$n 0 obj\n$corps\nendobj\n";
    }
    $xref = strlen($sortie);
    $max = max(array_keys($objets));
    $sortie .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= $max; $n++) {
        $sortie .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
    }
    $sortie .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($fichier, $sortie);
}

function pdfEchappe(string $texte): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte);
}
