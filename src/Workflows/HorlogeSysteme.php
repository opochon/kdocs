<?php

declare(strict_types=1);

namespace Ged\Workflows;

/** Horloge de production : l'instant courant. */
final class HorlogeSysteme implements Horloge
{
    public function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
