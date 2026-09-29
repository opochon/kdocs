<?php

declare(strict_types=1);

namespace Ged\Ingestion\Imap;

/**
 * Boîte dont la connexion n'est ouverte qu'au premier usage : construire le pilote ne touche pas le réseau,
 * et un serveur injoignable ne devient une erreur qu'au relevé (où le pilote l'isole en alerte).
 */
final class ConnexionDifferee implements Boite
{
    private ?Boite $boite = null;

    /** @param \Closure(): Boite $ouvrir */
    public function __construct(private readonly \Closure $ouvrir, private readonly string $nom)
    {
    }

    public function nom(): string
    {
        return $this->nom;
    }

    public function nonLus(): array
    {
        return $this->boite()->nonLus();
    }

    public function lire(int $uid): string
    {
        return $this->boite()->lire($uid);
    }

    public function classer(int $uid, string $dossier): void
    {
        $this->boite()->classer($uid, $dossier);
    }

    public function fermer(): void
    {
        $this->boite?->fermer();
        $this->boite = null;
    }

    private function boite(): Boite
    {
        return $this->boite ??= ($this->ouvrir)();
    }
}
