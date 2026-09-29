<?php

declare(strict_types=1);

namespace Ged\Classement;

/**
 * Client IA injectable : le classeur ne connaît que ce contrat, jamais le réseau. Les tests et le banc
 * fournissent une implémentation inerte ou simulée ; la production branche InfomaniakClient.
 */
interface ClientIa
{
    /**
     * @param list<string> $typesConnus nomenclature proposée au modèle
     *
     * @return ReponseIa|null null = IA indisponible, en panne ou hors délai (repli sans blocage)
     */
    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa;

    /** Vrai si le dernier appel a expiré (on n'insiste pas au-delà d'une reprise). */
    public function aExpire(): bool;

    public function disponible(): bool;

    public function modele(): string;
}
