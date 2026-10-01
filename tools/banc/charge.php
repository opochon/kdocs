<?php

declare(strict_types=1);

/**
 * Porte « 6-charge » du harness.
 *
 * Sur le serveur de TEST (gedv2_test sous le verrou partagé), on joue 3 puis 10 utilisateurs simultanés,
 * dont 2 à 3 en écriture, sur un fonds de 10 000 documents préparé dans `var/charge/` (jamais dans
 * `tests/corpus`). Chaque utilisateur ouvre la bibliothèque, cherche, ouvre un aperçu, et — pour les
 * écrivains — valide des pièces dans « À traiter » ; pendant tout ce temps, une tâche d'ingestion
 * (`tools/tache.php ingestion`) tourne sur le lot `courrier-100p` du corpus et écrit dans le même fonds.
 *
 *   php tools/banc/charge.php [--documents=10000] [--sans-ingestion] [--purger] [--aide]
 *
 * Pour obtenir de la vraie concurrence avec le serveur intégré de PHP (mono-processus sous Windows), la
 * sonde démarre N serveurs `php -S` isolés (un par utilisateur) et pilote les requêtes avec `curl_multi` :
 * plusieurs requêtes sont réellement en vol, sur des processus PHP distincts, autour des mêmes fichiers
 * et de la même base MariaDB.
 *
 * Preuves : `recette/preuves/charge/charge.json` + `charge.md`. Code de sortie 0 si la porte tient :
 *   p95 lecture < 1,5 s ; p95 écriture < 3 s ; 0 erreur (500, CSRF, verrou) ; 0 collision (aucune
 *   validation perdue ni doublée) ; la tâche d'ingestion finit sans erreur.
 *
 * L'index documentaire est posé EN BLOC, comme le fait `Ged\Socle\Reconstructeur` : le DOSSIER fait foi
 * (chaque `.meta.json` porte son `id`), la base n'en est que l'index reconstructible.
 */

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\SourceRecherche;
use Ged\Securite\RegistreSessions;
use Ged\Socle\Fichiers;

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

// ---------------------------------------------------------------- options
$documents = 10000;
$avecIngestion = true;
$purger = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--documents=(\d+)$/', $arg, $m) === 1) {
        $documents = max(50, (int) $m[1]);
    } elseif ($arg === '--sans-ingestion') {
        $avecIngestion = false;
    } elseif ($arg === '--purger') {
        $purger = true;
    } elseif ($arg === '--aide') {
        echo "Usage : php tools/banc/charge.php [--documents=10000] [--sans-ingestion] [--purger]\n";

        exit(0);
    }
}

const SEUIL_LECTURE_MS = 1500.0;   // p95 en lecture
const SEUIL_ECRITURE_MS = 3000.0;  // p95 en écriture
const TOURS_LECTURE = 3;           // bibliothèque + recherche + à traiter + aperçu, par tour
const LECTURE_MAX_S = 30;          // au-delà, la requête est comptée en erreur (verrou mort, boucle…)

$bac = $racine . '/var/charge';
$fonds = $bac . '/fonds/documents';
$entree = $bac . '/entree';
$travail = $bac . '/travail';
$securite = $bac . '/securite';
$logs = $bac . '/logs';
$preuves = $racine . '/recette/preuves/charge';

$debut = microtime(true);
$serveurs = [];
$ingestion = null;
$resume = 'ECHEC: charge — la porte n’a pas tenu';

// Environnement de TEST imposé : base gedv2_test, fonds de charge dans var/.
foreach (['DB_NAME' => 'gedv2_test', 'APP_ENV' => 'test', 'STORAGE_ROOT' => $fonds,
    'SECURITE_DOSSIER' => $securite, 'INGESTION_DOSSIER' => $entree, 'INGESTION_TRAVAIL' => $travail,
    // La disponibilité/latence d'Infomaniak est couverte par mesurer-classement --ia. Ici, le but est
    // de mesurer la GED (10 utilisateurs + reprise d'ingestion) : une indisponibilité réseau ne doit
    // pas consommer le budget du tic et rendre la porte de concurrence aléatoire.
    'INGESTION_IMAP_HOTE' => '', 'INFOMANIAK_AI_ENABLED' => 'false'] as $cle => $valeur) {
    putenv("$cle=$valeur");
}

