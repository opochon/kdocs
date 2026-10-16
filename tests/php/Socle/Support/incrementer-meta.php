<?php

declare(strict_types=1);

// Processus concurrent de MetaAtomiqueTest : incrémente N fois le compteur d'un .meta.json par
// Fichiers::modifierMeta et ajoute sa trace sous sa clé. Code de sortie 0 = toutes les écritures acceptées.

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $document, $fois, $nom] = $argv;
for ($i = 0; $i < (int) $fois; $i++) {
    \Ged\Socle\Fichiers::modifierMeta($document, static function (array $meta) use ($nom, $i): array {
        $meta['compteur'] = (int) ($meta['compteur'] ?? 0) + 1;
        $meta[$nom][] = $i;

        return $meta;
    });
}
