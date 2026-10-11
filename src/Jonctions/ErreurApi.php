<?php

declare(strict_types=1);

namespace Ged\Jonctions;

/**
 * Refus de l'API d'entrée : porte un statut HTTP, l'étape concernée et la cause, pour qu'une erreur se
 * nomme (jamais un 500 muet). Aucune donnée sensible n'y transite.
 */
final class ErreurApi extends \RuntimeException
{
    public function __construct(
        public readonly int $statut,
        public readonly string $cause,
        public readonly string $etape,
        ?\Throwable $precedente = null,
    ) {
        parent::__construct($cause, 0, $precedente);
    }
}
