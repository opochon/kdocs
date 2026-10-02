<?php

declare(strict_types=1);

/**
 * Prépare le parcours Playwright « écrans réels » (lot R1-ecrans, tests/e2e/ecrans-reels.spec.ts).
 *
 *   STORAGE_ROOT=<dossier> INGESTION_* INFOMANIAK_AI_MODEL_CLASSIFY=<modèle> php tests/e2e/preparer-ecrans-reels.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres oracles) ;
 * - crée un compte RÉEL comme en production (mot de passe haché, rôle admin — la voie de l'installateur) ;
 * - dépose un fonds synthétique par le VRAI `DepotFichiers` :
 *     * une pièce SANS proposition automatique (statut `a_controler`, type vide, aucun `classement`) ;
 *     * une pièce AVEC proposition du Classeur (source `regle`, confiance 0.62, raison lisible) ;
 *     * un document de type Facture et un de type Contrat pour les vues enregistrées ;
 * - pose un travail d'ingestion réel dans la zone de travail de l'instance (dernière tâche de l'écran Application) ;
 * - ouvre un appairage ClearMyMails (`cmm`) actif pour prouver que l'écran lit l'état réel des Jonctions ;
 * - enregistre deux VRAIES vues du fonds (`Factures à payer`, `Contrats à échéance`) ;
 * - rend le compteur réel des pièces `a_controler` de CE fonds (fichier présent), source de vérité du rail.
 *
 * Sortie JSON : { preparation, compte, mot_de_passe, fonds, ids, vues, compteur, modele, racine, tache }.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Config;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Entree;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Source;
use Ged\Ingestion\Travaux;
use Ged\Jonctions\Jetons;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Vues;

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
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/ecrans-reels/documents');
    supprimerDossier($fonds);
    if (!mkdir($fonds, 0775, true) && !is_dir($fonds)) {
        throw new RuntimeException("préparation : impossible de créer $fonds");
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Securite', 'Ingestion', 'Jonctions', 'Classement'] as $secteur) {
        ('Ged\\' . $secteur . '\\Schema')::installer($pdo);
    }
    remettreEtatFondsE2e($pdo);

    // Compte RÉEL, créé comme par l'installateur : identifiant = adresse de courriel, mot de passe haché.
    $identifiant = 'recette-ecrans@gedv2.test';
    $motDePasse = 'MotDePasse-Ecrans-2026!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), 'admin']);
    $compteId = (int) $pdo->query('SELECT id FROM comptes WHERE identifiant = ' . $pdo->quote($identifiant))->fetchColumn();

    // La configuration de la GED vit dans le fonds (`.ged/`) : le recréer à neuf l'a remise à zéro.
    (new Nomenclature($config))->assurer();

    // Mes lignes d'index seulement (dossier d'oracle dédié) : jamais une ligne d'un autre parcours.
    $miennes = $pdo->query("SELECT id FROM documents WHERE chemin_relatif LIKE 'oracle-ecrans-reels/%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($miennes !== []) {
        $liste = implode(',', array_map('intval', $miennes));
        $pdo->exec("DELETE FROM document_versions WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM document_deplacements WHERE document_id IN ($liste)");
        $pdo->exec("DELETE FROM documents WHERE id IN ($liste)");
    }

    // Contenu réel du PDF synthétique du corpus : la visionneuse a un vrai fichier à rendre.
    $modele = $racine . '/tests/corpus/apercu/documents/201-facture-an-5001.pdf';
    $contenu = is_file($modele) ? (string) file_get_contents($modele) : "%PDF-1.4\n%%EOF\n";

    $depot = new DepotFichiers($config);
    $deposer = static function (array $meta) use ($depot, $contenu, $fonds): int {
        $source = sys_get_temp_dir() . '/gedv2-ecrans-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($source, $contenu);
        // Un dossier dédié à l'oracle : le nettoyage cible ces lignes sans toucher aux autres parcours.
        $id = $depot->deposer($source, ['dossier' => 'oracle-ecrans-reels'] + $meta);
        $depot->mettreAJour($id, ['id' => $id], 'oracle');   // `.meta.json` auto-descriptif (visionneuse)
        @unlink($source);

        return $id;
    };

    // Pièce SANS proposition : type vide, aucun `classement`, aucun émetteur.
    $idSans = $deposer([
        'titre' => 'scans-dossier-103p (p. 1)',
        'type' => null,
        'emetteur' => null,
        'date' => '2001-01-01',
        'statut' => 'a_controler',
        'source' => 'dossier',
        'texte' => 'Pièce synthétique sans proposition automatique.',
        'origine' => ['lot' => 0, 'pages' => '1'],
    ]);

    // Pièce AVEC proposition : source règle, confiance et raison lisibles.
    $idAvec = $deposer([
        'titre' => 'Facture oracle proposition',
        'type' => 'Facture',
        'emetteur' => 'Fournisseur Oracle',
        'date' => '2001-01-05',
        'montant' => 101.0,
        'statut' => 'a_controler',
        'source' => 'dossier',
        'texte' => 'Facture FICTIVE-ORACLE-1 — règlement à 30 jours.',
        'raison' => 'confiance IA insuffisante (0.62 < 0.8)',
        'classement' => ['source' => 'regle', 'confiance' => 0.62, 'preuve' => 'mot « facture » dans le texte'],
        'origine' => ['lot' => 0, 'pages' => '3'],
    ]);

    // Deux documents valides pour les vues enregistrées.
    $idFacture = $deposer(['titre' => 'Facture oracle vue', 'type' => 'Facture', 'emetteur' => 'Fournisseur Oracle',
        'date' => '2026-02-01', 'statut' => 'valide', 'source' => 'dossier']);
    $idContrat = $deposer(['titre' => 'Contrat oracle vue', 'type' => 'Contrat', 'emetteur' => 'Bailleur Oracle',
        'date' => '2026-02-02', 'statut' => 'valide', 'source' => 'dossier']);

    // Un document ingéré par le VRAI Pilote (chaîne d'ingestion complète : Travaux → classement réel →
    // DepotFichiers). Le moteur de découpe externe (ClearMyDocs) vit dans un autre dépôt : le test fournit
    // le découpeur à sa place, mais l'orchestration, le classement et le rangement sont ceux de production.
    // Le PDF fait 13 pages : c'est lui qui doit être RENDU par la visionneuse des trois écrans.
    $zone = $config->chemin('INGESTION_TRAVAIL', 'var/ingestion');
    $jeton = bin2hex(random_bytes(4));
    $emetteurPilote = 'Papeterie Fictive ' . $jeton . ' SA';   // unique : aucune habitude d'un passage précédent
    $contenuPilote = sys_get_temp_dir() . '/gedv2-ecrans-pilote-' . $jeton . '.pdf';
    ecrirePdf(array_map(static fn (int $n): string => 'LOT FICTIF ' . $jeton . ' — page ' . $n . ' sur 13', range(1, 13)), $contenuPilote);

    $source = new class ($contenuPilote) implements Source {
        private bool $fait = false;

        public function __construct(private readonly string $fichier)
        {
        }

        public function relever(): array
        {
            if ($this->fait) {
                return [];
            }
            $this->fait = true;

            return [new Entree($this->fichier, 'dossier', 'lot-oracle-13p.pdf')];
        }
    };

    $decoupeur = new class ($emetteurPilote) implements Decoupeur {
        public function __construct(private readonly string $emetteur)
        {
        }

        public function decouper(string $fichier, string $sortie): array
        {
            if (!is_dir($sortie) && !mkdir($sortie, 0775, true) && !is_dir($sortie)) {
                throw new RuntimeException("moteur simulé : impossible de créer $sortie");
            }
            $piece = $sortie . '/piece-13p.pdf';
            ecrirePdf(array_map(
                fn (int $n): string => 'FACTURE FICTIVE ' . $this->emetteur . ' — page ' . $n . ' sur 13',
                range(1, 13),
            ), $piece);

            return [[
                'fichier' => $piece,
                'pages' => range(1, 13),   // une seule pièce de 13 pages (origine.pages = « 1-13 »)
                'texte' => '**' . $this->emetteur . "**\nFacture N° F-ORACLE-13P\nDate : 05.01.2026\nTotal fictif : CHF 101.00",
                'analyse' => ['origine' => null],
            ]];
        }
    };

    $pilote = new Pilote(new Travaux($pdo), [$source], $decoupeur, $depot,
        new ClasseurGed($config, $pdo, new ClientIaNul()), $zone, $config);
    $pilote->tic(20);   // reçoit le lot
    $pilote->tic(20);   // découpe la pièce puis la range par le dépôt réel

    $idPilote = (int) $pdo->query('SELECT id FROM documents WHERE meta LIKE '
        . $pdo->quote('%' . $jeton . '%') . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($idPilote === 0) {
        throw new RuntimeException('ingestion Pilote : le document attendu n’a pas été rangé');
    }
    // Le fonds de l'oracle est un dossier dédié : on y range la pièce pour que la remise à neuf suivante
    // la balaie (le dossier fait foi, la ligne d'index suit).
    $depot->mettreAJour($idPilote, ['dossier' => 'oracle-ecrans-reels', 'id' => $idPilote], 'oracle');
    @unlink($contenuPilote);

    // Deux VRAIES vues du fonds (registre .ged/vues.json), jamais des liens figés.
    $vues = new Vues($config);
    $idVueFactures = $vues->enregistrer('Factures à payer', ['type' => 'Facture'], 'oracle');
    $idVueContrats = $vues->enregistrer('Contrats à échéance', ['type' => 'Contrat'], 'oracle');

    // Dernier travail d'ingestion RÉEL, dans la zone de travail de l'instance.
    $zone = $config->chemin('INGESTION_TRAVAIL', 'var/ingestion');
    $pdo->prepare('INSERT INTO ingestion_travaux (empreinte, source, nom_origine, chemin, parent_id, etat, maj_le)'
        . " VALUES (?, 'dossier', ?, ?, 0, 'range', NOW())")
        ->execute([hash('sha256', 'oracle-ecrans-' . bin2hex(random_bytes(4))), 'lot-oracle-ecrans.pdf',
            $zone . '/recus/lot-oracle-ecrans.pdf']);

    // Appairage ClearMyMails actif : l'écran Application doit le voir (état réel des Jonctions). Un seul
    // suffit — on n'empile pas une ligne par passage d'oracle (la table de test est partagée).
    $dejaRelie = (int) $pdo->query("SELECT COUNT(*) FROM jonctions_jetons WHERE nature = 'appareil'"
        . " AND LOWER(application) = 'cmm' AND revoque_le IS NULL")->fetchColumn();
    if ($dejaRelie === 0) {
        (new Jetons($pdo))->creerAppairage('cmm', 'poste-oracle-ecrans', $compteId, $identifiant);
    }

    // Le compteur de la file « à traiter » de CE fonds : chemin indexé ET fichier présent (le dossier fait foi).
    $compteur = 0;
    foreach ($pdo->query("SELECT chemin_relatif FROM documents WHERE supprime_le IS NULL AND statut = 'a_controler'") as $ligne) {
        if (is_file($fonds . '/' . $ligne['chemin_relatif'])) {
            $compteur++;
        }
    }

    echo json_encode([
        'preparation' => 'prete',
        'compte' => $identifiant,
        'mot_de_passe' => $motDePasse,
        'fonds' => $fonds,
        'ids' => ['sans_proposition' => $idSans, 'avec_proposition' => $idAvec, 'facture' => $idFacture, 'contrat' => $idContrat,
            'pilote' => $idPilote],
        'pages_pilote' => 13,
        'vues' => ['factures' => $idVueFactures, 'contrats' => $idVueContrats],
        'compteur' => $compteur,
        'modele' => $config->get('INFOMANIAK_AI_MODEL_CLASSIFY', 'google/gemma-4-31B-it'),
        'racine' => $config->get('STORAGE_ROOT', 'storage/documents'),
        'tache' => 'lot-oracle-ecrans.pdf',
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

/**
 * Écrit un PDF minimal SYNTHÉTIQUE de N pages (texte latin, aucune police embarquée), pour que la
 * visionneuse ait un vrai fichier à rendre — même technique que le corpus d'aperçu.
 *
 * @param list<string> $pagesTextes
 */
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
