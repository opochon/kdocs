<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/** Un fichier reçu par une source, déjà mis à l'abri dans la zone de travail. */
final class Entree
{
    public readonly string $empreinte;

    public function __construct(
        public readonly string $chemin,
        public readonly string $source,
        public readonly string $nomOrigine,
    ) {
        $h = hash_file('sha256', $chemin);
        if ($h === false) {
            throw new \RuntimeException("Fichier illisible : $chemin");
        }
        $this->empreinte = $h;
    }
}
