<?php

declare(strict_types=1);

namespace Ged\Archivage;

/** Horloge de production : l'heure système, sans dépendance injectée. */
final class HorlogeSysteme implements Horloge
{
    public function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
