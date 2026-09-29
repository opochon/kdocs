<?php

declare(strict_types=1);

namespace Ged\Recherche;

/** Résultat d'une recherche : lignes, total du périmètre, mode réellement employé, durée, erreur éventuelle. */
final readonly class Resultat
{
    /** @param list<Trouvaille> $trouvailles */
    public function __construct(
        public array $trouvailles,
        public int $total,
        public string $mode,
        public float $dureeMs,
        public ?ErreurSql $erreur = null,
    ) {
    }

    public static function enErreur(ErreurSql $erreur, float $dureeMs): self
    {
        return new self([], 0, 'erreur', $dureeMs, $erreur);
    }
}