// Un seul exécutant à la fois sur gedv2_test (même verrou que PHPUnit et les autres bancs).
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "ECHEC: verrou gedv2_test indisponible\n");

    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('le banc de charge n’écrit que sur gedv2_test');
    }
    Base::assurerBase($config);
    $pdo = Base::pdo($config);

    foreach ([$bac, $fonds, $entree, $travail, $securite, $logs, $preuves] as $dossier) {
        is_dir($dossier) || mkdir($dossier, 0775, true);
    }
    // Journaux neufs : un motif de verrou d'un rejeu précédent ne doit pas faire échouer celui-ci.
    foreach (glob($logs . '/*') ?: [] as $ancien) {
        is_file($ancien) && @unlink($ancien);
    }

    echo "Charge : préparation du fonds de $documents documents…\n";
    $prep = preparerFonds($pdo, $fonds, $entree, $travail, $securite, $documents);
    $fiches = $prep['fiches'];
    $previews = $prep['previews'];
    $termes = ['maintenance', 'facture', 'contrat', 'attestation', 'devis'];

    echo 'Charge : index plein texte sur ' . count($prep['controles']) . " pièces à contrôler…\n";
    $index = new IndexPleinTexte($pdo, new SourceRecherche($fonds));
    $index->synchroniser(null);

    $initial = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();

    // La tâche d'ingestion démarre AVANT la charge : elle découpe le lot pendant que les utilisateurs lisent.
    if ($avecIngestion) {
        $ingestion = demarrerIngestion($racine, $entree, $fonds, $travail, $securite, $logs, $config);
        echo "Charge : tâche d’ingestion lancée (lot courrier-100p)…\n";
    }

    // N serveurs PHP isolés : un par utilisateur, pour de la vraie concurrence.
    $utilisateursMax = 10;
    for ($u = 0; $u < $utilisateursMax; $u++) {
        $port = portLibre();
        $serveurs[$u] = demarrerServeur($racine, $port, $fonds, $entree, $travail, $securite, $logs, $u);
        attendreSante($serveurs[$u]['base']);
    }
    echo 'Charge : ' . count($serveurs) . " serveurs de test prêts.\n";

    $sessions = new RegistreSessions($securite . '/sessions');
    echauffer($serveurs, $sessions, $previews);

    $etat = [
        'controles' => $prep['controles'],
        'fiches' => $fiches,
        'previews' => $previews,
        'termes' => $termes,
        'attendus' => [],
        'sessions' => [],
    ];
    $phases = [
        ['nom' => '3-utilisateurs', 'utilisateurs' => 3, 'ecrivains' => 2],
        ['nom' => '10-utilisateurs', 'utilisateurs' => 10, 'ecrivains' => 3],
    ];
    $validationsParEcrivain = max(3, min(20, intdiv(max(20, count($prep['controles'])), 2 * count($phases) + 4)));
    $etat['validations_par_ecrivain'] = $validationsParEcrivain;

    $resultats = [];
    $phasesMesurees = [];
    foreach ($phases as $phase) {
        echo 'Charge : ' . $phase['nom'] . "…\n";
        $phasesMesurees[$phase['nom']] = jouerPhase($phase, $serveurs, $sessions, $etat, $resultats);
    }

    // ------------------------------------------------------------ ingestion
    $ingestionBilan = $avecIngestion
        ? attendreIngestion($ingestion, $pdo, $initial)
        : ['finie' => true, 'detail' => 'désactivée (option)', 'duree_s' => 0.0, 'documents_ajoutes' => 0,
            'travaux_restants' => 0, 'erreurs' => 0, 'code' => 0];

    // ------------------------------------------------------------ collisions et erreurs
    $verrousMorts = compterPatternLogs($logs, '/(deadlock|lock wait timeout|sqlstate\[40001\]|sqlstate\[hy000\]|too many connections)/i');
    $collisions = verifierCollisions($pdo, $fonds, $etat['attendus'], $resultats, $verrousMorts);

    // ------------------------------------------------------------ porte
    foreach ($phasesMesurees as $nom => $mesure) {
        $mesure['ok'] = $mesure['lecture_p95_ms'] < SEUIL_LECTURE_MS
            && $mesure['ecriture_p95_ms'] < SEUIL_ECRITURE_MS
            && $mesure['erreurs'] === 0;
        $phasesMesurees[$nom] = $mesure;
    }
    $defauts = [];
    foreach ($phasesMesurees as $nom => $mesure) {
        if ($mesure['lecture_p95_ms'] >= SEUIL_LECTURE_MS) {
            $defauts[] = sprintf('%s : p95 lecture %.0f ms ≥ %.0f ms', $nom, $mesure['lecture_p95_ms'], SEUIL_LECTURE_MS);
        }
        if ($mesure['ecriture_p95_ms'] >= SEUIL_ECRITURE_MS) {
            $defauts[] = sprintf('%s : p95 écriture %.0f ms ≥ %.0f ms', $nom, $mesure['ecriture_p95_ms'], SEUIL_ECRITURE_MS);
        }
        if ($mesure['erreurs'] !== 0) {
            $defauts[] = sprintf('%s : %d requête(s) en erreur', $nom, $mesure['erreurs']);
        }
    }
    if (!$collisions['ok']) {
        foreach ($collisions['details'] as $detail) {
            $defauts[] = 'collision : ' . $detail;
        }
    }
    if (!$ingestionBilan['finie']) {
        $defauts[] = 'ingestion : ' . $ingestionBilan['detail'];
    }
    $franchie = $defauts === [];

    $duree = round(microtime(true) - $debut, 1);
    $rapport = [
        'date' => date('c'),
        'commit' => commitCourant($racine),
        'duree_s' => $duree,
        'documents' => $documents,
        'fonds' => $fonds,
        'seuils' => ['lecture_p95_ms' => SEUIL_LECTURE_MS, 'ecriture_p95_ms' => SEUIL_ECRITURE_MS],
        'phases' => $phasesMesurees,
        'collisions' => $collisions,
        'ingestion' => $ingestionBilan,
        'defauts' => $defauts,
        'porte' => $franchie ? 'FRANCHIE' : 'NON FRANCHIE',
    ];
    publierPreuves($preuves, $rapport);

    $resume = sprintf('%s: charge — 3 puis 10 utilisateurs, p95 lecture %.0f ms, p95 écriture %.0f ms, %d erreur(s), %d collision(s), ingestion %s (%s)',
        $franchie ? 'OK' : 'ECHEC',
        max($phasesMesurees['3-utilisateurs']['lecture_p95_ms'], $phasesMesurees['10-utilisateurs']['lecture_p95_ms']),
        max($phasesMesurees['3-utilisateurs']['ecriture_p95_ms'], $phasesMesurees['10-utilisateurs']['ecriture_p95_ms']),
        max($phasesMesurees['3-utilisateurs']['erreurs'], $phasesMesurees['10-utilisateurs']['erreurs']),
        $collisions['perdues'] + $collisions['doublons'],
        $ingestionBilan['finie'] ? 'finie' : 'INCOMPLÈTE',
        $franchie ? 'porte franchie' : implode(' ; ', $defauts));

    if ($purger) {
        supprimerDossier($bac);
    }
} catch (Throwable $e) {
    $resume = 'ECHEC: charge — ' . $e->getMessage();
} finally {
    foreach ($serveurs as $serveur) {
        arreterServeur($serveur['processus']);
    }
    if (is_array($ingestion)) {
        arreterServeur($ingestion['processus']);
    }
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

echo $resume . "\n";
exit(str_starts_with($resume, 'OK:') ? 0 : 1);

// ============================================================================
//  Préparation du fonds
// ============================================================================

/**
 * Génère les fichiers + `.meta.json` du fonds (dans var/), puis pose l'index en bloc.
 *
 * @return array{controles:list<int>, previews:list<int>, fiches:array<int, array<string, mixed>>}
 */
function preparerFonds(PDO $pdo, string $fonds, string $entree, string $travail, string $securite, int $n): array
{
    // Schémas de tous les secteurs présents : l'application complète répond, pas un sous-ensemble.
    foreach (['Socle', 'Securite', 'Ingestion', 'Classement', 'Recherche', 'Workflows', 'Jonctions', 'Archivage'] as $secteur) {
        $classe = 'Ged\\' . $secteur . '\\Schema';
        if (class_exists($classe)) {
            $classe::installer($pdo);
        }
    }

    // Base de TEST uniquement : on repart d'un index vide pour que les comptes soient ceux de ce rejeu.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['recherche_textes', 'document_versions', 'document_deplacements', 'documents',
        'classement_choix', 'ingestion_travaux', 'ingestion_messages'] as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE $table");
        } catch (PDOException) {
            // table d'un secteur absent : rien à vider.
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    foreach ([$fonds, $entree, $travail, $securite] as $dossier) {
        supprimerDossier($dossier);
        mkdir($dossier, 0775, true);
    }

    $modele = dirname(__DIR__, 2) . '/tests/corpus/apercu/documents/201-facture-an-5001.pdf';
    $contenuPdf = is_file($modele) ? (string) file_get_contents($modele) : "%PDF-1.4\n%%EOF\n";
    $empreintePdf = hash('sha256', $contenuPdf);
    $taillePdf = strlen($contenuPdf);

    $types = ['Facture', 'Contrat', 'Bulletin de salaire', 'Attestation', 'Courrier', 'Devis'];
    $emetteurs = ['Fournisseur Alpha', 'Fournisseur Beta', 'Fournisseur Gamma', 'Client Delta', 'Client Epsilon',
        'Assurance Zeta', 'Banque Eta', 'Régie Theta', 'Hôpital Iota', 'Commune Kappa', 'Fiduciaire Lambda', 'Transport Mu'];
    $controle = min(240, max(6, intdiv($n, 8)));
    $dossiersCrees = [];
    $fiches = [];
    $controles = [];
    $previews = [];

    $insertion = $pdo->prepare('INSERT INTO documents (id, chemin_relatif, nom, empreinte, taille, mime, meta, '
        . 'titre, type, emetteur, date_document, montant, etiquettes, statut, source) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $pdo->beginTransaction();
    try {
        for ($i = 1; $i <= $n; $i++) {
            $type = $types[$i % count($types)];
            $emetteur = $emetteurs[intdiv($i, 37) % count($emetteurs)];
            $date = sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1);
            $statut = $i <= $controle ? 'a_controler' : 'valide';
            $titre = sprintf('%s charge %05d', $type, $i);
            $texte = sprintf("%s maintenance — dossier de charge %05d\nÉmetteur %s, date %s.", $type, $i, $emetteur, $date);

            $dossier = $fonds . '/2026/' . Fichiers::assainirSegment($type) . '/' . Fichiers::assainirSegment($emetteur);
            if (!isset($dossiersCrees[$dossier])) {
                is_dir($dossier) || mkdir($dossier, 0775, true);
                $dossiersCrees[$dossier] = true;
            }
            $nom = Fichiers::nomRangé($titre, 'pdf');
            $absolu = $dossier . '/' . $nom;
            $relatif = substr($absolu, strlen($fonds) + 1);

            file_put_contents($absolu, $contenuPdf);
            $meta = [
                'id' => $i,
                'titre' => $titre,
                'type' => $type,
                'emetteur' => $emetteur,
                'date' => $date,
                'montant' => 100.0 + $i,
                'etiquettes' => ['charge'],
                'statut' => $statut,
                'source' => 'dossier',
                'texte' => $texte,
                'raison' => 'confiance IA insuffisante (0.62 < 0.8)',
                'classement' => ['source' => 'regle', 'confiance' => 0.62, 'preuve' => 'mot « facture » dans le texte'],
                'origine' => ['lot' => 0, 'pages' => '1'],
            ];
            file_put_contents($absolu . '.meta.json', Fichiers::json($meta, true));

            [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
            $insertion->execute([$i, $relatif, basename($relatif), $empreintePdf, $taillePdf, 'application/pdf',
                Fichiers::json($meta), ...$valeurs]);

            if ($statut === 'a_controler') {
                $controles[] = $i;
                $fiches[$i] = ['titre' => $titre, 'type' => $type, 'emetteur' => $emetteur, 'date' => $date, 'montant' => 100.0 + $i];
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }

    // Un vivier d'aperçus, réparti sur le fonds (documents complets, jamais ceux en écriture).
    $espace = max(1, intdiv($n - $controle, 400));
    for ($i = $controle + 1; $i <= $n; $i += $espace) {
        $previews[] = $i;
    }

    return ['controles' => $controles, 'previews' => $previews, 'fiches' => $fiches];
}

// ============================================================================
//  Serveurs et ingestion
// ============================================================================

/** @return array{processus:resource, base:string, port:int} */
function demarrerServeur(string $racine, int $port, string $fonds, string $entree, string $travail, string $securite, string $logs, int $u): array
{
    $environnement = environnement([
        'DB_NAME' => 'gedv2_test', 'APP_ENV' => 'test', 'STORAGE_ROOT' => $fonds,
        'SECURITE_DOSSIER' => $securite, 'INGESTION_DOSSIER' => $entree, 'INGESTION_TRAVAIL' => $travail,
        'INGESTION_IMAP_HOTE' => '',
    ]);
    $commande = [PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port,
        '-t', $racine . '/public', $racine . '/public/index.php'];
    $processus = proc_open($commande, [
        0 => ['pipe', 'r'],
        1 => ['file', "$logs/serveur-$u.out", 'a'],
        2 => ['file', "$logs/serveur-$u.err", 'a'],
    ], $tuyaux, $racine, $environnement);
    if (!is_resource($processus)) {
        throw new RuntimeException("démarrage du serveur de test $u impossible");
    }
    fclose($tuyaux[0]);

    return ['processus' => $processus, 'base' => 'http://127.0.0.1:' . $port, 'port' => $port];
}

/**
 * Lance `tools/tache.php ingestion` en tâche de fond sur le lot du corpus.
 *
 * @return array{processus:resource, debut:float, sortie:string, erreurs:string}
 */
function demarrerIngestion(string $racine, string $entree, string $fonds, string $travail, string $securite, string $logs, Config $config): array
{
    $verite = json_decode((string) file_get_contents($racine . '/tests/corpus/courrier-100p/verite.json'), true, 512, JSON_THROW_ON_ERROR);
    $source = $racine . '/tests/corpus/courrier-100p/' . $verite['fichier'];
    if (!is_file($source)) {
        throw new RuntimeException('lot courrier-100p introuvable');
    }
    copy($source, $entree . '/' . basename($source));
    // `SourceDossier` n'accepte un fichier qu'au DEUXIÈME relevé (fichier stable) ; on simule le relevé
    // précédent pour qu'une SEULE invocation de la tâche fasse le travail. Le planificateur réel relève
    // périodiquement ; ce comportement est signalé en demande au secteur Ingestion (voir la fiche).
    (new \Ged\Ingestion\SourceDossier($entree, $travail . '/recus', $travail . '/etat-dossier.json'))->relever();

    $environnement = environnement([
        'DB_NAME' => 'gedv2_test', 'APP_ENV' => 'test', 'STORAGE_ROOT' => $fonds,
        'SECURITE_DOSSIER' => $securite, 'INGESTION_DOSSIER' => $entree, 'INGESTION_TRAVAIL' => $travail,
        'INGESTION_IMAP_HOTE' => '',
    ]);
    $sortie = "$logs/ingestion.out";
    $erreurs = "$logs/ingestion.err";
    $commande = [PHP_BINARY, $racine . '/tools/tache.php', 'ingestion', '--budget=300', '--tours=200'];
    $processus = proc_open($commande, [
        0 => ['pipe', 'r'],
        1 => ['file', $sortie, 'a'],
        2 => ['file', $erreurs, 'a'],
    ], $tuyaux, $racine, $environnement);
    if (!is_resource($processus)) {
        throw new RuntimeException('démarrage de la tâche d’ingestion impossible');
    }
    fclose($tuyaux[0]);

    return ['processus' => $processus, 'debut' => microtime(true), 'sortie' => $sortie, 'erreurs' => $erreurs,
        'lot' => basename($source)];
}

/**
 * Attend la fin de la tâche d'ingestion (borné) et vérifie qu'il ne reste, POUR CE LOT, rien à traiter.
 * Le périmètre est restreint au lot `courrier-100p` : un autre exécutant sur la base partagée ne fausse pas
 * le verdict de la charge.
 */
function attendreIngestion(array $ingestion, PDO $pdo, int $initial): array
{
    $fin = microtime(true) + 600;
    $code = null;
    while (microtime(true) < $fin) {
        $etat = proc_get_status($ingestion['processus']);
        if (!$etat['running']) {
            $code = (int) $etat['exitcode'];
            break;
        }
        usleep(500000);
    }
    $duree = round(microtime(true) - $ingestion['debut'], 1);
    $lot = (string) $ingestion['lot'];
    $requete = $pdo->prepare('SELECT id FROM ingestion_travaux WHERE parent_id = 0 AND nom_origine = ? ORDER BY id DESC LIMIT 1');
    $requete->execute([$lot]);
    $lotId = (int) ($requete->fetchColumn() ?: 0);
    $restants = -1;
    $erreurs = 0;
    if ($lotId > 0) {
        // Le lot parent reste en 'decoupe' une fois ses pièces produites (comportement actuel du pilote).
        // Ce qui « reste à faire » est donc : le lot non encore découpé, ou une pièce non encore rangée.
        $compte = $pdo->prepare("SELECT COUNT(*) FROM ingestion_travaux WHERE (id = ? AND etat = 'recu')"
            . " OR (parent_id = ? AND etat IN ('recu','decoupe','pret'))");
        $compte->execute([$lotId, $lotId]);
        $restants = (int) $compte->fetchColumn();
        $compte = $pdo->prepare("SELECT COUNT(*) FROM ingestion_travaux WHERE (id = ? OR parent_id = ?) AND etat = 'erreur'");
        $compte->execute([$lotId, $lotId]);
        $erreurs = (int) $compte->fetchColumn();
    }
    $ajoutes = 0;
    if ($lotId > 0) {
        try {
            $compte = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE JSON_EXTRACT(meta, '$.origine.lot') = ?");
            $compte->execute([$lotId]);
            $ajoutes = (int) $compte->fetchColumn();
        } catch (PDOException) {
            $ajoutes = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn() - $initial;
        }
    }
    $logErreurs = is_file($ingestion['erreurs']) ? (string) file_get_contents($ingestion['erreurs']) : '';
    $tuee = $code === null;

    $finie = !$tuee && $code === 0 && $lotId > 0 && $restants === 0 && $erreurs === 0 && $ajoutes > 0;
    $detail = $finie
        ? sprintf('%d pièces rangées, 0 travail restant', $ajoutes)
        : sprintf('code %s, lot %s, %d travail(x) non fini(s), %d erreur(s), %d pièce(s) rangée(s)%s',
            $tuee ? 'timeout' : (string) $code, $lotId > 0 ? (string) $lotId : 'introuvable', max(0, $restants), $erreurs, $ajoutes,
            $logErreurs !== '' ? ' — ' . trim(mb_substr($logErreurs, 0, 200)) : '');

    return ['finie' => $finie, 'detail' => $detail, 'duree_s' => $duree, 'documents_ajoutes' => $ajoutes,
        'travaux_restants' => max(0, $restants), 'erreurs' => $erreurs, 'code' => $code];
}

// ============================================================================
//  Parcours utilisateur
// ============================================================================

function echauffer(array $serveurs, RegistreSessions $sessions, array $previews): void
{
    $session = $sessions->ouvrir('charge-echauffement@gedv2.test', permissionsTest());
    foreach ($serveurs as $serveur) {
        requeteSimple($serveur['base'], '/bibliotheque', $session['id'], $session['csrf']);
    }
    // Un seul aperçu suffit à charger le fonds en cache disque pour tous les serveurs.
    if ($previews !== []) {
        requeteSimple($serveurs[0]['base'], '/documents/' . $previews[0] . '/apercu', $session['id'], $session['csrf']);
    }
}

/** @return array<string, mixed> */
function jouerPhase(array $phase, array $serveurs, RegistreSessions $sessions, array &$etat, array &$resultats): array
{
    $utilisateurs = [];
    $nbEcrivains = min($phase['ecrivains'], $phase['utilisateurs']);
    for ($u = 0; $u < $phase['utilisateurs']; $u++) {
        $session = $sessions->ouvrir('charge-' . $phase['nom'] . "-$u@gedv2.test", permissionsTest());
        $ecrivain = $u < $nbEcrivains;
        $steps = parcours($u, $ecrivain, $etat);
        $utilisateurs[$u] = [
            'index' => $u,
            'phase' => $phase['nom'],
            'base' => $serveurs[$u]['base'],
            'sid' => $session['id'],
            'csrf' => $session['csrf'],
            'steps' => $steps,
            'etape' => 0,
            'capture' => null,
            'debut' => 0.0,
        ];
    }
    executerParcours($utilisateurs, $resultats);

    return mesurerPhase($phase['nom'], $resultats);
}

/**
 * Construit la suite d'actions d'un utilisateur : lectures, puis validations disjointes pour les écrivains.
 *
 * @return list<array<string, mixed>>
 */
function parcours(int $u, bool $ecrivain, array &$etat): array
{
    $steps = [];
    $termes = $etat['termes'];
    $previews = $etat['previews'];
    for ($tour = 0; $tour < TOURS_LECTURE; $tour++) {
        $steps[] = ['type' => 'lecture', 'ecran' => 'bibliotheque', 'chemin' => '/bibliotheque'];
        $steps[] = ['type' => 'lecture', 'ecran' => 'recherche',
            'chemin' => '/recherche?q=' . urlencode($termes[($u + $tour) % count($termes)])];
        $steps[] = ['type' => 'lecture', 'ecran' => 'a-traiter', 'chemin' => '/a-traiter'];
        $steps[] = ['type' => 'lecture', 'ecran' => 'apercu',
            'chemin' => '/documents/' . $previews[($u * 7 + $tour) % count($previews)] . '/apercu'];
    }
    if (!$ecrivain) {
        return $steps;
    }
    $nombre = min($etat['validations_par_ecrivain'], count($etat['controles']));
    for ($k = 0; $k < $nombre; $k++) {
        $id = array_shift($etat['controles']);
        $fiche = $etat['fiches'][$id];
        $etat['attendus'][] = $id;
        $steps[] = [
            'type' => 'ecriture', 'ecran' => 'validation', 'doc' => $id,
            'chemin' => '/a-traiter/' . $id . '/valider',
            'corps' => http_build_query([
                'csrf' => '__CSRF__', 'titre' => $fiche['titre'], 'type' => $fiche['type'],
                'emetteur' => $fiche['emetteur'], 'date' => $fiche['date'], 'montant' => (string) $fiche['montant'],
            ]),
        ];
    }

    return $steps;
}

/** Exécute les parcours de tous les utilisateurs en parallèle réel via curl_multi. */
function executerParcours(array $utilisateursInit, array &$resultats): void
{
    $multi = curl_multi_init();
    $utilisateurs = $utilisateursInit;
    $map = [];
    $actifs = 0;
    foreach (array_keys($utilisateurs) as $u) {
        demarrerEtapeMulti($utilisateurs[$u], $multi, $map);
        $actifs++;
    }

    while ($actifs > 0) {
        $statut = curl_multi_exec($multi, $running);
        while ($statut === CURLM_CALL_MULTI_PERFORM) {
            $statut = curl_multi_exec($multi, $running);
        }
        if ($running > 0 && curl_multi_select($multi, 0.5) === -1) {
            usleep(20000);
        }
        while (($info = curl_multi_info_read($multi)) !== false) {
            $ch = $info['handle'];
            $u = $map[spl_object_id($ch)] ?? null;
            if ($u === null) {
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                continue;
            }
            $step = $utilisateurs[$u]['steps'][$utilisateurs[$u]['etape']];
            $resultats[] = [
                'phase' => $utilisateurs[$u]['phase'],
                'ecran' => $step['ecran'],
                'type' => $step['type'],
                'doc' => $step['doc'] ?? null,
                'code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'ms' => (microtime(true) - $utilisateurs[$u]['debut']) * 1000,
                'erreur' => curl_error($ch),
            ];
            if ($step['type'] === 'ecriture' && $utilisateurs[$u]['capture']->csrf !== null) {
                $utilisateurs[$u]['csrf'] = $utilisateurs[$u]['capture']->csrf;
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $utilisateurs[$u]['etape']++;
            if ($utilisateurs[$u]['etape'] < count($utilisateurs[$u]['steps'])) {
                demarrerEtapeMulti($utilisateurs[$u], $multi, $map);
            } else {
                $actifs--;
            }
        }
    }
    curl_multi_close($multi);
}

function demarrerEtapeMulti(array &$u, CurlMultiHandle $multi, array &$map): void
{
    $step = $u['steps'][$u['etape']];
    $u['capture'] = (object) ['csrf' => null];
    $capture = $u['capture'];
    $ch = curl_init($u['base'] . $step['chemin']);
    $entetes = ['Cookie: gedv2_session=' . $u['sid'] . '; gedv2_csrf=' . $u['csrf']];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => LECTURE_MAX_S,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($step['type'] === 'ecriture') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, str_replace('__CSRF__', $u['csrf'], $step['corps']));
        $entetes[] = 'Content-Type: application/x-www-form-urlencoded';
        $entetes[] = 'X-CSRF-Token: ' . $u['csrf'];
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $ligne) use ($capture): int {
        if (stripos($ligne, 'set-cookie:') === 0 && preg_match('/gedv2_csrf=([^;]+)/i', $ligne, $m) === 1) {
            $capture->csrf = $m[1];
        }

        return strlen($ligne);
    });
    curl_setopt($ch, CURLOPT_HTTPHEADER, $entetes);
    $u['debut'] = microtime(true);
    curl_multi_add_handle($multi, $ch);
    $map[spl_object_id($ch)] = $u['index'];
}

// ============================================================================
//  Mesures et vérifications
// ============================================================================

function mesurerPhase(string $nom, array $resultats): array
{
    $ecrans = [];
    $erreurs = 0;
    foreach ($resultats as $r) {
        if ($r['phase'] !== $nom) {
            continue;
        }
        $ecrans[$r['ecran']][] = $r['ms'];
        $ok = $r['erreur'] === '' && ($r['type'] === 'lecture' ? $r['code'] === 200 : $r['code'] === 303);
        if (!$ok) {
            $erreurs++;
        }
    }
    $lectures = ['bibliotheque', 'recherche', 'a-traiter', 'apercu'];
    $parEcran = [];
    $lectureP95 = 0.0;
    foreach ($ecrans as $ecran => $valeurs) {
        if ($valeurs === []) {
            continue;
        }
        $p50 = centile($valeurs, 0.5);
        $p95 = centile($valeurs, 0.95);
        $parEcran[$ecran] = ['n' => count($valeurs), 'p50_ms' => round($p50), 'p95_ms' => round($p95), 'max_ms' => round(max($valeurs))];
        if (in_array($ecran, $lectures, true)) {
            $lectureP95 = max($lectureP95, $p95);
        }
    }
    $ecriture = $ecrans['validation'] ?? [];
    $ecritureP95 = $ecriture === [] ? 0.0 : centile($ecriture, 0.95);

    return [
        'nom' => $nom,
        'par_ecran' => $parEcran,
        'lecture_p95_ms' => round($lectureP95),
        'ecriture_p95_ms' => round($ecritureP95),
        'ecriture_n' => count($ecriture),
        'requetes' => count(array_filter($resultats, static fn (array $r): bool => $r['phase'] === $nom)),
        'erreurs' => $erreurs,
    ];
}

/** @return array{perdues:int, doublons:int, validees:int, attendues:int, verrous_morts:int, ok:bool, details:list<string>} */
function verifierCollisions(PDO $pdo, string $fonds, array $attendus, array $resultats, int $verrousMorts): array
{
    $attendus = array_values(array_unique($attendus));
    $succes = [];
    foreach ($resultats as $r) {
        if ($r['ecran'] === 'validation' && $r['doc'] !== null && $r['code'] === 303 && $r['erreur'] === '') {
            $succes[$r['doc']] = ($succes[$r['doc']] ?? 0) + 1;
        }
    }
    $perdues = [];
    $doublons = [];
    $validees = 0;
    $fiches = [];
    if ($attendus !== []) {
        $ph = implode(',', array_map('intval', $attendus));
        $lignes = $pdo->query("SELECT id, chemin_relatif, statut FROM documents WHERE id IN ($ph)")->fetchAll();
        foreach ($lignes as $ligne) {
            $fiches[(int) $ligne['id']] = $ligne;
        }
        foreach ($attendus as $id) {
            if (($succes[$id] ?? 0) > 1) {
                $doublons[] = $id;
            }
            $ligne = $fiches[$id] ?? null;
            $chemin = $ligne['chemin_relatif'] ?? null;
            $meta = $chemin !== null && is_file($fonds . '/' . $chemin . '.meta.json')
                ? (json_decode((string) file_get_contents($fonds . '/' . $chemin . '.meta.json'), true) ?: [])
                : [];
            $validePartout = ($ligne['statut'] ?? '') === 'valide' && ($meta['statut'] ?? '') === 'valide';
            if ($validePartout && ($succes[$id] ?? 0) === 1) {
                $validees++;
            } elseif (!$validePartout) {
                $perdues[] = $id;
            }
        }
    }
    $details = [];
    if ($perdues !== []) {
        $details[] = count($perdues) . ' validation(s) perdue(s) (ex. ' . implode(',', array_slice($perdues, 0, 5)) . ')';
    }
    if ($doublons !== []) {
        $details[] = count($doublons) . ' validation(s) doublée(s) (ex. ' . implode(',', array_slice($doublons, 0, 5)) . ')';
    }
    if ($verrousMorts > 0) {
        $details[] = $verrousMorts . ' verrou(x) mort(s) / attente(s) de verrou dans les journaux';
    }

    return ['perdues' => count($perdues), 'doublons' => count($doublons), 'validees' => $validees,
        'attendues' => count($attendus), 'verrous_morts' => $verrousMorts, 'ok' => $details === [], 'details' => $details];
}

function compterPatternLogs(string $logs, string $motif): int
{
    $total = 0;
    $fichiers = glob($logs . '/*.err') ?: [];
    foreach (array_merge($fichiers, [$logs . '/ingestion.err']) as $fichier) {
        if (is_file($fichier)) {
            $total += preg_match_all($motif, (string) file_get_contents($fichier)) ?: 0;
        }
    }

    return $total;
}

function centile(array $valeurs, float $p): float
{
    if ($valeurs === []) {
        return 0.0;
    }
    sort($valeurs);
    $n = count($valeurs);
    $rang = (int) ceil($p * $n) - 1;
    $rang = max(0, min($n - 1, $rang));

    return (float) $valeurs[$rang];
}

// ============================================================================
//  Preuves
// ============================================================================

function publierPreuves(string $preuves, array $rapport): void
{
    is_dir($preuves) || mkdir($preuves, 0775, true);
    file_put_contents($preuves . '/charge.json',
        json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    $libelles = ['bibliotheque' => 'Bibliothèque', 'recherche' => 'Recherche', 'a-traiter' => 'À traiter',
        'apercu' => 'Aperçu', 'validation' => 'Validation (écriture)'];
    $md = ['# Charge — 3 puis 10 utilisateurs — ' . date('Y-m-d H:i'), '',
        sprintf('Fonds de **%s documents** (var/charge), base `gedv2_test`, commit `%s`. Ingestion `courrier-100p` en parallèle.',
            number_format($rapport['documents'], 0, ',', ' '), $rapport['commit']), '',
        '| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |', '|---|---|---|---|---|'];
    foreach ($rapport['phases'] as $nom => $phase) {
        $md[] = sprintf('| %s | %d | %d ms | %d ms | %d |', $nom, $phase['requetes'],
            $phase['lecture_p95_ms'], $phase['ecriture_p95_ms'], $phase['erreurs']);
    }
    $md[] = '';
    foreach ($rapport['phases'] as $nom => $phase) {
        $md[] = "## $nom";
        $md[] = '';
        $md[] = '| Écran | n | p50 | p95 | max |';
        $md[] = '|---|---|---|---|---|';
        foreach ($phase['par_ecran'] as $ecran => $mesure) {
            $md[] = sprintf('| %s | %d | %d ms | %d ms | %d ms |', $libelles[$ecran] ?? $ecran,
                $mesure['n'], $mesure['p50_ms'], $mesure['p95_ms'], $mesure['max_ms']);
        }
        $md[] = '';
    }
    $md[] = '## Collisions et ingestion';
    $md[] = '';
    $c = $rapport['collisions'];
    $md[] = sprintf('- Validations attendues : %d ; validees (fichier + index) : %d ; perdues : %d ; doublees : %d ; verrous morts : %d.',
        $c['attendues'], $c['validees'], $c['perdues'], $c['doublons'], $c['verrous_morts']);
    $i = $rapport['ingestion'];
    $md[] = sprintf('- Ingestion : %s — %s (%.1f s, %d documents ajoutes).', $i['finie'] ? 'finie' : 'INCOMPLETE',
        $i['detail'], $i['duree_s'], $i['documents_ajoutes']);
    $md[] = '';
    $md[] = '## Porte';
    $md[] = '';
    $md[] = sprintf('- Seuils : p95 lecture < %.0f ms, p95 ecriture < %.0f ms, 0 erreur, 0 collision, ingestion finie.',
        $rapport['seuils']['lecture_p95_ms'], $rapport['seuils']['ecriture_p95_ms']);
    $md[] = '- Verdict : **' . $rapport['porte'] . '**';
    foreach ($rapport['defauts'] as $defaut) {
        $md[] = "- Defaut : $defaut";
    }
    file_put_contents($preuves . '/charge.md', implode("\n", $md) . "\n");
}

// ============================================================================
//  Outils HTTP et système
// ============================================================================

/** @return array{code:int, corps:string} */
function requeteSimple(string $base, string $chemin, string $sid, string $csrf): array
{
    $ch = curl_init($base . $chemin);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Cookie: gedv2_session=' . $sid . '; gedv2_csrf=' . $csrf],
    ]);
    $corps = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['code' => $code, 'corps' => $corps];
}

