<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-api-1.
 *
 * Le curateur global (`corriger-correspondance.php`) reconstruit tout le fichier et ne connaît pas les
 * 55 éléments `chaine` couverts par le lot ged-parite-chaine-1 : le rejouer les reclasserait « absent ».
 * Ce correcteur ADDITIF ne touche que les éléments `api` déclarés ici, laisse tout le reste intact.
 *
 * Chaque élément est marqué `couvert-autrement` seulement s'il cite une route GEDv2 réelle
 * (`GET /api/v1/documents`) ET une preuve PHPUnit rejouée au dernier passage. Le juge tranche.
 *
 * Usage : php tools/parite/corriger-correspondance-api.php
 */

$racine = dirname(__DIR__, 2);
$chemin = $racine . '/docs/parite/correspondance.json';
$document = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($document) || !is_array($document['elements'] ?? null)) {
    fwrite(STDERR, "correspondance.json illisible ou sans « elements »\n");
    exit(1);
}

/** @var array<string, array<string, mixed>> $elements */
$elements = $document['elements'];

$route = static fn (string $methode, string $cheminRoute): array =>
    ['type' => 'route', 'methode' => $methode, 'chemin' => $cheminRoute];
$preuve = static fn (string $cible): array => ['type' => 'phpunit', 'cible' => $cible];
$vec = 'Ged\\Tests\\Parite\\ApiFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments';
$bornes = 'Ged\\Tests\\Parite\\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables';

/** @var array<string, array{0:string, 1?:string}> $declarations id => [preuve, note ?] */
$declarations = [];
foreach ([
    'DocumentFilterSet.id', 'DocumentFilterSet.checksum', 'DocumentFilterSet.mime_type',
    'DocumentFilterSet.is_in_inbox', 'DocumentFilterSet.tags__id__none',
] as $filtre) {
    $declarations['api:filtre:' . $filtre] = [$vec];
}
foreach ([
    'DocumentFilterSet.title', 'DocumentFilterSet.content', 'DocumentFilterSet.is_tagged',
    'DocumentFilterSet.created', 'DocumentFilterSet.created__date__gt', 'DocumentFilterSet.created__date__lt',
    'DocumentFilterSet.added', 'DocumentFilterSet.modified', 'DocumentFilterSet.correspondent',
    'DocumentFilterSet.correspondent__name', 'DocumentFilterSet.document_type',
    'DocumentFilterSet.document_type__name', 'DocumentFilterSet.tags__name',
] as $filtre) {
    $declarations['api:filtre:' . $filtre] = [$bornes];
}
$declarations['api:filtre:DocumentFilterSet.storage_path'] = [$bornes,
    'GEDv2 : pas d’entité chemin de stockage ; « sans chemin » = document à la racine (sans_dossier).'];

$inconnus = [];
foreach ($declarations as $id => $declaration) {
    $cible = $declaration[0];
    $note = $declaration[1] ?? '';
    if (!isset($elements[$id])) {
        $inconnus[] = $id;
        continue;
    }
    $elements[$id] = array_filter([
        'statut' => 'couvert-autrement',
        'gedv2' => $route('GET', '/api/v1/documents'),
        'preuve' => $preuve($cible),
        'note' => $note,
    ], static fn ($v): bool => $v !== '');
}

if ($inconnus !== []) {
    fwrite(STDERR, "ids absents de l'inventaire : " . implode(', ', $inconnus) . "\n");
    exit(1);
}

$document['elements'] = $elements;
file_put_contents($chemin, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("OK: %d elements api declares (le reste de la correspondance est intact)\n", count($declarations));
