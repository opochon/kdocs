<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Ce que l'IA propose, ramené à la nomenclature connue. Une proposition n'est jamais une validation :
 * le classeur la corrobore (indice du texte) ou la laisse à contrôler.
 */
final class ReponseIa
{
    /**
     * @param list<string> $etiquettes
     */
    public function __construct(
        public readonly ?string $type,
        public readonly ?string $emetteur,
        public readonly ?string $titre,
        public readonly ?string $date,
        public readonly ?float $montant,
        public readonly array $etiquettes,
        public readonly float $confiance,
        public readonly string $modele,
    ) {
    }
}
