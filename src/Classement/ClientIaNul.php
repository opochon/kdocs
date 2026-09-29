<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Client IA inerte : aucune requête, aucune proposition. Sert au banc sans réseau et aux tests qui
 * mesurent le socle déterministe (règles + habitudes).
 */
final class ClientIaNul implements ClientIa
{
    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
    {
        return null;
    }

    public function aExpire(): bool
    {
        return false;
    }

    public function disponible(): bool
    {
        return false;
    }

    public function modele(): string
    {
        return 'aucune';
    }
}
