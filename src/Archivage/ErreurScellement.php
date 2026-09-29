<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Erreur nommée du secteur Archivage : elle décrit l'étape et la cause, jamais un 500 muet.
 * Code 403 : un document scellé refuse toute écriture (WORM) tant que sa rétention court.
 */
final class ErreurScellement extends \RuntimeException
{
    public static function mutation(string $operation, string $cheminRelatif, string $echeance): self
    {
        return new self(sprintf('archivage : %s refusée — le document « %s » est scellé jusqu’au %s',
            $operation, $cheminRelatif, $echeance), 403);
    }

    public static function horodatage(string $cause): self
    {
        return new self('archivage : horodatage refusé — ' . $cause, 403);
    }

    public static function scellement(string $cause): self
    {
        return new self('archivage : scellement refusé — ' . $cause, 403);
    }
}
