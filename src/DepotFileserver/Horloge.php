<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/** Horloge injectable : les rejeux figent l'instant, la production lit l'horloge système. */
interface Horloge
{
    /** Instant courant, au format ISO 8601 (ex. `2026-10-13T10:00:00+02:00`). */
    public function maintenant(): string;
}
