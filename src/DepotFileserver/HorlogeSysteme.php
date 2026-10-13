<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

final class HorlogeSysteme implements Horloge
{
    public function maintenant(): string
    {
        return (new \DateTimeImmutable())->format('c');
    }
}
