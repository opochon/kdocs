<?php

declare(strict_types=1);

/**
 * Correcteur cible du lot ged-parite-ecrans-1 : marque les elements `ecran` couverts par un ecran GEDv2
 * PROUVE par un parcours Playwright.
 *
 * Source unique : tests/parite/vecteurs/ecrans.json (committe avant l'implementation). Un element n'est
 * ecrit `couvert-autrement` que si le gabarit cite existe ET si le vecteur porte une preuve E2E ; aucun
 * cas particulier cable, aucun element force.
 *
 * Usage : php tools/parite/corriger-correspondance-ecrans.php
 */

$racine = dirname(__DIR__, 2);
$chemin = $racine . '/docs/parite/correspondance.json';
$donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
$elements = $donnees['elements'];

$vecteurs = json_decode((string) file_get_contents($racine . '/tests/parite/vecteurs/ecrans.json'), true, 512, JSON_THROW_ON_ERROR);

$modifies = 0;
foreach ((array) ($vecteurs['ecrans'] ?? []) as $id => $vecteur) {
    $id = (string) $id;
    if (!isset($elements[$id])) {
        fwrite(STDERR, "correspondance : id d'ecran inconnu $id\n");
        exit(1);
    }
    $fichier = (string) ($vecteur['gedv2']['fichier'] ?? '');
    $preuve = (string) ($vecteur['preuve_e2e'] ?? '');
    if ($fichier === '' || !is_file($racine . '/' . $fichier)) {
        fwrite(STDERR, "correspondance : gabarit introuvable pour $id ($fichier)\n");
        exit(1);
    }
    if ($preuve === '') {
        fwrite(STDERR, "correspondance : preuve E2E absente pour $id\n");
        exit(1);
    }

    $elements[$id] = [
        'statut' => 'couvert-autrement',
        'gedv2' => ['type' => 'ecran', 'fichier' => $fichier],
        'preuve' => ['type' => 'playwright', 'cible' => $preuve],
        'note' => (string) ($vecteur['note'] ?? ''),
    ];
    $modifies++;
}

$donnees['elements'] = $elements;
file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "correspondance ecrans : $modifies elements couverts, " . count($elements) . " au total\n";
