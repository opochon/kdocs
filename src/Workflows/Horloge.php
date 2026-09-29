<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Horloge du moteur. Injectée partout : les tests la figent, la production utilise l'heure système.
 * Aucune règle ni transition ne lit `time()` directement.
 */
interface Horloge
{
    public function maintenant(): \DateTimeImmutable;
}
