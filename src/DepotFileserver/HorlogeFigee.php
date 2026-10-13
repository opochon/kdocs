<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

final class HorlogeFigee implements Horloge
{
    public function __construct(private readonly string $instant)
    {
    }

    public function maintenant(): string
    {
        return $this->instant;
    }
}
