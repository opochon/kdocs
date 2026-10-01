<?php

declare(strict_types=1);

/**
 * « Passage de la tâche » du parcours mobile : le lot déposé par photo traverse la VRAIE chaîne
 * d'ingestion (Pilote → découpe simulée → classement → rangement par DepotFichiers).
 *
 *   PHOTO_NOM=<nom_origine> INGESTION_TRAVAIL=<dossier> STORAGE_ROOT=<dossier> php tests/e2e/passer-ingestion-mobile.php
 *
 * Le moteur de découpe externe (ClearMyDocs) vit dans un autre dépôt : il est remplacé ici par un
 * découpeur de test qui transforme la photo en PDF d'une page, comme le ferait la Vision. Le reste de
 * la chaîne (orchestration, classement, rangement) est celui de production.
 *
 * Isolation : la table `ingestion_travaux` est partagée avec les autres bancs. Avant de faire tourner
 * le Pilote, les AUTRES travaux ouverts sont réservés à l'écart (marque posée puis retirée) pour que ce
 * parcours traite exactement SON lot, et rien d'autre.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Config;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Travaux;
use Ged\Socle\DepotFichiers;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$nom = (string) getenv('PHOTO_NOM');
if ($nom === '') {
    fwrite(STDERR, "passage mobile : PHOTO_NOM requis\n");
    exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "passage mobile : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    $pdo = Base::pdo($config);
    $zone = $config->chemin('INGESTION_TRAVAIL', 'var/oracles/mobile/ingestion');

    $requete = $pdo->prepare("SELECT id FROM ingestion_travaux WHERE source = 'api' AND parent_id = 0 AND nom_origine = ? ORDER BY id DESC LIMIT 1");
    $requete->execute([$nom]);
    $lot = (int) $requete->fetchColumn();
    if ($lot === 0) {
        throw new RuntimeException("aucun lot d'ingestion pour « $nom » (le dépôt photo a-t-il abouti ?)");
    }

    $decoupeur = new class implements Decoupeur {
        public function decouper(string $fichier, string $sortie): array
        {
            if (!is_dir($sortie) && !mkdir($sortie, 0775, true) && !is_dir($sortie)) {
                throw new RuntimeException("découpeur photo simulé : impossible de créer $sortie");
            }
            $piece = $sortie . '/piece-photo-1p.pdf';
            ecrirePdfMobile(['FACTURE — photo déposée'], $piece);

            return [[
                'fichier' => $piece,
                'pages' => [1],
                'texte' => "**FACTURE**\nPièce déposée par photo\nDate : 05.01.2026\nTotal fictif : CHF 42.00",
                'analyse' => ['origine' => null],
            ]];
        }
    };

    // La table `ingestion_travaux` est partagée : on ne passe pas par la file (« recu »/« pret »), on
    // déroule le VRAI Pilote (découpeur, classeur, dépôt) sur CE lot et SA pièce, rien d'autre.
    $pilote = new Pilote(new Travaux($pdo), [], $decoupeur, new DepotFichiers($config),
        new ClasseurGed($config, $pdo, new ClientIaNul()), $zone, $config);
    $document = deplierLot($pilote, $pdo, $lot);
    if ($document === 0) {
        throw new RuntimeException('passage mobile : la pièce déposée n’a pas été rangée');
    }

    // Le fonds de l'oracle est un dossier dédié : la remise à neuf suivante la balaie.
    (new DepotFichiers($config))->mettreAJour($document, ['dossier' => 'oracle-mobile'], 'oracle');
    $titre = (string) $pdo->query('SELECT titre FROM documents WHERE id = ' . $document)->fetchColumn();

    echo json_encode(['ok' => true, 'document' => $document, 'titre' => $titre], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'passage mobile : ' . $e->getMessage() . "\n");
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
function deplierLot(Pilote $pilote, PDO $pdo, int $lot): int
{
    $classe = new ReflectionClass($pilote);
    $decouper = $classe->getMethod('decouper');
    $ranger = $classe->getMethod('ranger');
    $decouper->setAccessible(true);
    $ranger->setAccessible(true);

    $lireLot = $pdo->prepare('SELECT * FROM ingestion_travaux WHERE id = ?');
    $lireLot->execute([$lot]);
    $ligneLot = $lireLot->fetch(PDO::FETCH_ASSOC);
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
    $lirePiece = $pdo->prepare('SELECT * FROM ingestion_travaux WHERE id = ?');
    $lirePiece->execute([$pieceId]);
    $ranger->invoke($pilote, (array) $lirePiece->fetch(PDO::FETCH_ASSOC));

    $document = $pdo->prepare('SELECT document_id FROM ingestion_travaux WHERE id = ?');
    $document->execute([$pieceId]);

    return (int) $document->fetchColumn();
}

/** @param list<string> $pagesTextes */
function ecrirePdfMobile(array $pagesTextes, string $fichier): void
{
    $objets = [];
    $kids = [];
    $numero = 3;
    foreach ($pagesTextes as $i => $texte) {
        $page = $numero++;
        $contenu = $numero++;
        $kids[] = "$page 0 R";
        $flux = 'BT /F1 20 Tf 60 780 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte) . ") Tj ET\n"
            . "1 w 60 740 m 535 740 l S\n";
        $objets[$contenu] = "<< /Length " . strlen($flux) . " >>\nstream\n" . $flux . "endstream";
        $objets[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 9 0 R >> >> /Contents $contenu 0 R >>";
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
