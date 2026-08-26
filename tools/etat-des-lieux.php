<?php
/**
 * État des lieux régulier — le volet ORDONNANCEUR du versioning file server.
 *
 * Olivier (2026-08-25) : « un état des lieux régulier pour avoir une image si
 * il bosse en file server ». Deux déclencheurs décidés (« les deux ») :
 *   1. AU PASSAGE ADMIN (déjà posé) : AdminController appelle
 *      SnapshotService::scheduleAutoSnapshot() — métadonnées seules, gardé
 *      par intervalle, jamais de travail lourd dans la requête d'affichage.
 *   2. CET OUTIL, pour le Planificateur de tâches Windows — couvre le headless
 *      (personne n'a l'UI ouverte) et fait le travail LOURD que la page ne
 *      doit pas faire :
 *        a. indexation complète du fonds : toute divergence de hash (fichier
 *           modifié hors GED, mode file server) archive l'état d'avant dans
 *           .versions/ À CÔTÉ du fichier (attendu A3) ;
 *        b. snapshot des métadonnées (documents, dossiers, tags, ...), gardé
 *           par l'intervalle snapshot_auto_interval ;
 *        c. instantané initial des documents sans version (idempotent).
 *
 * Pose dans le Planificateur de tâches Windows (une fois, en admin) :
 *   schtasks /Create /TN "K-Docs etat des lieux" /SC HOURLY ^
 *     /TR "C:\wamp64\bin\php\php8.4.0\php.exe F:\DATA\DEVELOPPEMENT\GEDv1\tools\etat-des-lieux.php" /RU SYSTEM
 * (adapter le chemin de php ; HOURLY -> /SC DAILY /ST 03:00 pour un passage
 *  nocturne ; l'intervalle réel est de toute façon gardé par les settings.)
 *
 * Usage :
 *   php tools/etat-des-lieux.php           (exécute)
 *   php tools/etat-des-lieux.php --quiet   (sortie réduite, pour le planificateur)
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use KDocs\Services\FilesystemIndexer;
use KDocs\Services\SnapshotService;

$quiet = in_array('--quiet', $argv, true);
$say = static function (string $l) use ($quiet): void {
    if (!$quiet) {
        echo $l, "\n";
    }
};

$say('+==============================================================+');
$say('|   K-DOCS - ETAT DES LIEUX (ordonnanceur versioning)          |');
$say('+==============================================================+');

$exit = 0;

// 1. Fichiers : réindexation -> détection des modifications hors GED.
try {
    $t0 = microtime(true);
    $indexer = new FilesystemIndexer();
    $stats = $indexer->indexAll();
    $say(sprintf('  indexation : %d dossier(s), %d fichier(s), %d nouveaux, %d mis a jour (%.1fs)',
        $stats['folders'] ?? 0, $stats['files'] ?? 0, $stats['new'] ?? 0, $stats['updated'] ?? 0, microtime(true) - $t0));
} catch (\Throwable $e) {
    $say('  ECHEC indexation : ' . $e->getMessage());
    $exit = 1;
}

// 2. Instantané initial idempotent : les documents encore sans version.
try {
    $snap = (new FilesystemIndexer())->snapshotInitial($quiet);
    if ($snap['documents'] > 0) {
        $say(sprintf('  instantane initial : %d document(s), %d archive(s), %d erreur(s)',
            $snap['documents'], $snap['archives'], $snap['erreurs']));
    } else {
        $say('  instantane initial : rien a faire (tous versionnes)');
    }
    if ($snap['erreurs'] > 0) {
        $exit = 1;
    }
} catch (\Throwable $e) {
    $say('  ECHEC instantane initial : ' . $e->getMessage());
    $exit = 1;
}

// 3. Métadonnées : snapshot planifié, gardé par intervalle (24 h par défaut).
try {
    (new SnapshotService())->scheduleAutoSnapshot();
    $say('  snapshot metadonnees : verifie (cree seulement si l\'intervalle est echu)');
} catch (\Throwable $e) {
    $say('  ECHEC snapshot : ' . $e->getMessage());
    $exit = 1;
}

$say($exit === 0 ? '  etat des lieux : OK' : '  etat des lieux : AVEC ECHEC(S)');
exit($exit);
