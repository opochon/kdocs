<?php

declare(strict_types=1);

/**
 * Amorce des parcours d'écran du lot ged-parite-ecrans-1.
 *
 * Sème, dans le fonds de test `tests/corpus/apercu/documents` (STORAGE_ROOT imposé par Playwright) :
 *   - un document à classer (statut `a_controler`) pour le tableau de bord ;
 *   - un document mis à la corbeille, restaurable ;
 *   - un traitement en échec non acquitté ;
 *   - un courrier récent ;
 *   - une vue enregistrée épinglée au tableau de bord ;
 *   - une entrée de journal d'audit distinctive ;
 *   - un document stable pour l'accès par numéro d'archive.
 *
 * Base `gedv2_test` SEULEMENT, compte synthétique `testeur@gedv2.test`. Aucune donnée réelle.
 * La sortie JSON (var/e2e-ecrans.json) est relue par `nettoyer-ecrans.php` pour remettre le corpora à
 * son état committé (aucune donnée utilisateur réelle, uniquement des pièces synthétiques du parcours).
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;
use Ged\Securite\JournalAudit;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Taches;
use Ged\Socle\Vues;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation écrans : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('préparation écrans : seule gedv2_test est admise');
    }
    $fonds = rtrim(str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents')), '/');
    if (!str_ends_with($fonds, 'tests/corpus/apercu/documents')) {
        throw new RuntimeException('préparation écrans refusée hors du corpus de test');
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Securite', 'Ingestion', 'Classement', 'Recherche', 'Jonctions', 'Workflows', 'Archivage'] as $secteur) {
        ('Ged\\' . $secteur . '\\Schema')::installer($pdo);
    }

    // Compte synthétique des tests (jamais un compte réel).
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
        ->execute(['testeur@gedv2.test', password_hash('MotDePasse-Test-2026!', PASSWORD_DEFAULT), 'admin']);

    $depot = new DepotFichiers($config);
    $jeton = bin2hex(random_bytes(4));

    $aClasser = deposer($depot, 'E2E document à classer ' . $jeton, [
        'titre' => 'E2E document à classer ' . $jeton, 'type' => 'Facture', 'emetteur' => 'Fournisseur Fictif SA',
        'date' => '2026-10-01', 'statut' => 'a_controler', 'source' => 'ecran',
    ]);
    $corbeille = deposer($depot, 'E2E document corbeille ' . $jeton, [
        'titre' => 'E2E document corbeille ' . $jeton, 'type' => 'Courrier', 'emetteur' => 'Émetteur Fictif',
        'date' => '2026-10-02', 'statut' => 'valide', 'source' => 'ecran',
    ]);
    $depot->miseALaCorbeille($corbeille['id'], 'e2e');

    $archive = deposer($depot, 'E2E document archive ' . $jeton, [
        'titre' => 'E2E document archive ' . $jeton, 'type' => 'Contrat', 'emetteur' => 'Partenaire Fictif SA',
        'date' => '2026-10-03', 'statut' => 'valide', 'source' => 'ecran',
    ]);

    $taches = new Taches($config);
    $tache = $taches->ouvrir('CONSUME_FILE', 'MANUAL_TASK', 'e2e-ecrans-' . $jeton . '.pdf', 'e2e');
    $taches->echouer((int) $tache['id'], 'e2e : lecture interrompue (synthétique)');

    $pdo->prepare('INSERT INTO ingestion_messages (boite, message_id, pieces, sujet, statut, dossier, traite_le)'
        . ' VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE sujet = VALUES(sujet)')
        ->execute(['e2e@gedv2.test', 'e2e-message-' . $jeton, 1, 'E2E courrier synthétique ' . $jeton, 'SUCCESS', 'INBOX']);

    $vue = (new Vues($config))->enregistrer('E2E vue épinglée ' . $jeton, [
        'display_mode' => 'table', 'show_on_dashboard' => '1', 'show_in_sidebar' => '0',
    ], 'e2e');

    (new JournalAudit($config->chemin('SECURITE_DOSSIER', 'var/securite') . '/audit.ndjson'))
        ->ajouter('e2e-journal', 'e2e', ['cible' => 'e2e-journal-' . $jeton]);

    $etat = [
        'jeton' => $jeton,
        'fonds' => $fonds,
        'a_classer' => $aClasser,
        'corbeille' => $corbeille,
        'archive' => $archive,
        'tache' => ['id' => (int) $tache['id']],
        'vue' => ['id' => $vue],
    ];
    $dossierVar = $racine . '/var';
    if (!is_dir($dossierVar)) {
        mkdir($dossierVar, 0777, true);
    }
    file_put_contents($dossierVar . '/e2e-ecrans.json', json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode($etat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation écrans : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

/**
 * @param array<string, mixed> $meta
 * @return array{id:int, relatif:string}
 */
function deposer(DepotFichiers $depot, string $titre, array $meta): array
{
    $temporaire = tempnam(sys_get_temp_dir(), 'gedv2-e2e-ecrans-');
    if ($temporaire === false) {
        throw new RuntimeException('préparation écrans : fichier temporaire impossible');
    }
    // Le moteur fixe le nom stocké sur le titre et garde l'EXTENSION du fichier déposé : on dépose donc
    // un vrai .pdf (contenu minimal, synthétique) pour que le document soit un PDF lisible.
    $pdf = $temporaire . '.pdf';
    file_put_contents($pdf, "%PDF-1.4\n% GEDv2 corpus synthétique de test — " . $titre . "\n%%EOF\n");
    try {
        $id = $depot->deposer($pdf, $meta);
    } finally {
        @unlink($temporaire);
        @unlink($pdf);
    }
    $fiche = $depot->fiche($id);
    if ($fiche === null) {
        throw new RuntimeException('préparation écrans : document non relu après dépôt');
    }

    return ['id' => (int) $id, 'relatif' => str_replace('\\', '/', (string) $fiche['chemin_relatif'])];
}
