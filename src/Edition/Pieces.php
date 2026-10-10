<?php

declare(strict_types=1);

namespace Ged\Edition;

/**
 * Ce que l'interface montre pour le geste d'ouverture : le libellé du bouton et le lien `kdocs://`.
 * Le lien ne porte qu'une action (`ouvrir`) et un identifiant NUMÉRIQUE : tout le reste est refusé par
 * l'agent (cahier des charges `docs/secteurs/edition-poste.md`).
 */
final class Pieces
{
    /** Quel programme l'utilisateur s'attend à voir s'ouvrir, d'après l'extension du document rangé. */
    public static function libelleOuverture(string $nom): string
    {
        $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

        return match ($extension) {
            'doc', 'docx', 'odt', 'rtf', 'txt' => 'Ouvrir dans Word',
            'xls', 'xlsx', 'ods', 'csv' => 'Ouvrir dans Excel',
            'ppt', 'pptx', 'odp' => 'Ouvrir dans PowerPoint',
            default => 'Ouvrir sur le poste',
        };
    }

    /** Le lien `kdocs://` : action + identifiant numérique seulement. */
    public static function lien(int $document): string
    {
        return 'kdocs://ouvrir/' . $document;
    }
}
