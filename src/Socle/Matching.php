<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Classement\Correspondance;
use RuntimeException;

/**
 * Modèle d'appariement (modèle Paperless `MatchingModel`) : un NOM, un motif (`match`), un ALGORITHME
 * (`matching_algorithm`) et la sensibilité à la casse (`is_insensitive`). Correspondant, type de document,
 * étiquette, chemin de rangement : Paperless hérite tous ces modèles de celui-ci.
 *
 * GEDv2 ne duplique pas les algorithmes : ils vivent dans `Ged\Classement\Correspondance` (réimplémentés à
 * la manière GEDv2, vecteurs Paperless rejoués). Cette classe assemble les quatre champs et délègue la
 * décision, pour qu'une règle de correspondance se relise telle qu'elle a été posée.
 */
final class Matching
{
    /** Algorithmes admis (ceux du modèle Paperless). */
    public const ALGORITHMES = [Correspondance::AUCUN, Correspondance::TOUS, Correspondance::UN,
        Correspondance::LITTERAL, Correspondance::REGEX, Correspondance::FLOU, Correspondance::AUTO];

    public function __construct(
        public readonly string $nom,
        public readonly string $motif,
        public readonly string $algorithme,
        public readonly bool $insensible = true,
    ) {
        if (trim($nom) === '') {
            throw new RuntimeException('appariement : le nom manque');
        }
        if (!in_array($algorithme, self::ALGORITHMES, true)) {
            throw new RuntimeException("appariement : algorithme inconnu « $algorithme » (" . implode(', ', self::ALGORITHMES) . ')');
        }
    }

    public function correspond(string $texte): bool
    {
        return Correspondance::correspond($this->algorithme, $this->motif, $texte, $this->insensible);
    }

    /**
     * @param array<string, mixed> $brut
     */
    public static function depuisTableau(array $brut): self
    {
        $insensible = $brut['is_insensitive'] ?? $brut['insensible'] ?? true;

        return new self(
            (string) ($brut['name'] ?? $brut['nom'] ?? ''),
            (string) ($brut['match'] ?? $brut['motif'] ?? ''),
            (string) ($brut['matching_algorithm'] ?? $brut['algorithme'] ?? Correspondance::AUCUN),
            (bool) $insensible,
        );
    }

    /** @return array{name:string, match:string, matching_algorithm:string, is_insensitive:bool} */
    public function versTableau(): array
    {
        return ['name' => $this->nom, 'match' => $this->motif,
            'matching_algorithm' => $this->algorithme, 'is_insensitive' => $this->insensible];
    }
}
