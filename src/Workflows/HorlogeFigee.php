<?php

declare(strict_types=1);

namespace Ged\Workflows;

/** Horloge figée des tests : l'instant ne bouge que si le test l'avance. */
final class HorlogeFigee implements Horloge
{
    private \DateTimeImmutable $instant;

    public function __construct(string|\DateTimeImmutable $instant)
    {
        $this->instant = $instant instanceof \DateTimeImmutable ? $instant : new \DateTimeImmutable($instant);
    }

    public static function depuis(string $instant): self
    {
        return new self($instant);
    }

    public function maintenant(): \DateTimeImmutable
    {
        return $this->instant;
    }

    /** Avance l'instant d'un modificateur relatif (« +90 days »). */
    public function avancer(string $modificateur): void
    {
        $this->instant = $this->instant->modify($modificateur) ?: $this->instant;
    }

    /** Fixe l'instant à une date absolue. */
    public function regler(string $instant): void
    {
        $this->instant = new \DateTimeImmutable($instant);
    }
}
