<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Ce dont le moteur de courrier a besoin d'une boîte : lister un dossier, lire un message, appliquer une
 * action, tester la connexion. C'est une INTERFACE INJECTABLE : en test, un faux en mémoire (aucun réseau,
 * aucun vrai serveur) ; en production, un adaptateur IMAP.
 */
interface PortBoite
{
    /** Identifiant stable de la boîte (utilisateur@serveur). */
    public function nom(): string;

    /** @return list<string> Dossiers visibles de la boîte. */
    public function dossiers(): array;

    /**
     * Messages du dossier, du plus ancien au plus récent.
     *
     * @return list<Message>
     */
    public function messages(string $dossier): array;

    /**
     * Applique l'action de règle (entiers `Regle::ACTION_*`) au message $uid du dossier.
     * « Supprimer » range en corbeille, jamais d'effacement (règle GEDv2).
     */
    public function appliquer(string $dossier, int $uid, int $action, string $parametre): void;

    /** Ouvre réellement la boîte : lève une ErreurCourrier nommée si la connexion échoue. */
    public function tester(): void;

    public function fermer(): void;
}
