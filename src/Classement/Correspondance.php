<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Correspondance automatique d'un texte de pièce avec le motif d'une règle de classement,
 * dans l'esprit de Paperless-ngx 2.20 (`documents.matching.matches`).
 *
 * Squelette de l'oracle rouge du lot ged-parite-comportement-1 : les modes sont déclarés, aucune
 * implémentation n'est encore posée. Chaque famille de vecteurs sera branchée dans un commit dédié.
 */
final class Correspondance
{
    public const AUCUN = 'MATCH_NONE';
    public const TOUS = 'MATCH_ALL';
    public const UN = 'MATCH_ANY';
    public const LITTERAL = 'MATCH_LITERAL';
    public const REGEX = 'MATCH_REGEX';
    public const FLOU = 'MATCH_FUZZY';
    public const AUTO = 'MATCH_AUTO';

    /** Seuil du mode flou, aligné sur rapidfuzz `score_cutoff=90` utilisé par Paperless. */
    public const SEUIL_FLOU = 90.0;

    /** Un motif vide ne correspond jamais ; les modes ne sont pas encore branchés. */
    public static function correspond(
        string $algorithme,
        string $motif,
        string $texte,
        bool $insensible = false,
    ): bool {
        if (trim($motif) === '') {
            return false;
        }

        return false;
    }
}
