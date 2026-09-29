<?php

declare(strict_types=1);

namespace Ged\Archivage;

use PDO;

/**
 * Le secteur Archivage n'a AUCUNE table : le scellement, son jeton TSA et la table des durées vivent dans
 * le dossier (`<fichier>.scellement/…`, `<fonds>/.archivage/…`). Le dossier fait foi ; une base reconstruite
 * n'a rien à relire ici. Cette classe existe pour satisfaire le contrat d'installation des secteurs.
 */
final class Schema
{
    public static function installer(PDO $pdo): void
    {
        // Volontairement vide : rien à poser en base. (Signature imposée par le contrat des schémas.)
        unset($pdo);
    }
}
