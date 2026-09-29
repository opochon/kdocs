<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Horloge du secteur Archivage. Injectée partout : les tests la figent, la production prend l'heure système.
 * Aucune échéance ni horodatage ne lit `time()` directement (rétention juste sur horloge figée).
 */
interface Horloge
{
    public function maintenant(): \DateTimeImmutable;
}
