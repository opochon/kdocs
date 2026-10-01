<?php

declare(strict_types=1);

/**
 * (Re)pose la ligne d'index de recherche de la cible du parcours mobile, juste avant la recherche.
 *
 *   DOCUMENT_ID=<id> TITRE=<titre> STORAGE_ROOT=<dossier> php tests/e2e/indexer-mobile.php
 *
 * Pourquoi ce script : la table `recherche_textes` est partagée entre les bancs Playwright, et le job
 * d'indexation d'un AUTRE fonds marque « absent » les lignes qu'il ne voit pas. Reposer ma ligne juste
 * avant de chercher garde l'oracle mobile isolé, sans toucher au fonds ni à l'index d'un autre parcours.
 */

$racine = dirname(__DIR__, 2);
require $racine . '/vendor/autoload.php';

use Ged\Base;
use Ged\Config;

putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$id = (int) getenv('DOCUMENT_ID');
$titre = (string) getenv('TITRE');
if ($id < 1 || $titre === '') {
    fwrite(STDERR, "indexeur mobile : DOCUMENT_ID et TITRE requis\n");
    exit(1);
}

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "indexeur mobile : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    $pdo = Base::pdo($config);
    $chemin = (string) $pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
    if ($chemin === '') {
        throw new RuntimeException("document $id introuvable dans l'index");
    }
    $fichier = $config->chemin('STORAGE_ROOT', 'var/oracles/mobile/documents') . '/' . $chemin;
    $metaFichier = $fichier . '.meta.json';
    $meta = is_file($metaFichier) ? json_decode((string) file_get_contents($metaFichier), true) : [];
    if (!is_array($meta)) {
        $meta = [];
    }
    $contenu = (string) ($meta['texte'] ?? $titre);

    $pdo->prepare('INSERT INTO recherche_textes (cle, chemin, document_id, titre, type, emetteur, date_document, montant, contenu, signature, absent_le)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        . ' ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), titre = VALUES(titre), type = VALUES(type),'
        . ' emetteur = VALUES(emetteur), date_document = VALUES(date_document), contenu = VALUES(contenu),'
        . ' signature = VALUES(signature), absent_le = NULL')
        ->execute([
            hash('sha256', $chemin), $chemin, $id, $titre,
            $meta['type'] ?? null, $meta['emetteur'] ?? null,
            isset($meta['date']) && is_string($meta['date']) ? $meta['date'] : null,
            isset($meta['montant']) && is_numeric($meta['montant']) ? (float) $meta['montant'] : null,
            $contenu,
            is_file($metaFichier) ? hash('sha256', filesize($metaFichier) . '|' . filemtime($metaFichier)) : hash('sha256', 'mobile'),
        ]);

    echo json_encode(['ok' => true, 'document' => $id], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'indexeur mobile : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}
