<?php
declare(strict_types=1);

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\IndexPleinTexte;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema as SchemaSocle;

putenv('DB_NAME=gedv2_test'); putenv('APP_ENV=test');
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) exit(1);
try {
    $config = new Config($racine); $fonds = $config->chemin('STORAGE_ROOT', 'var/oracles/v2-12c/documents');
    effacer($fonds); mkdir($fonds, 0775, true); Base::assurerBase($config); $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo); SchemaSecurite::installer($pdo); SchemaRecherche::installer($pdo);
    // Les identifiants d'oracle sont stables sous la base de test partagée : ne retirer que nos anciennes lignes synthétiques.
    $pdo->exec("DELETE FROM documents WHERE titre IN ('Version synthétique', 'Facture Suisse', 'Facture France', 'Contrat hors dossier', 'Lot complet', 'Lot incomplet')");
    $compte = 'v2-12c@gedv2.test'; $mot = 'MotDePasse-V2-12c!';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')->execute([$compte, password_hash($mot, PASSWORD_DEFAULT), 'admin']);
    $temp = sys_get_temp_dir() . '/gedv2-v2-12c-' . bin2hex(random_bytes(4)); mkdir($temp, 0775, true);
    $depot = new DepotFichiers($config);
    $poser = static function (string $nom, string $contenu) use ($temp): string { $p = "$temp/$nom"; file_put_contents($p, $contenu); return $p; };
    $commun = ['type' => 'Facture', 'emetteur' => 'Atelier Fictif SA', 'date' => '2026-10-02', 'statut' => 'valide', 'source' => 'ecran'];
    $version = $depot->deposer($poser('version.pdf', "%PDF-1.4\n% v1 synthétique\n%%EOF\n"), $commun + ['titre' => 'Version synthétique', 'dossier' => '2026/Factures/Suisse']);
    $suisse = $depot->deposer($poser('suisse.pdf', "%PDF-1.4\n% suisse\n%%EOF\n"), $commun + ['titre' => 'Facture Suisse', 'dossier' => '2026/Factures/Suisse']);
    $france = $depot->deposer($poser('france.pdf', "%PDF-1.4\n% france\n%%EOF\n"), $commun + ['titre' => 'Facture France', 'dossier' => '2026/Factures/France']);
    $hors = $depot->deposer($poser('contrat.pdf', "%PDF-1.4\n% contrat\n%%EOF\n"), $commun + ['titre' => 'Contrat hors dossier', 'dossier' => '2026/Contrats']);
    // Le seul scénario de lot rend ses deux pièces visibles. Les autres parcours remettent ce fonds
    // à neuf avec des pièces déjà validées : leur index partagé ne doit pas alimenter une autre file.
    $statutLot = getenv('V212C_LOT_ACTIF') === '1' ? 'a_controler' : 'valide';
    // La file est chronologique : une date synthétique minimale garantit que le scénario retrouve ses
    // pièces avant les index orphelins d'un autre fonds d'essai.
    $lotBon = $depot->deposer($poser('lot-bon.pdf', "%PDF-1.4\n% lot bon\n%%EOF\n"), array_replace($commun, ['titre' => 'Lot complet', 'date' => '1000-01-01', 'statut' => $statutLot]));
    $lotMauvais = $depot->deposer($poser('lot-mauvais.pdf', "%PDF-1.4\n% lot mauvais\n%%EOF\n"), ['titre' => 'Lot incomplet', 'type' => 'Facture', 'date' => '1000-01-01', 'statut' => $statutLot]);
    // Même index plein texte que l'écran Recherche, construit hors requête sur ce fonds synthétique.
    IndexPleinTexte::depuis($config, $pdo)->synchroniser(null);
    echo json_encode(compact('compte', 'mot', 'version', 'suisse', 'france', 'hors', 'lotBon', 'lotMauvais'), JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally { flock($verrou, LOCK_UN); fclose($verrou); }

function effacer(string $dossier): void { if (!is_dir($dossier)) return; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $e) $e->isDir() ? rmdir($e->getPathname()) : unlink($e->getPathname()); rmdir($dossier); }