function attendreSante(string $base): void
{
    for ($essai = 0; $essai < 150; $essai++) {
        $ch = curl_init($base . '/sante');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $corps = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200 && is_string($corps) && str_contains($corps, 'gedv2')) {
            return;
        }
        usleep(100000);
    }

    throw new RuntimeException('serveur de test injoignable : ' . $base);
}

function portLibre(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $cause);
    if ($socket === false) {
        throw new RuntimeException('port de test indisponible : ' . $cause);
    }
    $nom = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr($nom, strrpos($nom, ':') + 1);
    if ($port < 1) {
        throw new RuntimeException('port de test invalide');
    }

    return $port;
}

/** @return list<string> */
function permissionsTest(): array
{
    return ['documents:lire', 'documents:traiter', 'recherche:lire', 'workflows:lire', 'application:administrer',
        'workflow.gerer', 'workflow.executer', 'workflow.transition', 'workflow.transition.valider'];
}

/** @param array<string, string> $surcharges @return array<string, string> */
function environnement(array $surcharges): array
{
    $environnement = getenv();
    if (!is_array($environnement)) {
        $environnement = [];
    }

    return array_merge($environnement, $surcharges);
}

function commitCourant(string $racine): string
{
    $sortie = [];
    @exec('git -C ' . escapeshellarg($racine) . ' rev-parse --short HEAD 2>&1', $sortie);

    return trim($sortie[0] ?? '');
}

function arreterServeur(mixed $processus): void
{
    if (!is_resource($processus)) {
        return;
    }
    @proc_terminate($processus);
    for ($i = 0; $i < 20 && (proc_get_status($processus)['running'] ?? false); $i++) {
        usleep(100000);
    }
    @proc_close($processus);
}

function supprimerDossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($chemin);
}
