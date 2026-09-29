<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Une ligne de résultat prête pour l'écran : identité, métadonnées, pertinence et extraits déjà découpés
 * en segments surlignables (jamais de HTML).
 */
final readonly class Trouvaille
{
    /**
     * @param list<array{t:string, hit:bool}> $titreSegments
     * @param list<list<array{t:string, hit:bool}>> $extraits
     */
    public function __construct(
        public int $id,
        public string $chemin,
        public string $titre,
        public ?string $type,
        public ?string $emetteur,
        public ?string $date,
        public ?float $montant,
        public float $pertinence,
        public array $titreSegments,
        public array $extraits,
    ) {
    }
}
