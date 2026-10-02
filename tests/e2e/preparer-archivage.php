<?php

declare(strict_types=1);

/** Prépare uniquement le fonds synthétique du parcours V2-09b, via le vrai Pilote. */
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

putenv('DB_NAME=gedv2_test'); putenv('APP_ENV=test');
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) { fwrite(STDERR, "verrou indisponible\n"); exit(1); }
try {
    $config = new Config($racine);
    if ($config->get('DB_NAME') !== 'gedv2_test') { throw new RuntimeException('base de test imposée'); }
    $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/archivage/documents');
    nettoyer($fonds); mkdir($fonds, 0775, true);
    $bac = sys_get_temp_dir() . '/gedv2-archivage-e2e-' . bin2hex(random_bytes(4));
    mkdir($bac . '/entree', 0775, true); mkdir($bac . '/travail', 0775, true);
    putenv('INGESTION_DOSSIER=' . $bac . '/entree'); putenv('INGESTION_TRAVAIL=' . $bac . '/travail');
    Base::assurerBase($config); $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo); SchemaIngestion::installer($pdo); SchemaSecurite::installer($pdo);
    $admin = 'archivage-admin@gedv2.test'; $lecteur = 'archivage-lecteur@gedv2.test';
    $secret = 'MotDePasse-Archivage-2026!';
    foreach ([[$admin, 'admin'], [$lecteur, 'lecteur']] as [$identifiant, $role]) {
        $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
            ->execute([$identifiant, password_hash($secret, PASSWORD_DEFAULT), $role]);
    }
    $suffixe = bin2hex(random_bytes(4));
    $titre = 'Facture archivage synthétique ' . $suffixe;
    file_put_contents($bac . '/entree/facture-archivage.pdf', "%PDF-1.4\n% facture synthétique archivage $suffixe\n%%EOF\n");
    $decoupeur = new class implements Decoupeur { public function decouper(string $f, string $sortie): array { mkdir($sortie, 0775, true); $piece = $sortie . '/piece.pdf'; copy($f, $piece); return [['fichier' => $piece, 'pages' => [1], 'texte' => 'Facture synthétique Archivage', 'analyse' => []]]; } };
    $classeur = new class($titre) implements Classeur { public function __construct(private readonly string $titre) {} public function proposer(string $t, array $a, string $n): array { return ['titre' => $this->titre, 'type' => 'Facture', 'emetteur' => 'Émetteur Archivage Fictif', 'date' => '2026-03-01', 'montant' => 42.0, 'etiquettes' => [], 'confiance' => .6, 'source' => 'regle', 'preuve' => 'oracle V2-09b', 'valide' => false, 'raison' => 'validation humaine attendue']; } };
    $depot = new DepotFichiers($config);
    $pilote = new Pilote(new Travaux($pdo), [new SourceDossier($bac . '/entree', $bac . '/travail/recus', $bac . '/travail/etat.json')], $decoupeur, $depot, $classeur, $bac . '/travail');
    // On s'arrête dès que le vrai Pilote a rangé la pièce : rejouer après rangement ré-ingérerait la
    // même source et masquerait l'objet de ce parcours, une seule pièce réelle à valider.
    for ($i = 0; $i < 10; $i++) {
        $pilote->tic(20);
        $requete = $pdo->prepare('SELECT chemin_relatif FROM documents WHERE titre = ? ORDER BY id DESC LIMIT 1'); $requete->execute([$titre]);
        $verifier = $requete->fetchColumn();
        if (is_string($verifier) && is_file($depot->cheminAbsolu($verifier))) { break; }
    }
    $requete = $pdo->prepare('SELECT id, chemin_relatif FROM documents WHERE titre = ? ORDER BY id DESC LIMIT 1'); $requete->execute([$titre]);
    $document = $requete->fetch(PDO::FETCH_ASSOC);
    if ($document === false || !is_file($depot->cheminAbsolu((string) ($document['chemin_relatif'] ?? '')))) { throw new RuntimeException('le Pilote n’a pas rangé le document synthétique'); }
    $idLecteur = (int) $pdo->prepare('SELECT id FROM comptes WHERE identifiant = ?')->execute([$lecteur]);
    $stmt = $pdo->prepare('SELECT id FROM comptes WHERE identifiant = ?'); $stmt->execute([$lecteur]); $idLecteur = (int) $stmt->fetchColumn();
    $droits = new DroitsDossiers($pdo); $groupe = $droits->creerGroupe('lecteur archivage ' . bin2hex(random_bytes(3)), 'lecteur');
    $droits->ajouterCompteAuGroupe($idLecteur, $groupe); $dossier = $droits->dossierPourDocument((int) $document['id']);
    if ($dossier === null) { throw new RuntimeException('document sans dossier ACL'); } $droits->poserRegle($dossier, $groupe, 'lire', true);
    echo json_encode(['document' => (int) $document['id'], 'titre' => $titre, 'absolu' => $fonds . '/' . $document['chemin_relatif'], 'admin' => $admin, 'lecteur' => $lecteur, 'mot_de_passe' => $secret], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'préparation archivage : ' . $e->getMessage() . "\n"); exit(1); } finally { flock($verrou, LOCK_UN); fclose($verrou); }
function nettoyer(string $chemin): void { if (!is_dir($chemin)) return; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); } rmdir($chemin); }
