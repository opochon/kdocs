<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Une demande de recherche validée : la saisie et les filtres appliqués AU MÊME périmètre. Toute valeur
 * douteuse est neutralisée ici (une date non ISO n'est pas un filtre, un montant non numérique non plus).
 */
final readonly class Requete
{
    public const LIMITE_MAX = 200;

    public function __construct(
        public string $texte = '',
        public ?string $type = null,
        public ?string $emetteur = null,
        public ?string $dateDebut = null,
        public ?string $dateFin = null,
        public ?float $montantMin = null,
        public ?float $montantMax = null,
        public int $limite = 50,
    ) {
    }

    /** @param array<string, mixed> $parametres (query string de /recherche) */
    public static function depuisParametres(array $parametres): self
    {
        $limite = (int) ($parametres['limite'] ?? 50);

        return new self(
            trim((string) ($parametres['q'] ?? '')),
            self::chaine($parametres['type'] ?? null),
            self::chaine($parametres['emetteur'] ?? null),
            self::date($parametres['date_debut'] ?? null),
            self::date($parametres['date_fin'] ?? null),
            self::montant($parametres['montant_min'] ?? null),
            self::montant($parametres['montant_max'] ?? null),
            max(1, min(self::LIMITE_MAX, $limite)),
        );
    }

    private static function chaine(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    private static function date(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return preg_match('#^\d{4}-\d{2}-\d{2}$#', $valeur) === 1 ? $valeur : null;
    }

    private static function montant(mixed $valeur): ?float
    {
        $valeur = str_replace([' ', "'", ','], ['', '', '.'], trim((string) $valeur));

        return is_numeric($valeur) ? (float) $valeur : null;
    }
}
